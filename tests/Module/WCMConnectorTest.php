<?php

declare(strict_types=1);

namespace Tests\Module;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeWcm;

/**
 * WCMConnector inside a faked IP-Symcon runtime, talking to the simulated
 * WCM-COM. Configured for Heizkreis 2 and 3.
 */
final class WCMConnectorTest extends TestCase
{
    private const INSTANCE = 12345;
    private const SEMAPHORE = 'WCM_Communication';

    /** Raw device values, keyed MODULTYP/BUSKENNUNG/INFONR. */
    private const DEVICE = [
        '0/0/1' => 0,
        '10/0/12' => -35,
        '10/0/2572' => -12,
        '10/0/138' => 57,
        '10/0/319' => 1000,
        '10/0/345' => 800,
        '10/0/2' => 452,
        '10/0/13' => 448,
        '10/0/3101' => 451,
        '10/0/22' => 312,
        '10/0/14' => 503,
        '10/0/325' => 615,
        '3/0/118' => 551,
        '3/0/120' => 302,
        // Heizkreis 2 (BUSKENNUNG 1)
        '6/1/274' => 3,
        '12/1/15' => 352,
        '6/1/4' => 210,
        '6/1/2' => 360,
        '6/1/5' => 215,
        '6/1/2580' => 100,
        '6/1/278' => 180,
        '6/1/270' => 125,
        // Heizkreis 3 (BUSKENNUNG 2)
        '6/2/274' => 4,
        '12/2/15' => 298,
        '6/2/4' => 190,
        '6/2/2' => 310,
        '6/2/5' => 205,
        '6/2/2580' => 80,
        '6/2/278' => 170,
        '6/2/270' => 110,
    ];

    /** Variable values expected for DEVICE. */
    private const EXPECTED = [
        'KesselFehlercode' => 0,
        'KesselAussentemperatur' => -3.5,
        'KesselGedaempfteAussentemperatur' => -1.2,
        'KesselLaststellung' => 57,
        'KesselMaxLeistungHeizung' => 100,
        'KesselMaxLeistungWW' => 80,
        'KesselWaermeanforderung' => 45.2,
        'KesselVorlauftemperatur' => 44.8,
        'KesselVorlauftemperaturEstb' => 45.1,
        'KesselRuecklauftemperatur' => 31.2,
        'KesselWarmwassertemperatur' => 50.3,
        'KesselAbgastemperatur' => 61.5,
        'PufferB10Oben' => 55.1,
        'PufferB11Unten' => 30.2,
        'BetriebsartHeizkreis2' => 3,
        'VorlauftemperaturHK2' => 35.2,
        'SollTempHK2' => 21.0,
        'WaermeanforderungHK2' => 36.0,
        'NormalRaumtemperaturHK2' => 21.5,
        'RaumfrosttemperaturHK2' => 10.0,
        'SoWiUmschalttemperaturHK2' => 18.0,
        'SteilheitHK2' => 12.5,
        'BetriebsartHeizkreis3' => 4,
        'VorlauftemperaturHK3' => 29.8,
        'SollTempHK3' => 19.0,
        'WaermeanforderungHK3' => 31.0,
        'NormalRaumtemperaturHK3' => 20.5,
        'RaumfrosttemperaturHK3' => 8.0,
        'SoWiUmschalttemperaturHK3' => 17.0,
        'SteilheitHK3' => 11.0,
    ];

    private \WCMConnector $module;

    public static function setUpBeforeClass(): void
    {
        FakeWcm::start();
    }

    protected function setUp(): void
    {
        \IpsFake::reset();
        FakeWcm::reset(['device' => self::DEVICE]);
        $this->module = self::createModule();
    }

    private static function createModule(array $properties = []): \WCMConnector
    {
        $module = new \WCMConnector(self::INSTANCE);
        $module->Create();
        \IpsFake::configure(self::INSTANCE, $properties + [
            'URL' => FakeWcm::url(),
            'Username' => FakeWcm::USERNAME,
            'Password' => FakeWcm::PASSWORD,
            'UpdateInterval' => 60,
            'FirstHK' => 2,
            'LastHK' => 3,
        ]);
        $module->ApplyChanges();

        return $module;
    }

