<?php
include __DIR__ . '/constants.php';

class WeishauptOptions {
    public string $url;
    public string $username;
    public string $password;
    /** Seconds to wait for the TCP connection */
    public float $connectTimeout;
    /** Seconds one HTTP request may take in total (including the connection) */
    public float $requestTimeout;
    /** Telegrams per HTTP request (1-9) */
    public int $blockSize;
    /** Seconds to wait between two blocks, gives the WCM-COM time for its eBus queue */
    public float $blockPause;
    
    public function __construct(string $url, string $username, string $password, float $connectTimeout = 3.0, float $requestTimeout = 15.0, int $blockSize = 9, float $blockPause = 0.0) {
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
        $this->connectTimeout = $connectTimeout;
        $this->requestTimeout = $requestTimeout;
        $this->blockSize = max(1, min(9, $blockSize));
        $this->blockPause = max(0.0, $blockPause);
    }
}

/** The WCM-COM did not answer at all (connection failed, timeout). Not retried. */
class WeishauptConnectionException extends Exception {}

/** The WCM-COM did not answer in time. */
class WeishauptTimeoutException extends WeishauptConnectionException {}

/** No TCP connection within the connect timeout. */
class WeishauptConnectTimeoutException extends WeishauptTimeoutException {}

/** The request was sent, but the answer did not arrive within the request timeout. */
class WeishauptResponseTimeoutException extends WeishauptTimeoutException {}

/** The WCM-COM answered with an HTTP status other than 200. */
class WeishauptHttpException extends Exception {
    public int $httpCode;

    public function __construct(int $httpCode, string $message) {
        parent::__construct($message);
        $this->httpCode = $httpCode;
    }
}

/** The WCM-COM answered "server is busy". */
class WeishauptBusyException extends Exception {}

/**
 * The answer does not fit the request: "incomplete" (fewer telegrams),
 * "mismatch" (other or additional telegrams) or "invalid" (no telegram list,
 * malformed telegram). Invalid JSON is reported as JsonException.
 */
class WeishauptResponseException extends Exception {
    public string $kind;

    public function __construct(string $kind, string $message) {
        parent::__construct($message);
        $this->kind = $kind;
    }
}

/** sendBuffer() did not start the next block because the time limit for new blocks was reached. */
class WeishauptTooSlowException extends Exception {}

class TelegramObject {
    public int $MODULTYP;
    public int $BUSKENNUNG;
    public int $COMMAND;
    public int $INFONR;
    public int $INDEX;
    public int $PROT;
    public int $DATA;
    public int $HIGH_BYTE;
}

class FinalTelegramObject {
    public int $MODULTYP;
    public int $BUSKENNUNG;
    public int $COMMAND;
    public int $PROT;
    public int $INFONR;
    public int $INDEX;
    /** Decoded value, null if the WCM answered with an error telegram */
    public ?float $DATA = null;
    public int $HIGH_BYTE;
    public string $UNIT;
    /** True if the WCM answered this telegram with COMMAND ERROR (255) */
    public bool $ERROR = false;
    /** True if the WCM reports the value as "N/V" (not available, raw 0x8000) */
    public bool $NOT_AVAILABLE = false;
}

class TelegramObjectCollection implements IteratorAggregate {
    protected array $items = [];

    public function add(TelegramObject $telegramObject) : void {
        $this->items[] = $telegramObject;
    }

    public function getIterator() : Traversable {
        return new ArrayIterator($this->items);
    }
}

class FinalTelegramObjectCollection implements IteratorAggregate {
    protected array $items = [];

    public function add(FinalTelegramObject $telegramObject) : void {
        $this->items[] = $telegramObject;
    }
    
    public function addCollection(FinalTelegramObjectCollection $telegramObjectCollection): void {
        $this->items = array_merge($this->items, $telegramObjectCollection->getIterator()->getArrayCopy());
    }
    
    public function getIterator() : Traversable {
        return new ArrayIterator($this->items);
    }
}

