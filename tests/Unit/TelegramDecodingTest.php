<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Support\PrivateAccess;

/**
 * Decoding WCM answers: JSON parsing, 16-bit value extraction, scaling per
 * INFONR and unit lookup.
 */
final class TelegramDecodingTest extends TestCase
{
    use PrivateAccess;

    private \Weishaupt $api;

    protected function setUp(): void
    {
        $this->api = new \Weishaupt(new \WeishauptOptions('http://wcm.invalid', 'user', 'pass'));
    }

    /** Answer telegram carrying a signed raw value. */
    private static function telegram(int $modultyp, int $info, int $raw, int $bus = 0, int $command = 1): array
    {
        $raw &= 0xFFFF;

        return [$modultyp, $bus, $command, $info, 0, 0, $raw & 0xFF, ($raw >> 8) & 0xFF];
    }

    /** @return list<\FinalTelegramObject> */
    private function decode(array $telegrams): array
    {
        $body = json_encode(['prot' => 'coco', 'telegramm' => $telegrams]);

        return iterator_to_array(self::callPrivate($this->api, '_decodeTelegram', $body)->getIterator());
    }

    public static function scaledValues(): iterable
    {
        // divided by 10
        yield 'Waermeanforderung' => [10, 2, 452, 45.2, '°C'];
        yield 'SollTempHK' => [6, 4, 210, 21.0, null];
        yield 'NormalRaumtemperaturHK' => [6, 5, 215, 21.5, null];
        yield 'Aussentemperatur negativ' => [10, 12, -35, -3.5, '°C'];
        yield 'Vorlauftemperatur' => [10, 13, 448, 44.8, '°C'];
        yield 'Warmwassertemperatur' => [10, 14, 503, 50.3, '°C'];
        yield 'VorlauftemperaturHK' => [12, 15, 352, 35.2, null];
        yield 'Ruecklauftemperatur' => [10, 22, 312, 31.2, null];
        yield 'B10PufferOben' => [3, 118, 551, 55.1, '°C'];
        yield 'B11PufferUnten' => [3, 120, 302, 30.2, '°C'];
        yield 'SteilheitHK' => [6, 270, 125, 12.5, null];
        yield 'SoWiUmschalttemperaturHK' => [6, 278, 180, 18.0, null];
        yield 'MaxLeistungHeizung' => [10, 319, 1000, 100.0, '%'];
        yield 'Abgastemperatur' => [10, 325, 615, 61.5, '°C'];
        yield 'MaxLeistungWW' => [10, 345, 800, 80.0, '%'];
        yield 'GedaempfteAussentemperatur' => [10, 2572, -12, -1.2, '°C'];
        yield 'RaumfrosttemperaturHK negativ' => [6, 2580, -50, -5.0, null];
        yield 'T1Kollektor' => [10, 2601, 854, 85.4, '°C'];
        yield 'T2SolarUnten' => [10, 2602, 421, 42.1, '°C'];
        yield 'VorlauftemperaturEstb' => [10, 3101, 451, 45.1, '°C'];
        // divided by 100
        yield 'Durchfluss' => [10, 130, 1234, 12.34, 'l/min'];
        yield 'LeistungSolar' => [10, 475, 2500, 25.0, 'W'];
        // taken as is (low byte only)
        yield 'Fehlercode' => [0, 1, 0, 0.0, null];
        yield 'Laststellung' => [10, 138, 57, 57.0, '%'];
        yield 'BetriebsartHK' => [6, 274, 3, 3.0, null];
        yield 'Betriebsphase' => [10, 373, 7, 7.0, null];
        yield 'Password' => [10, 5056, 42, 42.0, null];
        yield 'StartsiteFooter' => [10, 5066, 9, 9.0, null];
    }

    #[DataProvider('scaledValues')]
    public function testValueIsScaledAccordingToInfoNumber(int $modultyp, int $info, int $raw, float $expected, ?string $unit): void
    {
        [$result] = $this->decode([self::telegram($modultyp, $info, $raw)]);

        $this->assertEqualsWithDelta($expected, $result->DATA, 1e-9);
        $this->assertSame($info, $result->INFONR);
        $this->assertSame($unit, $result->UNIT ?? null);
    }

    public function testDecodedTelegramKeepsAddressFields(): void
    {
        [$result] = $this->decode([[12, 3, 1, 15, 0, 0, 96, 1]]);

        $this->assertSame(3, $result->BUSKENNUNG);
        $this->assertSame(1, $result->COMMAND);
        $this->assertSame(0, $result->INDEX);
        $this->assertSame(0, $result->PROT);
        $this->assertSame(1, $result->HIGH_BYTE);
        $this->assertEqualsWithDelta(35.2, $result->DATA, 1e-9);
    }

    public function testDecodedTelegramKeepsModultyp(): void
    {
        [$result] = $this->decode([[12, 3, 1, 15, 0, 0, 96, 1]]);

        $this->assertSame(12, $result->MODULTYP);
    }

    public function testDecodesSeveralTelegramsInOrder(): void
    {
        $results = $this->decode([
            self::telegram(10, 12, -35),
            self::telegram(10, 138, 57),
            self::telegram(3, 118, 551),
        ]);

        $this->assertSame([12, 138, 118], array_map(fn ($t) => $t->INFONR, $results));
    }

    public function testUnknownInfoNumberThrows(): void
    {
        $this->expectExceptionMessage('Unknown Info: 9999');
        $this->decode([self::telegram(10, 9999, 1)]);
    }

