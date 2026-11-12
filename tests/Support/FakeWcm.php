<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Simulated WCM-COM: a PHP built-in web server running fake-wcm-router.php.
 *
 * The server keeps a small "device" (raw 16-bit values per
 * MODULTYP/BUSKENNUNG/INFONR), answers read telegrams from it, applies write
 * telegrams to it and records every authenticated request. Single responses
 * can be overridden per scenario to provoke errors.
 */
final class FakeWcm
{
    public const USERNAME = 'wcm';
    public const PASSWORD = 'secret';

    /** @var resource|null */
    private static $process = null;
    private static int $port = 0;
    private static string $stateDir = '';

    public static function start(): void
    {
        if (self::$process !== null) {
            return;
        }

        self::$stateDir = sys_get_temp_dir() . '/fake-wcm-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir(self::$stateDir);
        self::reset();

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        self::$port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        self::$process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/fake-wcm-router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', self::$stateDir . '/server.log', 'a'], 2 => ['file', self::$stateDir . '/server.log', 'a']],
            $pipes,
            null,
            ['FAKE_WCM_STATE' => self::$stateDir] + getenv()
        );

        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', self::$port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);
                register_shutdown_function([self::class, 'stop']);

                return;
            }
            usleep(50000);
        }

        throw new \RuntimeException('Fake WCM-COM did not start: ' . @file_get_contents(self::$stateDir . '/server.log'));
    }

    public static function stop(): void
    {
        if (self::$process === null) {
            return;
        }
        proc_terminate(self::$process);
        proc_close(self::$process);
        self::$process = null;

        foreach (glob(self::$stateDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir(self::$stateDir);
    }

    /**
     * @param array{
     *     auth?: bool,
     *     device?: array<string, int>,
     *     responses?: array<int, array<string, mixed>>,
     * } $scenario
     *
     * device:    raw signed values keyed "MODULTYP/BUSKENNUNG/INFONR"
     * responses: per request index (0-based) either a raw reply
     *            {status?, body?} or a device reply with modifications
     *            {truncate?, command?, set?, removeField?, appendField?, appendTelegram?, pretty?}
     *            set: [telegramIndex => [fieldIndex => value]]
     */
    public static function reset(array $scenario = []): void
    {
        $scenario += [
            'auth' => true,
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
            'device' => [],
            'responses' => [],
        ];

        file_put_contents(self::$stateDir . '/scenario.json', json_encode($scenario));
        file_put_contents(self::$stateDir . '/device.json', json_encode((object) $scenario['device']));
        @unlink(self::$stateDir . '/requests.jsonl');
    }

    public static function url(): string
    {
        return 'http://127.0.0.1:' . self::$port;
    }

    /** @return list<array{prot:string, telegramm:list<list<int>>}> authenticated requests, oldest first */
    public static function requests(): array
    {
        $file = self::$stateDir . '/requests.jsonl';
        if (!is_file($file)) {
            return [];
        }

        return array_map(
            static fn (string $line) => json_decode($line, true),
            file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        );
    }

    /** @return list<list<int>> every telegram of every authenticated request */
    public static function sentTelegrams(): array
    {
        return array_merge([], ...array_column(self::requests(), 'telegramm'));
    }

    /** Current raw device value as signed 16-bit integer. */
    public static function deviceValue(string $key): ?int
    {
        $device = json_decode(file_get_contents(self::$stateDir . '/device.json'), true);
        if (!isset($device[$key])) {
            return null;
        }
        $raw = $device[$key] & 0xFFFF;

        return $raw >= 0x8000 ? $raw - 0x10000 : $raw;
    }
}
