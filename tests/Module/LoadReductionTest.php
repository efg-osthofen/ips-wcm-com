<?php

declare(strict_types=1);

namespace Tests\Module;

use PHPUnit\Framework\TestCase;
use Tests\Support\ClockedWCMConnector;
use Tests\Support\FakeWcm;

// Device values and expected variable values are shared with WCMConnectorTest
require_once __DIR__ . '/WCMConnectorTest.php';

/**
 * Less load on the WCM-COM per cycle: shared curl handle, slow parameters
 * only every SlowParameterInterval minutes, pause after errors instead of
 * retries. Heizkreis 2 and 3, values from WCMConnectorTest::DEVICE.
 */
final class LoadReductionTest extends TestCase
{
    private const INSTANCE = 12345;

    /**
     * INFONR of Betriebsart HK, NormalRaumtemperatur, Raumfrosttemperatur,
     * So/Wi, Steilheit, Gedämpfte Außentemperatur, MaxLeistung Heizung/WW
     */
    private const SLOW_INFONR = [274, 5, 2580, 278, 270, 2572, 319, 345];

    private ClockedWCMConnector $module;

    public static function setUpBeforeClass(): void
    {
        FakeWcm::start();
    }

    protected function setUp(): void
    {
        \IpsFake::reset();
        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);
        $this->module = self::createModule();
    }

    private static function createModule(array $properties = []): ClockedWCMConnector
    {
        $module = new ClockedWCMConnector(self::INSTANCE);
        $module->Create();
        \IpsFake::configure(self::INSTANCE, $properties + [
            'URL' => FakeWcm::url(),
            'Username' => FakeWcm::USERNAME,
            'Password' => FakeWcm::PASSWORD,
            'UpdateInterval' => 15,
            'FirstHK' => 2,
            'LastHK' => 3,
            'BlockPause' => 0,
        ]);
        $module->ApplyChanges();

        return $module;
    }

    /** Switches the updates on (full read) and clears the request log. */
    private function switchOn(array $device = WCMConnectorTest::DEVICE): void
    {
        $this->module->RequestAction('ParameterUpdate', true);
        FakeWcm::reset(['device' => $device]);
    }

    /** Timer run $seconds after the previous one. */
    private function cycleAfter(int $seconds): void
    {
        $this->module->now += $seconds;
        $this->module->RetrieveWCMStatus(true);
    }

    private static function value(string $ident): mixed
    {
        return \IpsFake::variable(self::INSTANCE, $ident)['value'];
    }

    /** @return list<string> */
    private static function disabledIdents(): array
    {
        return array_values(array_filter(
            \IpsFake::idents(self::INSTANCE),
            fn ($ident) => \IpsFake::variable(self::INSTANCE, $ident)['disabled']
        ));
    }

    /** @return list<int> */
    private static function sentInfoNumbers(): array
    {
        return array_column(FakeWcm::sentTelegrams(), 3);
    }

    /** @return list<int> */
    private static function chunkSizes(): array
    {
        return array_map(fn ($request) => count($request['telegramm']), FakeWcm::requests());
    }

    private static function lastDebug(): string
    {
        return end(\IpsFake::$debug)['data'];
    }

    private function assertAllValid(): void
    {
        $this->assertSame([], self::disabledIdents());
        foreach (WCMConnectorTest::EXPECTED as $ident => $value) {
            $this->assertEqualsWithDelta($value, self::value($ident), 1e-9, $ident);
        }
    }

    private function assertAllInvalid(): void
    {
        $this->assertEqualsCanonicalizing(array_keys(WCMConnectorTest::EXPECTED), self::disabledIdents());
        foreach (array_keys(WCMConnectorTest::EXPECTED) as $ident) {
            $this->assertEqualsWithDelta(0, self::value($ident), 1e-9, $ident);
        }
    }

    // --- Requests per cycle ------------------------------------------------

    /** Before: 6 chunks with their own 401 round trip each, 12 HTTP requests. */
    public function testFullCycleForHeizkreis2To5Needs7HttpRequests(): void
    {
        \IpsFake::reset();
        $this->module = self::createModule(['FirstHK' => 2, 'LastHK' => 5]);

        $this->module->RequestAction('ParameterUpdate', true);

        $this->assertSame([9, 9, 9, 9, 9, 1], self::chunkSizes());
        $this->assertSame(1, FakeWcm::challenges());
        $this->assertMatchesRegularExpression(
            '#^Zyklus OK: 46 Telegramme, 6/6 Blöcke \((\d+,\d\d \+ ){5}\d+,\d\d s\), 7 HTTP-Anfragen, \d+ Verbindung\(en\), \d+,\d\d s, ERROR 0, N/V 0, seltene Parameter: alle$#',
            self::lastDebug()
        );
    }

    public function testNormalCycleForHeizkreis2To5Needs4HttpRequests(): void
    {
        \IpsFake::reset();
        $this->module = self::createModule(['FirstHK' => 2, 'LastHK' => 5]);
        $this->switchOn([]);

        $this->cycleAfter(15);

        // 1 Fehlercode + 4 x 3 Heizkreis + 10 Kessel/Puffer
        $this->assertSame([9, 9, 5], self::chunkSizes());
        $this->assertMatchesRegularExpression('#^Zyklus OK: 23 Telegramme, 3/3 Blöcke \(.*\), 4 HTTP-Anfragen, #', self::lastDebug());
    }

    // --- Slow parameters ---------------------------------------------------

    public function testNormalCycleSkipsSlowParameters(): void
    {
        $this->switchOn();

        $this->cycleAfter(15);

        $this->assertSame([9, 8], self::chunkSizes());
        $this->assertSame([], array_values(array_intersect(self::sentInfoNumbers(), self::SLOW_INFONR)));
        $this->assertSame([0, 0, 1, 1, 0, 0, 0, 0], FakeWcm::sentTelegrams()[0], 'Fehlercode stays first');
        $this->assertStringEndsWith('seltene Parameter: keine', self::lastDebug());
    }

    public function testSlowParametersKeepValueAndStayEnabledBetweenReads(): void
    {
        $this->switchOn(['6/1/270' => 150, '10/0/319' => 900] + WCMConnectorTest::DEVICE);

        $this->cycleAfter(15);

        $this->assertAllValid();
        \IpsFake::$setDisabledCalls = 0;
        $this->cycleAfter(15);
        $this->assertSame(0, \IpsFake::$setDisabledCalls);
    }

    public function testSlowParametersAreReadAgainAfterFifteenMinutes(): void
    {
        $this->switchOn(['6/1/270' => 150, '10/0/319' => 900] + WCMConnectorTest::DEVICE);

        $this->cycleAfter(15 * 60 - 1);
        $this->assertEqualsWithDelta(12.5, self::value('SteilheitHK2'), 1e-9, 'Not due yet');

        $this->cycleAfter(1);
        $this->assertEqualsWithDelta(15.0, self::value('SteilheitHK2'), 1e-9);
        $this->assertSame(90, self::value('KesselMaxLeistungHeizung'));
        $this->assertStringEndsWith('seltene Parameter: alle', self::lastDebug());

        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);
        $this->cycleAfter(15);
        $this->assertSame(17, count(FakeWcm::sentTelegrams()), 'Next 15 minutes start now');
    }

    public function testSlowParameterIntervalIsConfigurable(): void
    {
        \IpsFake::reset();
        $this->module = self::createModule(['SlowParameterInterval' => 5]);
        $this->switchOn();

        $this->cycleAfter(4 * 60 + 59);
        $this->assertCount(17, FakeWcm::sentTelegrams());

        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);
        $this->cycleAfter(1);
        $this->assertCount(30, FakeWcm::sentTelegrams());
    }

    public function testSlowParameterIntervalZeroReadsEverythingEveryCycle(): void
    {
        \IpsFake::reset();
        $this->module = self::createModule(['SlowParameterInterval' => 0]);
        $this->switchOn();

        $this->cycleAfter(15);

        $this->assertSame([9, 9, 9, 3], self::chunkSizes());
    }

    public function testSwitchingUpdatesOffAndOnReadsAllParameters(): void
    {
        $this->switchOn();
        $this->module->RequestAction('ParameterUpdate', false);
        $this->assertAllInvalid();

        $this->module->RequestAction('ParameterUpdate', true);

        $this->assertCount(30, FakeWcm::sentTelegrams());
        $this->assertAllValid();
    }

    // --- Read back after writing ---------------------------------------------

    public function testWrittenSlowParameterIsReadBackOnceInNextCycle(): void
    {
        $this->switchOn();
        $this->module->RequestAction('SteilheitHK2', 14.0);
        $this->assertEqualsWithDelta(14.0, self::value('SteilheitHK2'), 1e-9, 'Shown at once');
        // The device stores a slightly different value (e.g. rounded)
        FakeWcm::reset(['device' => ['6/1/270' => 141] + WCMConnectorTest::DEVICE]);

        $this->cycleAfter(15);

        $this->assertCount(18, FakeWcm::sentTelegrams());
        $this->assertContains([6, 1, 1, 270, 0, 0, 0, 0], FakeWcm::sentTelegrams(), 'Read telegram for SteilheitHK2');
        $this->assertSame([270], array_values(array_intersect(self::sentInfoNumbers(), self::SLOW_INFONR)), 'Only the written parameter');
        $this->assertEqualsWithDelta(14.1, self::value('SteilheitHK2'), 1e-9, 'Value of the device');
        $this->assertStringEndsWith('seltene Parameter: 1 nach Schreiben', self::lastDebug());

        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);
        $this->cycleAfter(15);
        $this->assertCount(17, FakeWcm::sentTelegrams(), 'Read back only once');
    }

    public function testPublicMaxLeistungFunctionIsReadBackInNextCycle(): void
    {
        $this->switchOn();
        $this->module->UpdateWCMMaxLeistungWW(70);
        $this->assertSame(80, self::value('KesselMaxLeistungWW'), 'The function does not set the variable');
        FakeWcm::reset(['device' => ['10/0/345' => 700] + WCMConnectorTest::DEVICE]);

        $this->cycleAfter(15);

        $this->assertSame([345], array_values(array_intersect(self::sentInfoNumbers(), self::SLOW_INFONR)));
        $this->assertSame(70, self::value('KesselMaxLeistungWW'));
    }

    public function testRejectedWriteIsNotReadBack(): void
    {
        $this->switchOn();
        FakeWcm::reset(['responses' => [['command' => 255]]]);
        try {
            $this->module->RequestAction('SteilheitHK2', 14.0);
        } catch (\Exception) {
        }
        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);

        $this->cycleAfter(15);

        $this->assertCount(17, FakeWcm::sentTelegrams());
    }

    // --- Pause after errors --------------------------------------------------

    public function testFailedCycleIsNotRetriedDisablesAllValuesAndPauses(): void
    {
        $this->switchOn();
        FakeWcm::reset(['responses' => [['status' => 500, 'body' => 'error']]]);

        $this->cycleAfter(15);

        $this->assertCount(1, FakeWcm::requests(), 'No retry, no further chunk');
        // Slow parameters were not requested in this cycle, they become invalid as well
        $this->assertAllInvalid();
        $this->assertStringStartsWith('Zyklus fehlgeschlagen (HTTP-Fehler 500 in Block 1/2): HTTP return code 500', self::lastDebug());
        $this->assertStringContainsString('| 17 Telegramme, 0/2 Blöcke (', self::lastDebug());
        $this->assertStringContainsString(' s), 2 HTTP-Anfragen, ', self::lastDebug());
        $this->assertStringEndsWith('| Pause 60 s (Fehler Nr. 1)', self::lastDebug());
        $this->assertCount(1, \IpsFake::$log);
    }

    /**
     * An external script treats disabled or not updated (> 5 min) variables,
     * especially "Kessel Außentemperatur", as an outage. They have to stay
     * disabled during the pause.
     */
    public function testValuesStayDisabledDuringPauseAndRecoverWithFullRead(): void
    {
        $this->switchOn();
        FakeWcm::reset(['responses' => [['status' => 500, 'body' => 'error']]]);
        $this->cycleAfter(15);
        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);

        foreach ([45, 30, 15] as $remaining) {
            $this->cycleAfter(15);
            $this->assertSame([], FakeWcm::requests());
            $this->assertSame("Pause nach Fehler, nächster Versuch in $remaining s", self::lastDebug());
            $this->assertContains('KesselAussentemperatur', self::disabledIdents());
            $this->assertAllInvalid();
        }

        $this->cycleAfter(15);

        $this->assertSame([9, 9, 9, 3], self::chunkSizes(), 'All parameters after the pause');
        $this->assertAllValid();
    }

    public function testPauseGrowsUpToFiveMinutes(): void
    {
        $this->switchOn();
        FakeWcm::reset(['responses' => array_fill(0, 10, ['status' => 500, 'body' => 'error'])]);
        $this->cycleAfter(15);

        foreach ([60, 120, 300, 300, 300] as $failure => $pause) {
            $this->assertStringEndsWith(sprintf('| Pause %d s (Fehler Nr. %d)', $pause, $failure + 1), self::lastDebug());
            $requests = count(FakeWcm::requests());

            $this->cycleAfter($pause - 1);
            $this->assertCount($requests, FakeWcm::requests(), "Still in pause $pause s");

            $this->cycleAfter(1);
            $this->assertCount($requests + 1, FakeWcm::requests(), "Pause $pause s is over");
        }
    }

    public function testSuccessfulCycleResetsThePause(): void
    {
        $this->switchOn();
        FakeWcm::reset(['responses' => [['status' => 500, 'body' => 'error']]]);
        $this->cycleAfter(15);
        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);
        $this->cycleAfter(60);
        $this->assertAllValid();

        FakeWcm::reset(['responses' => [['status' => 500, 'body' => 'error']]]);
        $this->cycleAfter(15);

        $this->assertStringEndsWith('| Pause 60 s (Fehler Nr. 1)', self::lastDebug());
    }

    public function testBusyServerStartsPause(): void
    {
        $this->switchOn();
        FakeWcm::reset(['responses' => [['body' => 'server is busy']]]);

        $this->cycleAfter(15);
        $this->assertStringStartsWith('Zyklus fehlgeschlagen (Server busy in Block 1/2): WCM-COM server is busy.', self::lastDebug());
        $this->assertAllInvalid();

        $this->cycleAfter(15);
        $this->assertCount(1, FakeWcm::requests());
    }

    public function testTimeoutStartsPause(): void
    {
        $this->switchOn();
        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE, 'delay' => 0.8]);

        $this->cycleAfter(15);
        usleep(400000); // let the fake server finish the delayed answer

        $this->assertStringStartsWith('Zyklus fehlgeschlagen (Antwort-Timeout in Block 1/2): CURL error occurred', self::lastDebug());
        $this->assertCount(1, FakeWcm::requests());
        $this->assertAllInvalid();
    }

    public function testUnreachableDeviceStartsPause(): void
    {
        \IpsFake::reset();
        $this->module = self::createModule(['URL' => 'http://127.0.0.1:1']);

        $this->cycleAfter(15);

        $this->assertStringStartsWith('Zyklus fehlgeschlagen (keine Verbindung in Block 1/4): CURL error occurred', self::lastDebug());
        $this->assertStringEndsWith('| Pause 60 s (Fehler Nr. 1)', self::lastDebug());
        $this->assertAllInvalid();
    }

    public function testSwitchingUpdatesOnEndsThePause(): void
    {
        $this->switchOn();
        FakeWcm::reset(['responses' => [['status' => 500, 'body' => 'error']]]);
        $this->cycleAfter(15);
        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);

        $this->module->RequestAction('ParameterUpdate', true);

        $this->assertCount(30, FakeWcm::sentTelegrams());
        $this->assertAllValid();
    }

    public function testWriteDuringPauseIsSent(): void
    {
        $this->switchOn();
        FakeWcm::reset(['responses' => [['status' => 500, 'body' => 'error']]]);
        $this->cycleAfter(15);
        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);

        $this->module->RequestAction('SteilheitHK2', 14.0);

        $this->assertSame([[6, 1, 2, 270, 0, 0, 140, 0]], FakeWcm::sentTelegrams());
    }

    public function testWriteWithoutAnswerIsNotRetriedAndPausesReading(): void
    {
        $this->switchOn();
        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE, 'delay' => 0.8]);

        try {
            $this->module->RequestAction('SteilheitHK2', 14.0);
            $this->fail('Expected a timeout');
        } catch (\WeishauptTimeoutException) {
        }
        usleep(400000);

        $this->assertCount(1, FakeWcm::requests(), 'No retry');
        $this->assertEqualsWithDelta(12.5, self::value('SteilheitHK2'), 1e-9, 'Keeps the old value');
        $this->assertStringStartsWith('Schreiben fehlgeschlagen (Antwort-Timeout): CURL error occurred', self::lastDebug());
        $this->assertSame([], \IpsFake::$semaphores);

        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);
        $this->cycleAfter(15);
        $this->assertSame([], FakeWcm::requests(), 'Reading pauses');
    }

    // --- Time limits and overlapping cycles -----------------------------------

    /**
     * Generous request timeout: the WCM-COM should not keep working on
     * requests the module already gave up. No new request after 10 s.
     */
    public function testTimeLimitsOfTheModule(): void
    {
        $constant = fn (string $name) => (new \ReflectionClassConstant(\WCMConnector::class, $name))->getValue();

        $this->assertSame(3.0, $constant('CONNECT_TIMEOUT'));
        $this->assertSame(15.0, $constant('REQUEST_TIMEOUT'));
        $this->assertSame(10.0, $constant('NEW_REQUEST_LIMIT'));
    }

    /** The timer does not wait for a running cycle: cycles never overlap, the run is skipped. */
    public function testTimerDoesNotWaitForTheSemaphore(): void
    {
        \IpsFake::$semaphoreWaits = [];

        $this->module->RetrieveWCMStatus(true);

        $this->assertSame([0], \IpsFake::$semaphoreWaits);
    }

    public function testWritesWaitUpToThirtySecondsForARunningCycle(): void
    {
        \IpsFake::$semaphoreWaits = [];

        $this->module->RequestAction('SteilheitHK2', 14.0);
        $this->module->UpdateWCMMaxLeistungWW(70);
        $this->module->RequestAction('ParameterUpdate', true);

        $this->assertSame([30000, 30000, 30000], \IpsFake::$semaphoreWaits);
    }

    public function testNoNewRequestAfterTheLimitFailsTheCycle(): void
    {
        // ClockedWCMConnector: no new request after 2 s; each block takes 0.35 s,
        // so block 6 starts at 1.75 s and is answered at 2.1 s
        \IpsFake::reset();
        $this->module = self::createModule(['BlockSize' => 3]);
        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE, 'delay' => 0.35]);

        $this->cycleAfter(15);

        $this->assertCount(6, FakeWcm::requests(), 'The running request finishes, no 7th request');
        $this->assertStringStartsWith('Zyklus fehlgeschlagen (zu langsam in Block 7/10): No further request after 2.0 s, 6 of 10 blocks answered.', self::lastDebug());
        $this->assertAllInvalid();
    }

    // --- Block size and pause between blocks ---------------------------------

    public function testBlockSizeIsConfigurable(): void
    {
        \IpsFake::reset();
        $this->module = self::createModule(['BlockSize' => 4]);

        $this->cycleAfter(15);

        $this->assertSame([4, 4, 4, 4, 4, 4, 4, 2], self::chunkSizes());
        $this->assertAllValid();
    }

    public function testBlockSizeIsLimitedToNine(): void
    {
        \IpsFake::reset();
        $this->module = self::createModule(['BlockSize' => 20]);

        $this->cycleAfter(15);

        $this->assertSame([9, 9, 9, 3], self::chunkSizes());
    }

    public function testPauseBetweenBlocks(): void
    {
        \IpsFake::reset();
        $this->module = self::createModule(['BlockPause' => 250]);

        $this->cycleAfter(15);

        $times = FakeWcm::requestTimes();
        $this->assertCount(4, $times);
        for ($i = 1; $i < 4; $i++) {
            $this->assertGreaterThanOrEqual(0.25, $times[$i] - $times[$i - 1], "Pause before block " . ($i + 1));
        }
        $this->assertAllValid();
    }

    public function testDefaultPauseBetweenBlocksIs300Milliseconds(): void
    {
        $module = new \WCMConnector(self::INSTANCE + 1);
        $module->Create();

        $this->assertSame(300, \IpsFake::$properties[self::INSTANCE + 1]['BlockPause']);
        $this->assertSame(9, \IpsFake::$properties[self::INSTANCE + 1]['BlockSize']);
        $this->assertSame(15, \IpsFake::$properties[self::INSTANCE + 1]['SlowParameterInterval']);
    }

    // --- How a cycle fails (debug) ---------------------------------------------

    /**
     * The WCM-COM answers, but its eBus side delivers nothing: an outage,
     * not a list of missing sensors. Laststellung is a raw value without N/V
     * and reads 0 here; it doesn't count as a valid measured value.
     */
    public function testAnswerWithOnlyErrorAndNotAvailableTelegramsIsAnOutage(): void
    {
        $this->switchOn();
        $notAvailable = array_map(fn () => -32768, WCMConnectorTest::DEVICE);
        FakeWcm::reset(['device' => ['0/0/1' => 0] + $notAvailable, 'responses' => [['set' => [1 => [2 => 255], 2 => [2 => 255]]]]]);

        $this->cycleAfter(15);

        $this->assertAllInvalid();
        $this->assertCount(2, FakeWcm::requests(), 'Both blocks were answered');
        $this->assertStringStartsWith('Zyklus fehlgeschlagen (nur ERROR/N/V): WCM-COM lieferte keine gültigen Werte (2 ERROR, 13 N/V). | 17 Telegramme, 2/2 Blöcke', self::lastDebug());
        $this->assertSame('WCM-COM lieferte keine gültigen Werte (2 ERROR, 13 N/V).', \IpsFake::$log[0]['message']);

        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);
        $this->cycleAfter(15);
        $this->assertSame([], FakeWcm::requests(), 'Pause');
    }

    public function testAnswerWithOnlyErrorTelegramsIsAnOutage(): void
    {
        $this->switchOn();
        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE, 'responses' => [['command' => 255], ['command' => 255]]]);

        $this->cycleAfter(15);

        $this->assertAllInvalid();
        $this->assertStringStartsWith('Zyklus fehlgeschlagen (nur ERROR/N/V): WCM-COM lieferte keine gültigen Werte (17 ERROR, 0 N/V).', self::lastDebug());
    }

    public function testSingleErrorAndNotAvailableTelegramsAreCountedButNoOutage(): void
    {
        $this->switchOn(['3/0/118' => -32768] + WCMConnectorTest::DEVICE);

        $this->cycleAfter(15);

        $this->assertSame(['PufferB10Oben'], self::disabledIdents());
        $this->assertMatchesRegularExpression('#, ERROR 0, N/V 1, seltene Parameter: keine$#', self::lastDebug());
        $this->assertSame([], \IpsFake::$log);
    }

    public static function failures(): iterable
    {
        yield 'HTTP-Fehler' => [['status' => 503, 'body' => 'Service Unavailable'], 'HTTP-Fehler 503 in Block 1/2', 'Letzte Antwort des WCM-COM (HTTP 503): Service Unavailable'];
        yield 'Server busy' => [['body' => 'server is busy'], 'Server busy in Block 1/2', 'Letzte Antwort des WCM-COM (HTTP 200): server is busy'];
        yield 'unvollständig' => [['truncate' => 5], 'unvollständige Antwort in Block 1/2', 'Letzte Antwort des WCM-COM (HTTP 200): {"prot":"coco","telegramm":[[0,0,1,1,'];
        yield 'unpassend' => [['set' => [3 => [3 => 13]]], 'unpassende Antwort in Block 1/2', 'Letzte Antwort des WCM-COM (HTTP 200): {"prot":"coco"'];
        yield 'zusätzliches Telegramm' => [['appendTelegram' => [10, 0, 1, 14, 0, 0, 0, 0]], 'unpassende Antwort in Block 1/2', 'Letzte Antwort des WCM-COM (HTTP 200): {"prot":"coco"'];
        yield 'ungültiges JSON' => [['body' => '<html>Fehler</html>'], 'ungültige Antwort in Block 1/2', 'Letzte Antwort des WCM-COM (HTTP 200): <html>Fehler</html>'];
        yield 'leere Antwort' => [['body' => '{"prot":"coco","telegramm":[]}'], 'ungültige Antwort in Block 1/2', 'Letzte Antwort des WCM-COM (HTTP 200): {"prot":"coco","telegramm":[]}'];
        yield 'leerer Body' => [['status' => 200, 'body' => ''], 'ungültige Antwort in Block 1/2', 'Letzte Antwort des WCM-COM (HTTP 200): (leer)'];
    }

    /** Each kind of failure is named, the raw answer is shown before the summary. */
    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testDebugNamesTheKindOfFailureAndShowsTheAnswer(array $response, string $kind, string $answer): void
    {
        $this->switchOn();
        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE, 'responses' => [$response]]);

        $this->cycleAfter(15);

        $debug = array_column(\IpsFake::$debug, 'data');
        $this->assertStringStartsWith("Zyklus fehlgeschlagen ($kind): ", end($debug));
        $this->assertStringStartsWith($answer, prev($debug));
        $this->assertCount(1, FakeWcm::requests(), 'No retry');
        $this->assertAllInvalid();
    }

    public function testDebugShowsNoAnswerOnTimeout(): void
    {
        $this->switchOn();
        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE, 'responses' => [['delay' => 0.8]]]);

        $this->cycleAfter(15);
        usleep(400000);

        $debug = array_column(\IpsFake::$debug, 'data');
        $this->assertStringStartsWith('Zyklus fehlgeschlagen (Antwort-Timeout in Block 1/2): ', end($debug));
        $this->assertSame('Keine Antwort des WCM-COM', prev($debug));
    }

    public function testLongAnswerIsShortenedInTheDebugOutput(): void
    {
        $this->switchOn();
        FakeWcm::reset(['responses' => [['status' => 500, 'body' => str_repeat('x', 800)]]]);

        $this->cycleAfter(15);

        $debug = array_column(\IpsFake::$debug, 'data');
        $this->assertSame('Letzte Antwort des WCM-COM (HTTP 500): ' . str_repeat('x', 500) . ' … (800 Zeichen)', $debug[count($debug) - 2]);
    }
}