    public function testEmptyAnswerThrows(): void
    {
        $this->expectExceptionMessage('Invalid WCM response. Response is empty.');
        $this->decode([]);
    }

    public function testAnswerWithoutTelegramsThrows(): void
    {
        $this->expectExceptionMessage('Invalid WCM response. Response is empty.');
        self::callPrivate($this->api, '_decodeTelegram', '{"prot":"coco"}');
    }

    public function testInvalidJsonIsReportedAsJsonError(): void
    {
        $this->expectException(\JsonException::class);
        self::callPrivate($this->api, '_decodeTelegram', '{"prot":"coco","telegramm":[[10,0,1');
    }

    /** The WCM answers with COMMAND 255 (ERROR) if it has no value for a parameter. */
    public function testErrorTelegramIsMarkedAndCarriesNoValue(): void
    {
        [$result] = $this->decode([self::telegram(10, 12, 123, command: 255)]);

        $this->assertTrue($result->ERROR);
        $this->assertNull($result->DATA);
        $this->assertSame(12, $result->INFONR);
    }

    public function testErrorTelegramWithUnknownInfoNumberDoesNotThrow(): void
    {
        [$result] = $this->decode([self::telegram(10, 9999, 0, command: 255)]);

        $this->assertTrue($result->ERROR);
    }

    public function testRegularTelegramIsNotMarkedAsError(): void
    {
        [$result] = $this->decode([self::telegram(10, 12, -35)]);

        $this->assertFalse($result->ERROR);
        $this->assertFalse($result->NOT_AVAILABLE);
    }

    public static function notAvailableValues(): iterable
    {
        // Without detection these were shown as 3276.8 / 327.68
        yield 'Aussentemperatur (/10)' => [10, 12];
        yield 'B10PufferOben (/10)' => [3, 118];
        yield 'VorlauftemperaturHK (/10)' => [12, 15];
        yield 'SteilheitHK (/10)' => [6, 270];
        yield 'Durchfluss (/100)' => [10, 130];
        yield 'LeistungSolar (/100)' => [10, 475];
    }

    #[DataProvider('notAvailableValues')]
    public function testRawValue0x8000IsReportedAsNotAvailable(int $modultyp, int $info): void
    {
        [$result] = $this->decode([[$modultyp, 0, 1, $info, 0, 0, 0, 128]]);

        $this->assertTrue($result->NOT_AVAILABLE);
        $this->assertNull($result->DATA);
        $this->assertFalse($result->ERROR);
    }

    public function testNeighboursOf0x8000AreRegularValues(): void
    {
        $results = $this->decode([
            [10, 0, 1, 12, 0, 0, 1, 128],     // 0x8001 = -32767
            [10, 0, 1, 12, 0, 0, 255, 127],   // 0x7FFF = 32767
        ]);

        $this->assertSame([-3276.7, 3276.7], [$results[0]->DATA, $results[1]->DATA]);
        $this->assertSame([false, false], [$results[0]->NOT_AVAILABLE, $results[1]->NOT_AVAILABLE]);
    }

    /** 8-bit values only use the low byte, so the 0x8000 marker does not apply. */
    public function testEightBitValueIgnoresTheMarker(): void
    {
        [$result] = $this->decode([[10, 0, 1, 138, 0, 0, 0, 128]]);

        $this->assertSame(0.0, $result->DATA);
        $this->assertFalse($result->NOT_AVAILABLE);
    }

    public static function malformedTelegrams(): iterable
    {
        yield 'missing field' => [[10, 0, 1, 12, 0, 0, 200]];
        yield 'extra field' => [[10, 0, 1, 12, 0, 0, 200, 0, 0]];
        yield 'string value' => [[10, 0, 1, 12, 0, 0, '200', 0]];
        yield 'float value' => [[10, 0, 1, 12, 0, 0, 2.5, 0]];
        yield 'null value' => [[10, 0, 1, 12, 0, 0, null, 0]];
        yield 'object instead of list' => [['a' => 1]];
        yield 'number instead of list' => [12];
    }

    /** Must be an \Exception (not an \Error), so retries and the module's catch handle it. */
    #[DataProvider('malformedTelegrams')]
    public function testMalformedTelegramThrowsException(mixed $telegram): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid telegram in WCM response');
        $this->decode([$telegram]);
    }

    public static function rawValues(): iterable
    {
        yield 'zero' => [0, 0, 0];
        yield 'low byte only' => [255, 0, 255];
        yield 'high byte only' => [0, 1, 256];
        yield 'max positive' => [255, 127, 32767];
        yield 'minus one' => [255, 255, -1];
        yield 'minus 35' => [221, 255, -35];
        yield 'min + 1' => [1, 128, -32767];
        // 0x8000 is shown as "N/V" (not available) on the heating system
        yield '0x8000 is N/V' => [0, 128, null];
    }

    #[DataProvider('rawValues')]
    public function testExtractValueCombinesLowAndHighByte(int $low, int $high, ?int $expected): void
    {
        $this->assertSame($expected, self::callPrivate($this->api, '_extractValue', $low, $high));
    }

    public function testEncodingAndDecodingAreInverseForTheWholeRange(): void
    {
        for ($value = -32767; $value <= 32767; $value++) {
            $low = self::callPrivate($this->api, '_calcLowByte', $value);
            $high = self::callPrivate($this->api, '_calcHighByte', $value);
            if (self::callPrivate($this->api, '_extractValue', $low, $high) !== $value) {
                $this->fail("Round trip failed for $value");
            }
        }
        $this->addToAssertionCount(1);
    }
}
