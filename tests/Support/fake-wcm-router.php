<?php

/**
 * Router script for `php -S`, simulating the WCM-COM /parameter.json endpoint.
 * Controlled by files in the directory given by FAKE_WCM_STATE (see FakeWcm).
 */

declare(strict_types=1);

$stateDir = getenv('FAKE_WCM_STATE');
$scenario = json_decode(file_get_contents("$stateDir/scenario.json"), true);
$realm = 'WCM-COM';

function reply(int $status, string $body, array $headers = []): void
{
    http_response_code($status);
    foreach ($headers as $header) {
        header($header);
    }
    header('Content-Type: application/json');
    echo $body;
}

function digestIsValid(string $header, array $scenario, string $realm): bool
{
    preg_match_all('/(\w+)=(?:"([^"]*)"|([^\s,]+))/', $header, $matches, PREG_SET_ORDER);
    $p = [];
    foreach ($matches as $match) {
        $p[$match[1]] = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');
    }

    foreach (['username', 'nonce', 'uri', 'response', 'nc', 'cnonce', 'qop'] as $required) {
        if (!isset($p[$required])) {
            return false;
        }
    }
    if ($p['username'] !== $scenario['username']) {
        return false;
    }

    $ha1 = md5("{$scenario['username']}:$realm:{$scenario['password']}");
    $ha2 = md5("{$_SERVER['REQUEST_METHOD']}:{$p['uri']}");

    return hash_equals(md5("$ha1:{$p['nonce']}:{$p['nc']}:{$p['cnonce']}:{$p['qop']}:$ha2"), $p['response']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) !== '/parameter.json') {
    reply(404, 'not found');

    return true;
}

if ($scenario['auth']) {
    $authorization = getallheaders()['Authorization'] ?? '';
    if (!str_starts_with($authorization, 'Digest ') || !digestIsValid(substr($authorization, 7), $scenario, $realm)) {
        $nonce = bin2hex(random_bytes(8));
        reply(401, '', ["WWW-Authenticate: Digest realm=\"$realm\", qop=\"auth\", nonce=\"$nonce\", opaque=\"5ccc069c\""]);

        return true;
    }
}

$request = json_decode(file_get_contents('php://input'), true);
$log = "$stateDir/requests.jsonl";
file_put_contents($log, json_encode($request) . "\n", FILE_APPEND | LOCK_EX);
$requestIndex = count(file($log, FILE_SKIP_EMPTY_LINES)) - 1;
$override = $scenario['responses'][$requestIndex] ?? [];

if (isset($override['status']) || isset($override['body'])) {
    reply($override['status'] ?? 200, $override['body'] ?? '');

    return true;
}

// Answer from the simulated device
$device = json_decode(file_get_contents("$stateDir/device.json"), true);
$telegrams = [];
foreach ($request['telegramm'] as $telegram) {
    $key = "{$telegram[0]}/{$telegram[1]}/{$telegram[3]}";
    if ($telegram[2] === 2) {
        $device[$key] = $telegram[6] | ($telegram[7] << 8);
    } else {
        $raw = ($device[$key] ?? 0) & 0xFFFF;
        $telegram[6] = $raw & 0xFF;
        $telegram[7] = ($raw >> 8) & 0xFF;
    }
    $telegrams[] = $telegram;
}
file_put_contents("$stateDir/device.json", json_encode((object) $device));

// Optional modifications to provoke faulty answers
if (isset($override['truncate'])) {
    $telegrams = array_slice($telegrams, 0, $override['truncate']);
}
if (isset($override['command'])) {
    foreach ($telegrams as &$telegram) {
        $telegram[2] = $override['command'];
    }
    unset($telegram);
}
foreach ($override['set'] ?? [] as $index => $fields) {
    foreach ($fields as $field => $value) {
        $telegrams[$index][$field] = $value;
    }
}
if (isset($override['removeField'])) {
    [$index, $field] = $override['removeField'];
    unset($telegrams[$index][$field]);
    $telegrams[$index] = array_values($telegrams[$index]);
}
if (isset($override['appendTelegram'])) {
    $telegrams[] = $override['appendTelegram'];
}
if (isset($override['appendField'])) {
    [$index, $value] = $override['appendField'];
    $telegrams[$index][] = $value;
}

// "pretty": answer formatted like a device that pretty-prints its JSON
$body = json_encode(['prot' => 'coco', 'telegramm' => $telegrams], ($override['pretty'] ?? false) ? JSON_PRETTY_PRINT : 0);
reply(200, $body);

return true;
