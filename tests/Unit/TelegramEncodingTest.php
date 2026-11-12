<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\PrivateAccess;

/**
 * Building request telegrams:
 * [MODULTYP, BUSKENNUNG, COMMAND, INFONR, INDEX, PROT, DATA (low byte), HIGH_BYTE]
 *
 * The INFONR and MODULTYP values are written out on purpose instead of being
 * taken from constants.php, so an accidental change there is caught.
 */
final class TelegramEncodingTest extends TestCase
{
    use PrivateAccess;

    private \Weishaupt $api;

    protected function setUp(): void
    {
        $this->api = new \Weishaupt(new \WeishauptOptions('http://wcm.invalid', 'user', 'pass'));
    }

    public static function readTelegrams(): iterable
    {
        // Kessel (MODULTYP 10)
        yield 'Ruecklauftemperatur' => ['bufferedRequestRuecklauftemperatur', [], [10, 0, 1, 22, 0, 0, 0, 0]];
        yield 'Warmwassertemperatur' => ['bufferedRequestWarmwassertemperatur', [], [10, 0, 1, 14, 0, 0, 0, 0]];
        yield 'Laststellung' => ['bufferedRequestLaststellung', [], [10, 0, 1, 138, 0, 0, 0, 0]];
        yield 'MaxLeistungHeizung' => ['bufferedRequestMaxLeistungHeizung', [], [10, 0, 1, 319, 0, 0, 0, 0]];
        yield 'MaxLeistungWW' => ['bufferedRequestMaxLeistungWW', [], [10, 0, 1, 345, 0, 0, 0, 0]];
        yield 'Abgastemperatur' => ['bufferedRequestAbgastemperatur', [], [10, 0, 1, 325, 0, 0, 0, 0]];
        yield 'Waermeanforderung' => ['bufferedRequestWaermeanforderung', [], [10, 0, 1, 2, 0, 0, 0, 0]];
        yield 'Aussentemperatur' => ['bufferedRequestAussentemperatur', [], [10, 0, 1, 12, 0, 0, 0, 0]];
        yield 'GedaempfteAussentemperatur' => ['bufferedRequestGedaempfteAussentemperatur', [], [10, 0, 1, 2572, 0, 0, 0, 0]];
        yield 'Vorlauftemperatur' => ['bufferedRequestVorlauftemperatur', [], [10, 0, 1, 13, 0, 0, 0, 0]];
        yield 'VorlauftemperaturEstb' => ['bufferedRequestVorlauftemperaturEstb', [], [10, 0, 1, 3101, 0, 0, 0, 0]];
        // Pufferspeicher (MODULTYP 3)
        yield 'B10PufferOben' => ['bufferedRequestB10PufferOben', [], [3, 0, 1, 118, 0, 0, 0, 0]];
        yield 'B11PufferUnten' => ['bufferedRequestB11PufferUnten', [], [3, 0, 1, 120, 0, 0, 0, 0]];
        // Heizkreis 2 -> BUSKENNUNG 1 (MODULTYP 6, Vorlauf MODULTYP 12)
        yield 'BetriebsartHK' => ['bufferedRequestBetriebsartHK', [2], [6, 1, 1, 274, 0, 0, 0, 0]];
        yield 'VorlauftemperaturHK' => ['bufferedRequestVorlauftemperaturHK', [2], [12, 1, 1, 15, 0, 0, 0, 0]];
        yield 'SollTempHK' => ['bufferedRequestSollTempHK', [2], [6, 1, 1, 4, 0, 0, 0, 0]];
        yield 'WaermeanforderungHK' => ['bufferedRequestWaermeanforderungHK', [2], [6, 1, 1, 2, 0, 0, 0, 0]];
        yield 'NormalRaumtemperaturHK' => ['bufferedRequestNormalRaumtemperaturHK', [2], [6, 1, 1, 5, 0, 0, 0, 0]];
        yield 'SteilheitHK' => ['bufferedRequestSteilheitHK', [2], [6, 1, 1, 270, 0, 0, 0, 0]];
        yield 'RaumfrosttemperaturHK' => ['bufferedRequestRaumfrosttemperaturHK', [2], [6, 1, 1, 2580, 0, 0, 0, 0]];
        yield 'SoWiUmschalttemperaturHK' => ['bufferedRequestSoWiUmschalttemperaturHK', [2], [6, 1, 1, 278, 0, 0, 0, 0]];
    }

    #[DataProvider('readTelegrams')]
    public function testReadRequestBuildsTelegram(string $method, array $args, array $expected): void
    {
        $position = $this->api->$method(...$args);

        $this->assertSame(0, (int) $position);
        $this->assertSame([$expected], self::bufferOf($this->api));
    }