    private static function value(string $ident): mixed
    {
        return \IpsFake::variable(self::INSTANCE, $ident)['value'];
    }

    private static function timerInterval(): int
    {
        return \IpsFake::$timers[self::INSTANCE]['Update']['interval'];
    }

    private function assertAllValuesAre(callable $expected): void
    {
        foreach (self::EXPECTED as $ident => $value) {
            $this->assertEqualsWithDelta($expected($ident, $value), self::value($ident), 1e-9, $ident);
        }
    }

    // --- Create / ApplyChanges -------------------------------------------

    public function testCreateRegistersProfiles(): void
    {
        $this->assertSame(
            [1 => 'Standby', 3 => 'Normal', 4 => 'Absenk', 5 => 'Sommer', 11 => 'Programm1', 12 => 'Programm2', 13 => 'Programm3', 255 => 'WieLeitstelle'],
            \IpsFake::$profiles['WCM.BetriebsartHK']['associations']
        );
        $this->assertSame(1, \IpsFake::$profiles['WCM.MaxLeistung']['type']);
        $this->assertSame([36.0, 100.0, 1.0], [
            \IpsFake::$profiles['WCM.MaxLeistung']['min'],
            \IpsFake::$profiles['WCM.MaxLeistung']['max'],
            \IpsFake::$profiles['WCM.MaxLeistung']['step'],
        ]);
    }

    public function testCreateDoesNotFailWhenProfilesExist(): void
    {
        (new \WCMConnector(self::INSTANCE + 1))->Create();

        $this->assertArrayHasKey('WCM.BetriebsartHK', \IpsFake::$profiles);
    }

    public function testApplyChangesRegistersVariablesForConfiguredHeizkreise(): void
    {
        $idents = \IpsFake::idents(self::INSTANCE);

        $this->assertEqualsCanonicalizing(['ParameterUpdate', ...array_keys(self::EXPECTED)], $idents);
    }

    public function testApplyChangesEnablesActionsOnlyForWritableVariables(): void
    {
        $writable = [];
        foreach (\IpsFake::$variables as $variable) {
            if ($variable['action']) {
                $writable[] = $variable['ident'];
            }
        }

        $this->assertEqualsCanonicalizing([
            'ParameterUpdate', 'KesselMaxLeistungHeizung', 'KesselMaxLeistungWW',
            'BetriebsartHeizkreis2', 'NormalRaumtemperaturHK2', 'RaumfrosttemperaturHK2', 'SoWiUmschalttemperaturHK2', 'SteilheitHK2',
            'BetriebsartHeizkreis3', 'NormalRaumtemperaturHK3', 'RaumfrosttemperaturHK3', 'SoWiUmschalttemperaturHK3', 'SteilheitHK3',
        ], $writable);
    }

    public function testVariablePositionsAreGroupedPerHeizkreisAndKessel(): void
    {
        $position = fn (string $ident) => \IpsFake::variable(self::INSTANCE, $ident)['position'];

        $this->assertSame([21, 28], [$position('BetriebsartHeizkreis2'), $position('SteilheitHK2')]);
        $this->assertSame([31, 38], [$position('BetriebsartHeizkreis3'), $position('SteilheitHK3')]);
        $this->assertSame([41, 54], [$position('KesselFehlercode'), $position('PufferB11Unten')]);
    }

    public function testApplyChangesWithUpdatesOffDoesNotContactTheDevice(): void
    {
        $this->assertSame([], FakeWcm::requests());
        $this->assertSame(0, self::timerInterval());
        $this->assertFalse(self::value('ParameterUpdate'));
    }

    // --- Reading ---------------------------------------------------------

    public function testSwitchingUpdatesOnReadsAllValues(): void
    {
        $this->module->RequestAction('ParameterUpdate', true);

        $this->assertTrue(self::value('ParameterUpdate'));
        $this->assertAllValuesAre(fn ($ident, $value) => $value);
        // 1 Fehlercode + 2 x 8 Heizkreis + 13 Kessel/Puffer = 30 telegrams
        $this->assertSame([9, 9, 9, 3],array_map(fn ($r) => count($r['telegramm']), FakeWcm::requests()));
        $this->assertSame([0, 0, 1, 1, 0, 0, 0, 0], FakeWcm::sentTelegrams()[0], 'Fehlercode comes first');
        $this->assertSame([], \IpsFake::$semaphores, 'Semaphore is released');
    }

