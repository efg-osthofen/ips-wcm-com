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

    /** INFONR of NormalRaumtemperatur, Raumfrosttemperatur, So/Wi, Steilheit, MaxLeistung Heizung/WW */
    private const SLOW_INFONR = [5, 2580, 278, 270, 319, 345];

    private const SLOW_IDENTS = [
        'NormalRaumtemperaturHK2', 'RaumfrosttemperaturHK2', 'SoWiUmschalttemperaturHK2', 'SteilheitHK2',
        'NormalRaumtemperaturHK3', 'RaumfrosttemperaturHK3', 'SoWiUmschalttemperaturHK3', 'SteilheitHK3',
        'KesselMaxLeistungHeizung', 'KesselMaxLeistungWW',
    ];

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
        $this->assertStringStartsWith('Zyklus OK: 46 Telegramme, 6/6 Blöcke, 7 HTTP-Anfragen, ', self::lastDebug());
        $this->assertStringEndsWith(' s, seltene Parameter: alle', self::lastDebug());
    }

    public function testNormalCycleForHeizkreis2To5Needs5HttpRequests(): void
    {
        \IpsFake::reset();
        $this->module = self::createModule(['FirstHK' => 2, 'LastHK' => 5]);
        $this->switchOn([]);

        $this->cycleAfter(15);

        $this->assertSame([9, 9, 9, 1], self::chunkSizes());
        $this->assertStringStartsWith('Zyklus OK: 28 Telegramme, 4/4 Blöcke, 5 HTTP-Anfragen, ', self::lastDebug());
    }

    // --- Slow parameters ---------------------------------------------------

    public function testNormalCycleSkipsSlowParameters(): void
    {
        $this->switchOn();

        $this->cycleAfter(15);

        $this->assertSame([9, 9, 2], self::chunkSizes());
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
        $this->assertSame(20, count(FakeWcm::sentTelegrams()), 'Next 15 minutes start now');
    }

    public function testSlowParameterIntervalIsConfigurable(): void
    {
        \IpsFake::reset();
        $this->module = self::createModule(['SlowParameterInterval' => 5]);
        $this->switchOn();

        $this->cycleAfter(4 * 60 + 59);
        $this->assertCount(20, FakeWcm::sentTelegrams());

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

        $this->assertCount(21, FakeWcm::sentTelegrams());
        $this->assertContains([6, 1, 1, 270, 0, 0, 0, 0], FakeWcm::sentTelegrams(), 'Read telegram for SteilheitHK2');
        $this->assertSame([270], array_values(array_intersect(self::sentInfoNumbers(), self::SLOW_INFONR)), 'Only the written parameter');
        $this->assertEqualsWithDelta(14.1, self::value('SteilheitHK2'), 1e-9, 'Value of the device');
        $this->assertStringEndsWith('seltene Parameter: 1 nach Schreiben', self::lastDebug());

        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);
        $this->cycleAfter(15);
        $this->assertCount(20, FakeWcm::sentTelegrams(), 'Read back only once');
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

        $this->assertCount(20, FakeWcm::sentTelegrams());
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
        $this->assertStringStartsWith('Zyklus fehlgeschlagen (Fehlerhafte Antwort): HTTP return code 500', self::lastDebug());
        $this->assertStringContainsString('| 20 Telegramme, 0/3 Blöcke, 2 HTTP-Anfragen, ', self::lastDebug());
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
        $this->assertStringStartsWith('Zyklus fehlgeschlagen (Server busy): WCM-COM server is busy.', self::lastDebug());
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

        $this->assertStringStartsWith('Zyklus fehlgeschlagen (Timeout): CURL error occurred', self::lastDebug());
        $this->assertCount(1, FakeWcm::requests());
        $this->assertAllInvalid();
    }

    public function testUnreachableDeviceStartsPause(): void
    {
        \IpsFake::reset();
        $this->module = self::createModule(['URL' => 'http://127.0.0.1:1']);

        $this->cycleAfter(15);

        $this->assertStringStartsWith('Zyklus fehlgeschlagen (keine Verbindung): CURL error occurred', self::lastDebug());
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
        $this->assertStringStartsWith('Schreiben fehlgeschlagen (Timeout): CURL error occurred', self::lastDebug());
        $this->assertSame([], \IpsFake::$semaphores);

        FakeWcm::reset(['device' => WCMConnectorTest::DEVICE]);
        $this->cycleAfter(15);
        $this->assertSame([], FakeWcm::requests(), 'Reading pauses');
    }

    // --- Time limits ---------------------------------------------------------

    /** A cycle must end before the semaphore timeout (10 s) and the update interval (15 s). */
    public function testTimeLimitsOfTheModuleFitIntoTheSemaphoreTimeout(): void
    {
        $constant = fn (string $name) => (new \ReflectionClassConstant(\WCMConnector::class, $name))->getValue();

        $this->assertSame(3.0, $constant('CONNECT_TIMEOUT'));
        $this->assertSame(8.0, $constant('REQUEST_TIMEOUT'));
        $this->assertLessThan(10.0, $constant('CYCLE_TIME_BUDGET'));
        $this->assertLessThan($constant('SEMAPHORE_TIMEOUT') / 1000, $constant('CYCLE_TIME_BUDGET'));
    }
}