    public function testFehlercodeIsReadFromModultypZero(): void
    {
        $this->api->bufferedRequestFehlercode();

        $this->assertSame([[0, 0, 1, 1, 0, 0, 0, 0]], self::bufferOf($this->api));
    }

    public function testFehlercodeMustBeTheFirstTelegram(): void
    {
        $this->api->bufferedRequestAussentemperatur();

        $this->expectExceptionMessage("'Fehlercode' needs to be the first telegram.");
        $this->api->bufferedRequestFehlercode();
    }

    public function testHeizkreisNumberMapsToBuskennungMinusOne(): void
    {
        foreach ([1, 2, 5, 8] as $heizkreis) {
            $this->api->bufferedRequestBetriebsartHK($heizkreis);
        }

        $this->assertSame([0, 1, 4, 7], array_column(self::bufferOf($this->api), 1));
    }

    public function testBufferPositionsAreSequential(): void
    {
        $positions = [
            $this->api->bufferedRequestFehlercode(),
            $this->api->bufferedRequestAussentemperatur(),
            $this->api->bufferedRequestBetriebsartHK(2),
            $this->api->bufferedRequestB10PufferOben(),
        ];

        $this->assertSame([0, 1, 2, 3], $positions);
    }

    public function testClearBufferEmptiesTheBuffer(): void
    {
        $this->api->bufferedRequestAussentemperatur();
        $this->api->clearBuffer();

        $this->assertSame([], self::bufferOf($this->api));
        $this->assertSame(0, $this->api->bufferedRequestAussentemperatur());
    }

    /** Return type of these two methods is declared as float instead of int. */
    #[Group('known-issue')]
    public function testAllRequestMethodsReturnIntegerPositions(): void
    {
        $this->assertIsInt($this->api->bufferedRequestRuecklauftemperatur());
        $this->assertIsInt($this->api->bufferedRequestWarmwassertemperatur());
    }

    public static function writeTelegrams(): iterable
    {
        // Values are sent multiplied by 10 as signed 16-bit (low byte, high byte)
        yield 'Betriebsart HK3 Absenk' => ['bufferedUpdateBetriebsartHK', [3, 4], [6, 2, 2, 274, 0, 0, 4, 0]];
        yield 'Betriebsart HK2 WieLeitstelle' => ['bufferedUpdateBetriebsartHK', [2, 255], [6, 1, 2, 274, 0, 0, 255, 0]];
        yield 'MaxLeistungHeizung 36 %' => ['bufferedUpdateMaxLeistungHeizung', [36], [10, 0, 2, 319, 0, 0, 104, 1]];
        yield 'MaxLeistungHeizung 100 %' => ['bufferedUpdateMaxLeistungHeizung', [100], [10, 0, 2, 319, 0, 0, 232, 3]];
        yield 'MaxLeistungWW 75 %' => ['bufferedUpdateMaxLeistungWW', [75], [10, 0, 2, 345, 0, 0, 238, 2]];
        yield 'NormalRaumtemperatur 21.5' => ['bufferedUpdateNormalRaumtemperaturHK', [2, 21.5], [6, 1, 2, 5, 0, 0, 215, 0]];
        yield 'NormalRaumtemperatur 0' => ['bufferedUpdateNormalRaumtemperaturHK', [2, 0.0], [6, 1, 2, 5, 0, 0, 0, 0]];
        yield 'NormalRaumtemperatur 30' => ['bufferedUpdateNormalRaumtemperaturHK', [2, 30.0], [6, 1, 2, 5, 0, 0, 44, 1]];
        yield 'Steilheit 2.5' => ['bufferedUpdateSteilheitHK', [2, 2.5], [6, 1, 2, 270, 0, 0, 25, 0]];
        yield 'Steilheit 12.3' => ['bufferedUpdateSteilheitHK', [2, 12.3], [6, 1, 2, 270, 0, 0, 123, 0]];
        yield 'Steilheit 40' => ['bufferedUpdateSteilheitHK', [2, 40.0], [6, 1, 2, 270, 0, 0, 144, 1]];
        yield 'Raumfrost -10' => ['bufferedUpdateRaumfrosttemperaturHK', [2, -10.0], [6, 1, 2, 2580, 0, 0, 156, 255]];
        yield 'Raumfrost 5' => ['bufferedUpdateRaumfrosttemperaturHK', [2, 5.0], [6, 1, 2, 2580, 0, 0, 50, 0]];
        yield 'SoWi -0.5' => ['bufferedUpdateSoWiUmschalttemperaturHK', [2, -0.5], [6, 1, 2, 278, 0, 0, 251, 255]];
        yield 'SoWi 18' => ['bufferedUpdateSoWiUmschalttemperaturHK', [2, 18.0], [6, 1, 2, 278, 0, 0, 180, 0]];
    }