class Weishaupt {
    /** URL of the API */
    private string $url;
    private string $username;
    private string $password;
    private float $connectTimeout;
    private float $requestTimeout;
    private int $blockSize;
    private float $blockPause;
    
    private array $telegramRequestBuffer = [];

    /**
     * One curl handle for all requests of this object. curl keeps the connection
     * (if the WCM-COM allows keep-alive) and the digest nonce, so only the first
     * request needs the 401 challenge round trip.
     * @var CurlHandle|resource|null
     */
    private $curl = null;

    /** Statistics of the last sendBuffer() call, see getLastStats() */
    private array $lastStats = [];

    /** HTTP responses seen by the curl handle (own object, so the header callback doesn't reference $this) */
    private stdClass $responseCounter;

    public function __construct(WeishauptOptions $options) {
        $this->url = $options->url;
        $this->username = $options->username;
        $this->password = $options->password;
        $this->connectTimeout = $options->connectTimeout;
        $this->requestTimeout = $options->requestTimeout;
        $this->blockSize = $options->blockSize;
        $this->blockPause = $options->blockPause;
        $this->lastStats = self::emptyStats(0, 0);
        $this->responseCounter = new stdClass();
        $this->responseCounter->count = 0;
    }

    /**
     * Statistics of the last sendBuffer() call:
     * telegrams, blocks (planned), sent_blocks (answered successfully),
     * http_requests (including 401 challenges and retries), connections (new
     * TCP connections), duration (seconds, including pauses between blocks),
     * block_durations (seconds per started block, including retries),
     * failed_block (1-based, null if all blocks succeeded), and http_code and
     * body of the last answer (body null if there was no answer).
     */
    public function getLastStats(): array {
        return $this->lastStats;
    }

    /**
     * Releases the curl handle (and with it the connection and the digest nonce).
     */
    public function close(): void {
        $this->curl = null;
    }