    public function testSwitchingUpdatesOnStartsTimer(): void
    {
        $this->module->RequestAction('ParameterUpdate', true);

        $this->assertSame(60000, self::timerInterval());
        $this->assertSame(
            'WCM_RetrieveWCMStatus(' . self::INSTANCE . ', true);',
            \IpsFake::$timers[self::INSTANCE]['Update']['script']
        );
    }

    public function testSwitchingUpdatesOffStopsTimerAndResetsValues(): void
    {
        $this->module->RequestAction('ParameterUpdate', true);
        $requests = count(FakeWcm::requests());

        $this->module->RequestAction('ParameterUpdate', false);

        $this->assertSame(0, self::timerInterval());
        $this->assertAllValuesAre(fn () => 0);
        $this->assertCount($requests, FakeWcm::requests(), 'No further request');
    }

    public function testReadsValuesForOtherHeizkreisRange(): void
    {
        \IpsFake::reset();
        $module = self::createModule(['FirstHK' => 3, 'LastHK' => 3]);

        $module->RetrieveWCMStatus(true);

        $this->assertNull(\IpsFake::variableId(self::INSTANCE, 'SteilheitHK2'));
        $this->assertEqualsWithDelta(11.0, self::value('SteilheitHK3'), 1e-9);
        $this->assertEqualsWithDelta(-3.5, self::value('KesselAussentemperatur'), 1e-9);
    }

    #[Group('slow')]
    public function testReadFailureIsLoggedAndResetsValues(): void
    {
        $this->module->RequestAction('ParameterUpdate', true);
        FakeWcm::reset(['responses' => array_fill(0, 3, ['status' => 500, 'body' => 'error'])]);

        $this->module->RetrieveWCMStatus(true);

        $this->assertAllValuesAre(fn () => 0);
        $this->assertCount(1, \IpsFake::$log);
        $this->assertSame(KL_ERROR, \IpsFake::$log[0]['type']);
        $this->assertStringContainsString('HTTP return code 500', \IpsFake::$log[0]['message']);
    }

    /**
     * A chunk answered with one telegram less would shift all later buffer
     * positions onto the wrong parameters. It must be rejected and retried.
     */
    #[Group('slow')]
    public function testIncompleteAnswerIsRetriedInsteadOfAssigningWrongValues(): void
    {
        FakeWcm::reset(['device' => self::DEVICE, 'responses' => [['truncate' => 8]]]);

        $this->module->RetrieveWCMStatus(true);

        $this->assertAllValuesAre(fn ($ident, $value) => $value);
        $this->assertCount(5, FakeWcm::requests(), '4 chunks + 1 retry');
        $this->assertSame([], \IpsFake::$log);
    }

    public function testErrorTelegramResetsOnlyThatVariable(): void
    {
        // Telegram 8 of the first chunk is SteilheitHK2
        FakeWcm::reset(['device' => self::DEVICE, 'responses' => [['set' => [8 => [2 => 255]]]]]);

        $this->module->RetrieveWCMStatus(true);

        $this->assertAllValuesAre(fn ($ident, $value) => $ident === 'SteilheitHK2' ? 0 : $value);
        $this->assertCount(4, FakeWcm::requests(), 'No retry for an error telegram');
        $this->assertSame([], \IpsFake::$log);
        $this->assertSame('WCM-COM returned an error for SteilheitHK2', \IpsFake::$debug[0]['data']);
    }

    /** The timer must not talk to the WCM while a write holds the semaphore. */
    public function testCyclicReadIsSkippedWhileSemaphoreIsHeld(): void
    {
        $this->module->RequestAction('ParameterUpdate', true);
        FakeWcm::reset(['device' => self::DEVICE]);
        IPS_SemaphoreEnter(self::SEMAPHORE, 0);

        $this->module->RetrieveWCMStatus(true);

        $this->assertSame([], FakeWcm::requests());
        $this->assertAllValuesAre(fn ($ident, $value) => $value);
        $this->assertSame('Skipped, WCM-COM is busy with another request', end(\IpsFake::$debug)['data']);
        $this->assertSame(['WCM_Communication' => true], \IpsFake::$semaphores, 'Foreign semaphore stays held');
    }