    #[DataProvider('writeTelegrams')]
    public function testWriteRequestBuildsTelegram(string $method, array $args, array $expected): void
    {
        $this->api->$method(...$args);

        $this->assertSame([$expected], self::bufferOf($this->api));
    }

    public static function outOfRangeValues(): iterable
    {
        yield 'MaxLeistungHeizung 35' => ['bufferedUpdateMaxLeistungHeizung', [35]];
        yield 'MaxLeistungHeizung 101' => ['bufferedUpdateMaxLeistungHeizung', [101]];
        yield 'MaxLeistungWW 35' => ['bufferedUpdateMaxLeistungWW', [35]];
        yield 'MaxLeistungWW 101' => ['bufferedUpdateMaxLeistungWW', [101]];
        yield 'NormalRaumtemperatur -0.1' => ['bufferedUpdateNormalRaumtemperaturHK', [2, -0.1]];
        yield 'NormalRaumtemperatur 30.1' => ['bufferedUpdateNormalRaumtemperaturHK', [2, 30.1]];
        yield 'Steilheit 2.4' => ['bufferedUpdateSteilheitHK', [2, 2.4]];
        yield 'Steilheit 40.1' => ['bufferedUpdateSteilheitHK', [2, 40.1]];
        yield 'Raumfrost -10.1' => ['bufferedUpdateRaumfrosttemperaturHK', [2, -10.1]];
        yield 'Raumfrost 30.1' => ['bufferedUpdateRaumfrosttemperaturHK', [2, 30.1]];
        yield 'SoWi -10.1' => ['bufferedUpdateSoWiUmschalttemperaturHK', [2, -10.1]];
        yield 'SoWi 30.1' => ['bufferedUpdateSoWiUmschalttemperaturHK', [2, 30.1]];
    }

    #[DataProvider('outOfRangeValues')]
    public function testWriteRejectsValuesOutsideRange(string $method, array $args): void
    {
        try {
            $this->api->$method(...$args);
            $this->fail('Expected an exception for an out-of-range value');
        } catch (\Exception $e) {
            $this->assertStringContainsString('outside of valid range', $e->getMessage());
        }

        $this->assertSame([], self::bufferOf($this->api), 'Nothing must be buffered');
    }

    public function testEveryTenthWithinRangeIsEncodedExactly(): void
    {
        // Guards against float artefacts like 2.3 * 10 = 22.999... -> 22
        for ($tenths = -100; $tenths <= 300; $tenths++) {
            $value = (float) sprintf('%.1f', $tenths / 10);
            $this->api->clearBuffer();
            $this->api->bufferedUpdateRaumfrosttemperaturHK(2, $value);
            [$telegram] = self::bufferOf($this->api);

            $raw = $telegram[6] | ($telegram[7] << 8);
            $signed = $raw >= 0x8000 ? $raw - 0x10000 : $raw;
            $this->assertSame($tenths, $signed, "Value $value");
        }
    }

    /** Values with more than one decimal are cut off instead of rounded (and trigger a PHP deprecation). */
    #[Group('known-issue')]
    public function testValuesWithMoreDecimalsAreRounded(): void
    {
        $this->api->bufferedUpdateNormalRaumtemperaturHK(2, 21.26);

        $this->assertSame(213, self::bufferOf($this->api)[0][6]);
    }

    /** Any integer is accepted as Betriebsart, even values the WCM does not know. */
    #[Group('known-issue')]
    public function testBetriebsartRejectsUnknownValues(): void
    {
        $this->expectException(\Exception::class);
        $this->api->bufferedUpdateBetriebsartHK(2, 2);
    }

    public static function lowHighBytes(): iterable
    {
        yield '0' => [0, 0, 0];
        yield '255' => [255, 255, 0];
        yield '256' => [256, 0, 1];
        yield '1000' => [1000, 232, 3];
        yield '32767' => [32767, 255, 127];
        yield '-1' => [-1, 255, 255];
        yield '-100' => [-100, 156, 255];
        yield '-32767' => [-32767, 1, 128];
    }

    #[DataProvider('lowHighBytes')]
    public function testLowAndHighByteSplitTwosComplement(int $value, int $low, int $high): void
    {
        $this->assertSame($low, self::callPrivate($this->api, '_calcLowByte', $value));
        $this->assertSame($high, self::callPrivate($this->api, '_calcHighByte', $value));
    }
}