    private static function emptyStats(int $telegrams, int $blocks): array {
        return [
            'telegrams' => $telegrams,
            'blocks' => $blocks,
            'sent_blocks' => 0,
            'http_requests' => 0,
            'connections' => 0,
            'duration' => 0.0,
            'block_durations' => [],
            'failed_block' => null,
            'http_code' => 0,
            'body' => null,
        ];
    }

    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestRuecklauftemperatur(): int {
        $telegram = [10, 0, Operation["Lesen"], Info["Ruecklauftemperatur"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestWarmwassertemperatur(): int {
        $telegram = [10, 0, Operation["Lesen"], Info["Warmwassertemperatur"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestLaststellung(): int {
        $telegram = [10, 0, Operation["Lesen"], Info["Laststellung"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestMaxLeistungHeizung(): int {
        $telegram = [10, 0, Operation["Lesen"], Info["MaxLeistungHeizung"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestMaxLeistungWW(): int {
        $telegram = [10, 0, Operation["Lesen"], Info["MaxLeistungWW"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestFehlercode(): int {
        if(count($this->telegramRequestBuffer) > 0) {
            throw new Exception("'Fehlercode' needs to be the first telegram.");
        }
        
        $telegram = [0, 0, Operation["Lesen"], Info["Fehlercode"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestAbgastemperatur(): int {
        $telegram = [10, 0, Operation["Lesen"], Info["Abgastemperatur"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestWaermeanforderung(): int {
        $telegram = [10, 0, Operation["Lesen"], Info["Waermeanforderung"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestAussentemperatur(): int {
        $telegram = [10, 0, Operation["Lesen"], Info["Aussentemperatur"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestGedaempfteAussentemperatur(): int {
        $telegram = [10, 0, Operation["Lesen"], Info["GedaempfteAussentemperatur"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestVorlauftemperatur(): int {
        $telegram = [10, 0, Operation["Lesen"], Info["Vorlauftemperatur"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestVorlauftemperaturEstb(): int {
        $telegram = [10, 0, Operation["Lesen"], Info["VorlauftemperaturEstb"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestB10PufferOben(): int {
        $telegram = [3, 0, Operation["Lesen"], Info["B10PufferOben"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestB11PufferUnten(): int {
        $telegram = [3, 0, Operation["Lesen"], Info["B11PufferUnten"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestBetriebsartHK(int $heizkreis): int {
        $telegram = [6, ($heizkreis - 1), Operation["Lesen"], Info["BetriebsartHK"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestVorlauftemperaturHK(int $heizkreis): int {
        $telegram = [12, ($heizkreis - 1), Operation["Lesen"], Info["VorlauftemperaturHK"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestSollTempHK(int $heizkreis): int {
        $telegram = [6, ($heizkreis - 1), Operation["Lesen"], Info["SollTempHK"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestWaermeanforderungHK(int $heizkreis): int {
        $telegram = [6, ($heizkreis - 1), Operation["Lesen"], Info["Waermeanforderung"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestNormalRaumtemperaturHK(int $heizkreis): int {
        $telegram = [6, ($heizkreis - 1), Operation["Lesen"], Info["NormalRaumtemperaturHK"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestSteilheitHK(int $heizkreis): int {
        $telegram = [6, ($heizkreis - 1), Operation["Lesen"], Info["SteilheitHK"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestRaumfrosttemperaturHK(int $heizkreis): int {
        $telegram = [6, ($heizkreis - 1), Operation["Lesen"], Info["RaumfrosttemperaturHK"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
     * Adds a new telegram to the buffer and returns the buffer position
     */
    public function bufferedRequestSoWiUmschalttemperaturHK(int $heizkreis): int {
        $telegram = [6, ($heizkreis - 1), Operation["Lesen"], Info["SoWiUmschalttemperaturHK"], 0, 0, 0, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
    * Adds a new telegram to the buffer and returns the buffer position
    */
    public function bufferedUpdateBetriebsartHK(int $heizkreis, int $betriebsart): int {
        if(!in_array($betriebsart, BetriebsartHK, true)) {
            throw new Exception("Invalid Betriebsart ".$betriebsart.", valid values are ".implode(", ", BetriebsartHK));
        }
        
        $telegram = [6, ($heizkreis - 1), Operation["Schreiben"], Info["BetriebsartHK"], 0, 0, $betriebsart, 0];

        return $this->addBuffer($telegram);
    }
    
    /**
    * Adds a new telegram to the buffer and returns the buffer position
    */
    public function bufferedUpdateMaxLeistungHeizung(int $maxLeistung): int {
        if($maxLeistung > 100 || $maxLeistung < 36) {
            throw new Exception("Value outside of valid range of 36% - 100%");
        }
        
        $telegram = [10, 0, Operation["Schreiben"], Info["MaxLeistungHeizung"], 0, 0, ...$this->_encodeTenths($maxLeistung)];

        return $this->addBuffer($telegram);
    }
    
    /**
    * Adds a new telegram to the buffer and returns the buffer position
    */
    public function bufferedUpdateMaxLeistungWW(int $maxLeistung): int {
        if($maxLeistung > 100 || $maxLeistung < 36) {
            throw new Exception("Value outside of valid range of 36% - 100%");
        }
        
        $telegram = [10, 0, Operation["Schreiben"], Info["MaxLeistungWW"], 0, 0, ...$this->_encodeTenths($maxLeistung)];

        return $this->addBuffer($telegram);
    }
    
    /**
    * Adds a new telegram to the buffer and returns the buffer position
    */
    public function bufferedUpdateNormalRaumtemperaturHK(int $heizkreis, float $normalRaumtemperatur): int {
        if($normalRaumtemperatur > 30 || $normalRaumtemperatur < 0) {
            throw new Exception("Value outside of valid range of 0 - 30");
        }
        
        $telegram = [6, ($heizkreis - 1), Operation["Schreiben"], Info["NormalRaumtemperaturHK"], 0, 0, ...$this->_encodeTenths($normalRaumtemperatur)];

        return $this->addBuffer($telegram);
    }
    
    /**
    * Adds a new telegram to the buffer and returns the buffer position
    */
    public function bufferedUpdateSteilheitHK(int $heizkreis, float $steilheit): int {
        if($steilheit > 40 || $steilheit < 2.5) {
            throw new Exception("Value outside of valid range of 2.5 - 40");
        }
        
        $telegram = [6, ($heizkreis - 1), Operation["Schreiben"], Info["SteilheitHK"], 0, 0, ...$this->_encodeTenths($steilheit)];

        return $this->addBuffer($telegram);
    }
    
    /**
    * Adds a new telegram to the buffer and returns the buffer position
    */
    public function bufferedUpdateRaumfrosttemperaturHK(int $heizkreis, float $raumfrosttemperatur): int {
        if($raumfrosttemperatur > 30 || $raumfrosttemperatur < -10) {
            throw new Exception("Value outside of valid range of -10 - 30");
        }
        
        $telegram = [6, ($heizkreis - 1), Operation["Schreiben"], Info["RaumfrosttemperaturHK"], 0, 0, ...$this->_encodeTenths($raumfrosttemperatur)];

        return $this->addBuffer($telegram);
    }
    
    /**
    * Adds a new telegram to the buffer and returns the buffer position
    */
    public function bufferedUpdateSoWiUmschalttemperaturHK(int $heizkreis, float $temperatur): int {
        if($temperatur > 30 || $temperatur < -10) {
            throw new Exception("Value outside of valid range of -10 - 30");
        }
        
        $telegram = [6, ($heizkreis - 1), Operation["Schreiben"], Info["SoWiUmschalttemperaturHK"], 0, 0, ...$this->_encodeTenths($temperatur)];

        return $this->addBuffer($telegram);
    }
    
    private function addBuffer(array $telegram): int {
        $len = array_push($this->telegramRequestBuffer, $telegram);
        
        return $len - 1;
    }
    
    /**
     * Sends buffered telegrams to WCM-COM (Reads and Updates)
     *
     * Blocks are sent one after the other, never in parallel, with the
     * configured pause in between. A block that fails with an HTTP error,
     * "server is busy" or an invalid answer is sent again after a pause of
     * 1-2 s, up to $NUM_OF_ATTEMPTS times in total. If the WCM-COM does not
     * answer at all (connection error, timeout), there is no further attempt:
     * it would only add load to a device that already struggles.
     *
     * A running request is never cut short (only by the request timeout), so
     * the WCM-COM does not keep working on a request the client gave up.
     * Instead, no further request is started once $newRequestLimit seconds
     * have passed.
     *
     * @param int $NUM_OF_ATTEMPTS attempts per block
     * @param float|null $newRequestLimit seconds after which no further request is started, null for no limit
     * @throws WeishauptConnectionException if the WCM-COM did not answer (WeishauptTimeoutException: in time)
     * @throws WeishauptHttpException, WeishauptBusyException, WeishauptResponseException, JsonException if it answered with an error
     * @throws WeishauptTooSlowException if $newRequestLimit was reached
     */
    public function sendBuffer($NUM_OF_ATTEMPTS = 3, ?float $newRequestLimit = null): FinalTelegramObjectCollection {
        $chunks = array_chunk($this->telegramRequestBuffer, $this->blockSize);
        $finalRes = new FinalTelegramObjectCollection();

        $start = microtime(true);
        $this->lastStats = self::emptyStats(count($this->telegramRequestBuffer), count($chunks));
        $responsesBefore = $this->responseCounter->count;

        try {
            foreach($chunks as $blockIndex => $chunk) {
                if($blockIndex > 0 && $this->blockPause > 0) {
                    usleep((int) round($this->blockPause * 1000000));
                }

                $body = [
                    "prot" => "coco",
                    "telegramm" => $chunk
                ];

                $blockStart = microtime(true);
                $attempts = 0;
                $lastError = null;
                try {
                    do {
                        if($newRequestLimit !== null && microtime(true) - $start >= $newRequestLimit) {
                            // Report the actual problem if a retry ran out of time
                            throw $lastError ?? new WeishauptTooSlowException(sprintf("No further request after %.1f s, %d of %d blocks answered.", $newRequestLimit, $this->lastStats['sent_blocks'], count($chunks)));
                        }

                        try {
                            $res = $this->_callAPI("POST", $this->url."/parameter.json", $body, $this->requestTimeout);
                            $this->lastStats['http_code'] = (int) $res["http_code"];
                            $this->lastStats['body'] = empty($res["curl_error"]) ? $res["body"] : null;

                            // Throw exception if http or curl error occurred
                            if ($res["http_code"] != 200) {
                                if(!empty($res["curl_error"])) {
                                    $message = "CURL error occurred: ".$res["curl_error"];
                                    if($res["curl_errno"] == CURLE_OPERATION_TIMEDOUT) {
                                        // Before the request could be sent: the connection was not established
                                        if($res["request_sent"]) {
                                            throw new WeishauptResponseTimeoutException($message);
                                        }
                                        throw new WeishauptConnectTimeoutException($message);
                                    }
                                    throw new WeishauptConnectionException($message);
                                } else {
                                    throw new WeishauptHttpException((int) $res["http_code"], "HTTP return code ".$res["http_code"]."\n".$res["body"]);
                                }
                            }

                            // If WCM-COM server is busy and doesn't return a response
                            if(stripos($res["body"], "server is busy") !== false) {
                                throw new WeishauptBusyException("WCM-COM server is busy.");
                            } else {
                                $resTelegramCol = $this->_decodeTelegram($res["body"]);
                                $this->_verifyResponse($chunk, $resTelegramCol);
                                $finalRes->addCollection($resTelegramCol);
                                $this->lastStats['sent_blocks']++;
                            }
                        } catch(WeishauptConnectionException $e) {
                            throw $e;
                        } catch(Exception $e) {
                            $attempts++;
                            if($attempts >= $NUM_OF_ATTEMPTS) {
                                throw $e;
                            }
                            $lastError = $e;
                            usleep(rand(500000, 1000000));
                            usleep(rand(500000, 1000000));
                            continue;
                        }

                        break;
                    } while(true);
                } catch(Exception $e) {
                    $this->lastStats['failed_block'] = $blockIndex + 1;
                    throw $e;
                } finally {
                    $this->lastStats['block_durations'][] = microtime(true) - $blockStart;
                }
            }
        } finally {
            $this->lastStats['duration'] = microtime(true) - $start;
            $this->lastStats['http_requests'] = $this->responseCounter->count - $responsesBefore;
        }
        
        return $finalRes;
    }
    
    /**
     * Checks that the WCM answered every request telegram of a chunk, in order.
     * Results are mapped to buffer positions, so a missing telegram would shift
     * all following values onto the wrong parameters.
     */
    private function _verifyResponse(array $requests, FinalTelegramObjectCollection $responses): void {
        $responses = $responses->getIterator()->getArrayCopy();

        if(count($responses) !== count($requests)) {
            throw new WeishauptResponseException(count($responses) < count($requests) ? "incomplete" : "mismatch", "WCM response contains ".count($responses)." telegrams, expected ".count($requests).".");
        }

        foreach($responses as $pos => $telegram) {
            if($telegram->INFONR != $requests[$pos][3]) {
                throw new WeishauptResponseException("mismatch", "WCM response doesn't match the requests!");
            }
        }
    }

    /**
     * Clears the telegram buffer
     */
    public function clearBuffer() {
        $this->telegramRequestBuffer = [];
    }
    
    /**
     * Decodes a Telegram given from API
     * @param body telegram as given as response from API
     */
    private function _decodeTelegram(string $body): FinalTelegramObjectCollection {
        $response = new TelegramObjectCollection();

        $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);

        if(!isset($decoded->telegramm) || !is_array($decoded->telegramm) || empty($decoded->telegramm)) {
            throw new WeishauptResponseException("invalid", "Invalid WCM response. Response is empty.");
        }

        foreach($decoded->telegramm as $telegramEntry) {
            // Every telegram must consist of exactly one integer per field in Type
            if(!is_array($telegramEntry)
                || count($telegramEntry) !== count(Type)
                || count(array_filter($telegramEntry, 'is_int')) !== count(Type)) {
                throw new WeishauptResponseException("invalid", "Invalid telegram in WCM response: ".json_encode($telegramEntry));
            }

            $respObj = new TelegramObject();
            foreach($telegramEntry as $i => $value) {
                $attributeName = array_search($i, Type);
                $respObj->{$attributeName} = $value;
            }
            $response->add($respObj);
        }

        return $this->_decodeTelegramValues($response);
    }

    /**
     * Decodes the values of an array of telegramObjects, then returns an array of FinalTelegramObjects
     *
     * @param telegramObjects Array matching the interface
     */
    private function _decodeTelegramValues(TelegramObjectCollection $telegramObjects): FinalTelegramObjectCollection {
        $finalTelegramObjects = new FinalTelegramObjectCollection();
        foreach ($telegramObjects as $telegramObject) {
            $finalTelegramObj = new FinalTelegramObject();
            $finalTelegramObj->COMMAND = $telegramObject->COMMAND;
            $finalTelegramObj->MODULTYP = $telegramObject->MODULTYP;
            if($telegramObject->COMMAND == Command["ERROR"]) {
                // The WCM rejected this telegram, DATA carries no value
                $finalTelegramObj->ERROR = true;
            } else {
                $finalTelegramObj->DATA = $this->_convertData($telegramObject);
                $finalTelegramObj->NOT_AVAILABLE = ($finalTelegramObj->DATA === null);
            }
            $finalTelegramObj->BUSKENNUNG = $telegramObject->BUSKENNUNG;
            $finalTelegramObj->PROT = $telegramObject->PROT;
            $finalTelegramObj->INDEX = $telegramObject->INDEX;
            $finalTelegramObj->INFONR = $telegramObject->INFONR;
            $finalTelegramObj->HIGH_BYTE = $telegramObject->HIGH_BYTE;
            
            $enumName = array_search($finalTelegramObj->INFONR, Info);
            $match = array_key_exists($enumName, Unit);

            if($match !== false) {
                $finalTelegramObj->UNIT = Unit[$enumName];
            }
            
            $finalTelegramObjects->add($finalTelegramObj);
        }

        return $finalTelegramObjects;
    }

    /**
     * Data is extracted and converted according to its INFONR field
     *
     * @param telegramObject a single telegramObject
     * @return null if the WCM reports the value as "N/V"
     */
    private function _convertData(TelegramObject $telegramObject): ?float {
        switch ($telegramObject->INFONR) {
            case Info["VorlauftemperaturEstb"]:
            case Info["GedaempfteAussentemperatur"]:
            case Info["Waermeanforderung"]:
            case Info["Aussentemperatur"]:
            case Info["Warmwassertemperatur"]:
            case Info["Ruecklauftemperatur"]:
            case Info["Abgastemperatur"]:
            case Info["Vorlauftemperatur"]:
            case Info["T2SolarUnten"]:
            case Info["B11PufferUnten"]:
            case Info["B10PufferOben"]:
            case Info["T1Kollektor"]:
            case Info["MaxLeistungHeizung"]:
            case Info["MaxLeistungWW"]:
            case Info["SollTempHK"]:
            case Info["NormalRaumtemperaturHK"]:
            case Info["VorlauftemperaturHK"]:
            case Info["SoWiUmschalttemperaturHK"]:
            case Info["RaumfrosttemperaturHK"]:
            case Info["SteilheitHK"]:
                $val = $this->_extractValue($telegramObject->DATA, $telegramObject->HIGH_BYTE);
                return $val === null ? null : $val / 10;
            case Info["LeistungSolar"]:
            case Info["Durchfluss"]:
                $val = $this->_extractValue($telegramObject->DATA, $telegramObject->HIGH_BYTE);
                return $val === null ? null : $val / 100;
            case Info["Fehlercode"]:
            case Info["Password"]:
            case Info["StartsiteFooter"]:
            case Info["Laststellung"]:
            case Info["Betriebsphase"]:
            case Info["BetriebsartHK"]:
                return $telegramObject->DATA;
            default:
                throw new Exception("Unknown Info: {$telegramObject->INFONR}");
        }
    }

    /**
     * Calculate the signed 16-bit value from the low byte and high byte
     *
     * @param lowByte
     * @param highByte
     * @return null for 0x8000, which the WCM uses for "N/V" (not available)
     */
    private function _extractValue(int $lowByte, int $highByte): ?int {
        if ($highByte === 128 && $lowByte === 0) {
            return null;
        }

        if ($highByte <= 127) {
            return $highByte * 256 + $lowByte;
        }

        return -32768 + ($highByte - 128) * 256 + $lowByte;
    }
    
    /**
     * Encodes a value with one decimal as [low byte, high byte] of value * 10.
     * Rounds to one decimal, e.g. 21.26 -> 213 (casting would cut off to 212).
     */
    private function _encodeTenths(float $value): array {
        $raw = (int) round($value * 10);
        
        return [$this->_calcLowByte($raw), $this->_calcHighByte($raw)];
    }
    
    /**
     * Calculate the low byte of a value
     *
     * @param value
     */
    private function _calcLowByte(int $value): int {
        return $value & 0xff;
    }
    
    /**
     * Calculate the high byte of a value
     *
     * @param value
     */
    private function _calcHighByte(int $value): int {
        return ($value >> 8) & 0xff;
    }
    
    /**
     * Sends one request on the shared curl handle.
     *
     * @param string $method POST, everything else is sent as GET
     * @param mixed $data POST: sent as JSON body, GET: appended as query string
     * @param float $timeout seconds this request may take in total
     */
    private function _callAPI(string $method, string $url, $data, float $timeout): array {
        if($this->curl === null) {
            $this->curl = curl_init();
            curl_setopt($this->curl, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
            curl_setopt($this->curl, CURLOPT_USERPWD, $this->username.":".$this->password);
            curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
            // Timeouts below one second need curl to work without signals
            curl_setopt($this->curl, CURLOPT_NOSIGNAL, 1);
            // No "Expect: 100-continue" round trip for larger bodies
            curl_setopt($this->curl, CURLOPT_HTTPHEADER, ["Expect:"]);
            // Count every HTTP response, including the 401 digest challenges
            $counter = $this->responseCounter;
            curl_setopt($this->curl, CURLOPT_HEADERFUNCTION, static function($curl, string $header) use ($counter): int {
                if(preg_match('#^HTTP/\S+\s+[2-9]\d\d#', $header)) {
                    $counter->count++;
                }
                return strlen($header);
            });
        }
        $curl = $this->curl;

        if($method === "POST") {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $data ? json_encode($data) : "");
        } else {
            curl_setopt($curl, CURLOPT_HTTPGET, 1);
            if ($data)
                $url = sprintf("%s?%s", $url, http_build_query($data));
        }

        $timeoutMs = max(1, (int) round($timeout * 1000));
        curl_setopt($curl, CURLOPT_TIMEOUT_MS, $timeoutMs);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT_MS, min($timeoutMs, max(1, (int) round($this->connectTimeout * 1000))));
        curl_setopt($curl, CURLOPT_URL, $url);

        $res = curl_exec($curl);
        $error = curl_error($curl);
        $this->lastStats['connections'] += (int) curl_getinfo($curl, CURLINFO_NUM_CONNECTS);
        
        $result = array( 'body' => '',
                         'curl_error' => '',
                         'curl_errno' => 0,
                         'request_sent' => false,
                         'http_code' => '',
                         'last_url' => '');
        if ($error != "") {
            $result['curl_error'] = $error;
            $result['curl_errno'] = curl_errno($curl);
            // PRETRANSFER is reached once the connection is up and the request goes out
            $result['request_sent'] = curl_getinfo($curl, CURLINFO_PRETRANSFER_TIME) > 0;
            return $result;
        }

        // CURLOPT_HEADER is not set, so $res contains the complete body and no headers.
        // (It must not be split at CURLINFO_HEADER_SIZE: that would cut off long answers.)
        $result['body'] = $res;
        $result['http_code'] = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $result['last_url'] = curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);

        return $result;
    }
}
?>