    public function testCyclicReadReleasesSemaphore(): void
    {
        $this->module->RetrieveWCMStatus(true);

        $this->assertCount(4, FakeWcm::requests());
        $this->assertSame([], \IpsFake::$semaphores);
    }

    // --- Writing ---------------------------------------------------------

    public static function heizkreisWrites(): iterable
    {
        yield 'Betriebsart' => ['BetriebsartHeizkreis3', 5, [6, 2, 2, 274, 0, 0, 5, 0], '6/2/274', 5];
        yield 'Normal Raumtemperatur' => ['NormalRaumtemperaturHK3', 22.5, [6, 2, 2, 5, 0, 0, 225, 0], '6/2/5', 225];
        yield 'Raumfrosttemperatur' => ['RaumfrosttemperaturHK2', -2.0, [6, 1, 2, 2580, 0, 0, 236, 255], '6/1/2580', -20];
        yield 'So/Wi Umschalttemperatur' => ['SoWiUmschalttemperaturHK2', 16.5, [6, 1, 2, 278, 0, 0, 165, 0], '6/1/278', 165];
        yield 'Steilheit' => ['SteilheitHK3', 14.0, [6, 2, 2, 270, 0, 0, 140, 0], '6/2/270', 140];
    }

    #[DataProvider('heizkreisWrites')]
    public function testWritingHeizkreisParameter(string $ident, float|int $value, array $telegram, string $key, int $raw): void
    {
        $this->module->RequestAction($ident, $value);

        $this->assertSame([$telegram], FakeWcm::sentTelegrams());
        $this->assertSame($raw, FakeWcm::deviceValue($key));
        $this->assertEqualsWithDelta($value, self::value($ident), 1e-9);
        $this->assertSame([], \IpsFake::$semaphores);
    }

    public static function kesselWrites(): iterable
    {
        yield 'Heizung' => ['KesselMaxLeistungHeizung', 60, [10, 0, 2, 319, 0, 0, 88, 2], '10/0/319'];
        yield 'Warmwasser' => ['KesselMaxLeistungWW', 90, [10, 0, 2, 345, 0, 0, 132, 3], '10/0/345'];
    }

    #[DataProvider('kesselWrites')]
    public function testWritingMaxLeistung(string $ident, int $value, array $telegram, string $key): void
    {
        $this->module->RequestAction($ident, $value);

        $this->assertSame([$telegram], FakeWcm::sentTelegrams());
        $this->assertSame($value * 10, FakeWcm::deviceValue($key));
        $this->assertSame($value, self::value($ident));
        $this->assertSame([], \IpsFake::$semaphores);
    }

    public static function invalidWrites(): iterable
    {
        yield 'Steilheit too high' => ['SteilheitHK2', 50.0, 'outside of valid range'];
        yield 'Raumtemperatur negative' => ['NormalRaumtemperaturHK3', -1.0, 'outside of valid range'];
        yield 'MaxLeistung too low' => ['KesselMaxLeistungHeizung', 20, 'outside of valid range'];
        yield 'unknown ident' => ['DoesNotExist', 1, 'Invalid Ident'];
    }

