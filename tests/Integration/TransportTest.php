<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWcm;

/**
 * sendBuffer() against a simulated WCM-COM over real HTTP with digest auth.
 *
 * Note: a failed attempt that is followed by another attempt sleeps 1-2 s in
 * sendBuffer(), so the error tests use a single attempt where possible.
 */
final class TransportTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        FakeWcm::start();
    }

    protected function setUp(): void
    {
        FakeWcm::reset();
    }

    private static function api(string $password = FakeWcm::PASSWORD, ?string $url = null, float $connectTimeout = 3.0, float $requestTimeout = 8.0): \Weishaupt
    {
        return new \Weishaupt(new \WeishauptOptions($url ?? FakeWcm::url(), FakeWcm::USERNAME, $password, $connectTimeout, $requestTimeout));
    }

    /** Buffers $count read telegrams (VorlauftemperaturHK of Heizkreis 1..$count). */
    private static function apiWithTelegrams(int $count, float $requestTimeout = 8.0): \Weishaupt
    {
        $api = self::api(requestTimeout: $requestTimeout);
        for ($heizkreis = 1; $heizkreis <= $count; $heizkreis++) {
            $api->bufferedRequestVorlauftemperaturHK($heizkreis);
        }

        return $api;
    }

    /** @return list<float> */
    private static function values(\FinalTelegramObjectCollection $result): array
    {
        return array_map(fn ($t) => $t->DATA, iterator_to_array($result->getIterator()));
    }

    public function testSendsCocoTelegramsWithDigestAuthentication(): void
    {
        FakeWcm::reset(['device' => ['10/0/12' => -35]]);
        $api = self::api();
        $api->bufferedRequestAussentemperatur();

        $result = $api->sendBuffer();

        $this->assertSame(
            [['prot' => 'coco', 'telegramm' => [[10, 0, 1, 12, 0, 0, 0, 0]]]],
            FakeWcm::requests()
        );
        $this->assertSame([-3.5], self::values($result));
    }

    public function testSplitsBufferIntoRequestsOfAtMostNineTelegrams(): void
    {
        $device = [];
        $api = self::api();
        for ($heizkreis = 1; $heizkreis <= 20; $heizkreis++) {
            $device['12/' . ($heizkreis - 1) . '/15'] = 200 + $heizkreis;
            $api->bufferedRequestVorlauftemperaturHK($heizkreis);
        }
        FakeWcm::reset(['device' => $device]);

        $result = $api->sendBuffer();

        $this->assertSame([9, 9, 2], array_map(fn ($r) => count($r['telegramm']), FakeWcm::requests()));
        $this->assertEqualsWithDelta(
            array_map(fn ($hk) => (200 + $hk) / 10, range(1, 20)),
            self::values($result),
            1e-9,
            'Results of all chunks are merged in request order'
        );
    }

    public function testWriteTelegramChangesTheDevice(): void
    {
        $api = self::api();
        $api->bufferedUpdateNormalRaumtemperaturHK(2, 21.5);
        $api->bufferedUpdateRaumfrosttemperaturHK(2, -4.5);
        $api->bufferedUpdateBetriebsartHK(3, 4);

        $api->sendBuffer();

        $this->assertSame(215, FakeWcm::deviceValue('6/1/5'));
        $this->assertSame(-45, FakeWcm::deviceValue('6/1/2580'));
        $this->assertSame(4, FakeWcm::deviceValue('6/2/274'));
    }

    public function testWrittenValueCanBeReadBack(): void
    {
        $api = self::api();
        $api->bufferedUpdateSteilheitHK(2, 12.5);
        $api->sendBuffer();
        $api->clearBuffer();

        $api->bufferedRequestSteilheitHK(2);

        $this->assertSame([12.5], self::values($api->sendBuffer()));
    }

    public function testFullBufferOfThirtyTelegramsIsParsed(): void
    {
        $device = [];
        $api = self::api();
        for ($heizkreis = 1; $heizkreis <= 30; $heizkreis++) {
            $device['6/' . ($heizkreis - 1) . '/2580'] = -300 + $heizkreis;
            $api->bufferedRequestRaumfrosttemperaturHK($heizkreis);
        }
        FakeWcm::reset(['device' => $device, 'responses' => array_fill(0, 4, ['pretty' => true])]);

        $this->assertEqualsWithDelta(
            array_map(fn ($hk) => (-300 + $hk) / 10, range(1, 30)),
            self::values($api->sendBuffer(1)),
            1e-9
        );
    }

    public function testWrongPasswordFails(): void
    {
        $api = self::api('wrong');
        $api->bufferedRequestAussentemperatur();

        $this->expectExceptionMessage('HTTP return code 401');
        try {
            $api->sendBuffer(1);
        } finally {
            $this->assertSame([], FakeWcm::requests(), 'Request must not get past authentication');
        }
    }

    public function testHttpErrorFails(): void
    {
        FakeWcm::reset(['responses' => [['status' => 500, 'body' => 'internal error']]]);
        $api = self::api();
        $api->bufferedRequestAussentemperatur();

        $this->expectExceptionMessage('HTTP return code 500');
        $api->sendBuffer(1);
    }

    public function testUnreachableDeviceFails(): void
    {
        $api = self::api(url: 'http://127.0.0.1:1');
        $api->bufferedRequestAussentemperatur();

        $this->expectExceptionMessage('CURL error occurred');
        $api->sendBuffer(1);
    }

    public function testBusyDeviceIsRetried(): void
    {
        FakeWcm::reset([
            'device' => ['10/0/12' => 101],
            'responses' => [['body' => 'server is busy']],
        ]);
        $api = self::api();
        $api->bufferedRequestAussentemperatur();

        $result = $api->sendBuffer(2);

        $this->assertCount(2, FakeWcm::requests());
        $this->assertSame([10.1], self::values($result));
    }

    public function testBusyDeviceFailsAfterLastAttempt(): void
    {
        FakeWcm::reset(['responses' => [['body' => 'server is busy']]]);
        $api = self::api();
        $api->bufferedRequestAussentemperatur();

        $this->expectExceptionMessage('WCM-COM server is busy.');
        $api->sendBuffer(1);
    }

    public function testAnswerWithDifferentInfoNumberIsRejected(): void
    {
        FakeWcm::reset(['responses' => [['set' => [1 => [3 => 13]]]]]);
        $api = self::api();
        $api->bufferedRequestAussentemperatur();
        $api->bufferedRequestRuecklauftemperatur();

        $this->expectExceptionMessage("WCM response doesn't match the requests!");
        $api->sendBuffer(1);
    }

    /** Fewer telegrams would shift every later buffer position onto the wrong value. */
    public function testAnswerWithMissingTelegramsIsRejected(): void
    {
        FakeWcm::reset(['responses' => [['truncate' => 1]]]);
        $api = self::api();
        $api->bufferedRequestAussentemperatur();
        $api->bufferedRequestRuecklauftemperatur();

        $this->expectExceptionMessage('WCM response contains 1 telegrams, expected 2.');
        $api->sendBuffer(1);
    }

    public function testAnswerWithAdditionalTelegramsIsRejected(): void
    {
        FakeWcm::reset(['responses' => [['appendTelegram' => [10, 0, 1, 14, 0, 0, 0, 0]]]]);
        $api = self::api();
        $api->bufferedRequestAussentemperatur();

        $this->expectExceptionMessage('WCM response contains 2 telegrams, expected 1.');
        $api->sendBuffer(1);
    }

    public function testIncompleteAnswerIsRetried(): void
    {
        FakeWcm::reset([
            'device' => ['10/0/12' => -35, '10/0/22' => 312],
            'responses' => [['truncate' => 1]],
        ]);
        $api = self::api();
        $api->bufferedRequestAussentemperatur();
        $api->bufferedRequestRuecklauftemperatur();

        $this->assertSame([-3.5, 31.2], self::values($api->sendBuffer(2)));
        $this->assertCount(2, FakeWcm::requests());
    }

    /** An error telegram is a valid answer: no retry, the telegram is marked. */
    public function testErrorTelegramIsPassedThroughWithoutRetry(): void
    {
        FakeWcm::reset([
            'device' => ['10/0/12' => -35],
            'responses' => [['set' => [1 => [2 => 255]]]],
        ]);
        $api = self::api();
        $api->bufferedRequestAussentemperatur();
        $api->bufferedRequestB10PufferOben();

        $result = iterator_to_array($api->sendBuffer(3)->getIterator());

        $this->assertCount(1, FakeWcm::requests());
        $this->assertSame([false, true], [$result[0]->ERROR, $result[1]->ERROR]);
        $this->assertSame([-3.5, null], [$result[0]->DATA, $result[1]->DATA]);
    }

    /**
     * Regression: the answer used to be cut off after CURLINFO_HEADER_SIZE
     * bytes. That only worked while the answer was shorter than the headers
     * of curl's digest handshake. A pretty-printed answer is much longer.
     */
    public function testLongAnswerIsParsedCompletely(): void
    {
        $device = [];
        $api = self::api();
        for ($heizkreis = 1; $heizkreis <= 9; $heizkreis++) {
            $device['6/' . ($heizkreis - 1) . '/2580'] = -100 + $heizkreis;
            $api->bufferedRequestRaumfrosttemperaturHK($heizkreis);
        }
        FakeWcm::reset(['device' => $device, 'responses' => [['pretty' => true]]]);

        $this->assertCount(9, iterator_to_array($api->sendBuffer(1)->getIterator()));
    }

    // --- Load: one curl handle, digest nonce reused --------------------------

    /** Before: every chunk had its own handle, i.e. its own 401 round trip and nonce. */
    public function testAllChunksShareOneDigestChallengeAndNonce(): void
    {
        $api = self::apiWithTelegrams(20);

        $api->sendBuffer();

        $this->assertCount(3, FakeWcm::requests());
        $this->assertSame(1, FakeWcm::challenges(), 'Only the first request needs the 401 round trip');
        $nonces = FakeWcm::usedNonces();
        $this->assertCount(3, $nonces);
        $this->assertCount(1, array_unique($nonces), 'All chunks use the same nonce');
        $this->assertSame(['00000001', '00000002', '00000003'], array_column(FakeWcm::authLog(), 'nc'), 'Nonce count increases');
    }

    public function testHandleIsReusedForFurtherSendBufferCalls(): void
    {
        $api = self::api();
        $api->bufferedUpdateSteilheitHK(2, 12.5);
        $api->sendBuffer();
        $api->clearBuffer();
        $api->bufferedRequestSteilheitHK(2);

        $this->assertSame([12.5], self::values($api->sendBuffer()));
        $this->assertSame(1, FakeWcm::challenges());
    }

    /** If the WCM-COM lets a nonce expire, curl fetches a new one by itself. */
    public function testExpiredNonceIsRenewedTransparently(): void
    {
        FakeWcm::reset(['nonceUses' => 2]);
        $api = self::apiWithTelegrams(30);

        $this->assertCount(30, iterator_to_array($api->sendBuffer(1)->getIterator()));
        $this->assertCount(4, FakeWcm::requests());
        $this->assertSame(2, FakeWcm::challenges(), 'Initial challenge + one stale nonce');
        $this->assertSame(6, $api->getLastStats()['http_requests']);
    }

    public function testStatisticsOfLastSendBuffer(): void
    {
        $api = self::apiWithTelegrams(20);

        $api->sendBuffer();
        $stats = $api->getLastStats();

        $this->assertSame(20, $stats['telegrams']);
        $this->assertSame(3, $stats['blocks']);
        $this->assertSame(3, $stats['sent_blocks']);
        $this->assertSame(4, $stats['http_requests'], '3 POSTs + 1 challenge');
        $this->assertGreaterThanOrEqual(1, $stats['connections']);
        $this->assertGreaterThan(0.0, $stats['duration']);
    }

    public function testStatisticsCountRetriedRequests(): void
    {
        FakeWcm::reset(['responses' => [['body' => 'server is busy']]]);
        $api = self::apiWithTelegrams(1);

        $api->sendBuffer(2);

        $this->assertSame(['telegrams' => 1, 'blocks' => 1, 'sent_blocks' => 1, 'http_requests' => 3], array_intersect_key($api->getLastStats(), array_flip(['telegrams', 'blocks', 'sent_blocks', 'http_requests'])));
    }

    // --- Timeouts and time budget -----------------------------------------

    public function testRequestTimeoutAbortsSlowAnswerWithoutRetry(): void
    {
        FakeWcm::reset(['delay' => 1.0]);
        $api = self::apiWithTelegrams(1, requestTimeout: 0.3);

        $start = microtime(true);
        try {
            $api->sendBuffer(3);
            $this->fail('Expected a timeout');
        } catch (\WeishauptTimeoutException $e) {
            $this->assertStringContainsString('CURL error occurred', $e->getMessage());
        }

        $this->assertLessThan(0.9, microtime(true) - $start, 'Neither waits for the answer nor retries');
        $this->assertCount(1, FakeWcm::requests());
        usleep(800000); // let the fake server finish the delayed answer
    }

    /** The time budget limits all chunks together, not each request. */
    public function testTimeBudgetLimitsTheWholeBuffer(): void
    {
        FakeWcm::reset(['delay' => 0.4]);
        $api = self::apiWithTelegrams(30);

        $start = microtime(true);
        try {
            $api->sendBuffer(1, 1.0);
            $this->fail('Expected a timeout');
        } catch (\WeishauptTimeoutException) {
        }
        $duration = microtime(true) - $start;

        $this->assertGreaterThanOrEqual(0.95, $duration);
        $this->assertLessThan(1.4, $duration);
        $this->assertSame(2, $api->getLastStats()['sent_blocks']);
        $this->assertCount(3, FakeWcm::requests(), 'Chunk 4 is not sent any more');
        usleep(400000);
    }

    public function testExhaustedTimeBudgetSendsNothingMore(): void
    {
        $api = self::apiWithTelegrams(1);

        $this->expectException(\WeishauptTimeoutException::class);
        $this->expectExceptionMessage('Time budget of 0.0 s for sending the buffer exhausted.');
        try {
            $api->sendBuffer(1, 0.0);
        } finally {
            $this->assertSame([], FakeWcm::requests());
        }
    }

    public function testUnreachableDeviceIsNotRetried(): void
    {
        $api = self::api(url: 'http://127.0.0.1:1');
        $api->bufferedRequestAussentemperatur();

        $start = microtime(true);
        try {
            $api->sendBuffer(10);
            $this->fail('Expected a connection error');
        } catch (\WeishauptConnectionException $e) {
            $this->assertStringContainsString('CURL error occurred', $e->getMessage());
        }
        $this->assertLessThan(0.5, microtime(true) - $start, 'No pauses for retries');
    }

    public function testNoPauseAfterTheLastAttempt(): void
    {
        FakeWcm::reset(['responses' => [['status' => 500, 'body' => 'error']]]);
        $api = self::apiWithTelegrams(1);

        $start = microtime(true);
        try {
            $api->sendBuffer(1);
            $this->fail('Expected an HTTP error');
        } catch (\Exception $e) {
            $this->assertNotInstanceOf(\WeishauptConnectionException::class, $e);
        }
        $this->assertLessThan(0.5, microtime(true) - $start);
    }

    public function testBusyAnswerHasItsOwnExceptionType(): void
    {
        FakeWcm::reset(['responses' => [['body' => 'server is busy']]]);
        $api = self::apiWithTelegrams(1);

        $this->expectException(\WeishauptBusyException::class);
        $api->sendBuffer(1);
    }
}
