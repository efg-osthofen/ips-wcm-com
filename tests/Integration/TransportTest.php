<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWcm;

/**
 * sendBuffer() against a simulated WCM-COM over real HTTP with digest auth.
 *
 * Note: every failed attempt sleeps 1-2 s in sendBuffer(), also before the
 * final exception, so the error tests use a single attempt where possible.
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

    private static function api(string $password = FakeWcm::PASSWORD, ?string $url = null): \Weishaupt
    {
        return new \Weishaupt(new \WeishauptOptions($url ?? FakeWcm::url(), FakeWcm::USERNAME, $password));
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
     * The answer is parsed from $res["header"], which is the first
     * CURLINFO_HEADER_SIZE bytes of the *body* (CURLOPT_HEADER is not set).
     * Because curl's digest handshake receives two header blocks, this works
     * as long as the answer is shorter than both header blocks together. A
     * longer answer (here: pretty-printed) is cut off and fails to parse.
     */
    #[Group('known-issue')]
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
}