    #[DataProvider('invalidWrites')]
    public function testInvalidWriteChangesNothingAndReleasesSemaphore(string $ident, float|int $value, string $message): void
    {
        $before = \IpsFake::variableId(self::INSTANCE, $ident) !== null ? self::value($ident) : null;

        try {
            $this->module->RequestAction($ident, $value);
            $this->fail('Expected an exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame([], FakeWcm::requests(), 'Nothing is sent');
        $this->assertSame([], \IpsFake::$semaphores, 'Semaphore is released');
        if ($before !== null) {
            $this->assertSame($before, self::value($ident), 'Variable keeps its value');
        }
    }

    /** Takes 10-20 s: a failed write is retried 10 times with 1-2 s pauses. */
    #[Group('slow')]
    public function testFailedWriteKeepsOldValueAndReleasesSemaphore(): void
    {
        FakeWcm::reset(['responses' => array_fill(0, 10, ['status' => 500, 'body' => 'error'])]);

        try {
            $this->module->RequestAction('SteilheitHK2', 14.0);
            $this->fail('Expected an exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('HTTP return code 500', $e->getMessage());
        }

        $this->assertCount(10, FakeWcm::requests());
        $this->assertSame(0.0, self::value('SteilheitHK2'));
        $this->assertSame([], \IpsFake::$semaphores);
    }

    public static function rejectedWrites(): iterable
    {
        yield 'Heizkreis parameter' => ['SteilheitHK2', 14.0, 270];
        yield 'MaxLeistung Heizung' => ['KesselMaxLeistungHeizung', 60, 319];
        yield 'MaxLeistung WW' => ['KesselMaxLeistungWW', 60, 345];
    }

    #[DataProvider('rejectedWrites')]
    public function testWriteRejectedByWcmKeepsOldValueAndReleasesSemaphore(string $ident, float|int $value, int $info): void
    {
        FakeWcm::reset(['responses' => [['command' => 255]]]);
        $before = self::value($ident);

        try {
            $this->module->RequestAction($ident, $value);
            $this->fail('Expected an exception');
        } catch (\Exception $e) {
            $this->assertSame("WCM-COM rejected the write request for INFONR $info.", $e->getMessage());
        }

        $this->assertCount(1, FakeWcm::requests(), 'No retry for a rejected write');
        $this->assertSame($before, self::value($ident));
        $this->assertSame([], \IpsFake::$semaphores);
    }

    public static function publicMaxLeistungFunctions(): iterable
    {
        yield 'Heizung' => ['UpdateWCMMaxLeistungHeizung', [10, 0, 2, 319, 0, 0, 88, 2]];
        yield 'Warmwasser' => ['UpdateWCMMaxLeistungWW', [10, 0, 2, 345, 0, 0, 88, 2]];
    }

    #[DataProvider('publicMaxLeistungFunctions')]
    public function testPublicMaxLeistungFunctionWritesAndReleasesSemaphore(string $method, array $telegram): void
    {
        $this->module->$method(60);

        $this->assertSame([$telegram], FakeWcm::sentTelegrams());
        $this->assertSame([], \IpsFake::$semaphores);
    }

    #[DataProvider('publicMaxLeistungFunctions')]
    public function testPublicMaxLeistungFunctionIsBlockedWhileSemaphoreIsHeld(string $method, array $telegram): void
    {
        IPS_SemaphoreEnter(self::SEMAPHORE, 0);

        $this->expectOutputString('Durch parallele Anfrage blockiert.');
        $this->module->$method(60);

        $this->assertSame([], FakeWcm::requests());
    }

    #[DataProvider('publicMaxLeistungFunctions')]
    public function testPublicMaxLeistungFunctionReleasesSemaphoreOnError(string $method, array $telegram): void
    {
        try {
            $this->module->$method(20);
            $this->fail('Expected an exception');
        } catch (\Exception $e) {
            $this->assertStringContainsString('outside of valid range', $e->getMessage());
        }

        $this->assertSame([], \IpsFake::$semaphores);
    }

    public function testInvalidBetriebsartIsRejected(): void
    {
        try {
            $this->module->RequestAction('BetriebsartHeizkreis2', 2);
            $this->fail('Expected an exception');
        } catch (\Exception $e) {
            $this->assertStringStartsWith('Invalid Betriebsart 2,', $e->getMessage());
        }

        $this->assertSame([], FakeWcm::requests());
        $this->assertSame([], \IpsFake::$semaphores);
    }

    public function testWriteIsBlockedWhileSemaphoreIsHeld(): void
    {
        IPS_SemaphoreEnter(self::SEMAPHORE, 0);

        $this->expectOutputString('Durch parallele Anfrage blockiert.');
        $this->module->RequestAction('SteilheitHK2', 14.0);

        $this->assertSame([], FakeWcm::requests());
        $this->assertSame(0.0, self::value('SteilheitHK2'));
    }
}
