<?php
include __DIR__ . '/constants.php';

class WeishauptOptions {
    public string $url;
    public string $username;
    public string $password;
    
    public function __construct(string $url, string $username, string $password) {
        $this->url = $url;
        $this->username = $username;
        $this->password = $password;
    }
}

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
    
    private array $telegramRequestBuffer = [];

    public function __construct(WeishauptOptions $options) {
        $this->url = $options->url;
        $this->username = $options->username;
        $this->password = $options->password;
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
     */
    public function sendBuffer($NUM_OF_ATTEMPTS = 3): FinalTelegramObjectCollection {
        $chunks = array_chunk($this->telegramRequestBuffer, 9);
        $finalRes = new FinalTelegramObjectCollection();
        
        foreach($chunks as $chunk) {
            $body = [
                "prot" => "coco",
                "telegramm" => $chunk
            ];
            
            $attempts = 0;
            do {
                try {
                    $res = $this->_callAPI("POST", $this->url."/parameter.json", $body);

                    // Throw exception if http or curl error occurred
                    if ($res["http_code"] != 200) {
                        if(!empty($res["curl_error"]))
                            throw new Exception("CURL error occurred: ".$res["curl_error"]);
                        else
                            throw new Exception("HTTP return code ".$res["http_code"]."\n".$res["body"]);
                    }

                    // If WCM-COM server is busy and doesn't return a response
                    if(stripos($res["body"], "server is busy") !== false) {
                        throw new Exception("WCM-COM server is busy.");
                    } else {
                        $resTelegramCol = $this->_decodeTelegram($res["body"]);
                        $this->_verifyResponse($chunk, $resTelegramCol);
                        $finalRes->addCollection($resTelegramCol);
                    }
                } catch(Exception $e) {
                    $attempts++;
                    usleep(rand(500000, 1000000));
                    usleep(rand(500000, 1000000));
                    if($attempts >= $NUM_OF_ATTEMPTS) {
                        throw $e;
                    }
                    continue;
                }
                
                break;
            } while(true);
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
            throw new Exception("WCM response contains ".count($responses)." telegrams, expected ".count($requests).".");
        }

        foreach($responses as $pos => $telegram) {
            if($telegram->INFONR != $requests[$pos][3]) {
                throw new Exception("WCM response doesn't match the requests!");
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
            throw new Exception("Invalid WCM response. Response is empty.");
        }

        foreach($decoded->telegramm as $telegramEntry) {
            // Every telegram must consist of exactly one integer per field in Type
            if(!is_array($telegramEntry)
                || count($telegramEntry) !== count(Type)
                || count(array_filter($telegramEntry, 'is_int')) !== count(Type)) {
                throw new Exception("Invalid telegram in WCM response: ".json_encode($telegramEntry));
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
     */
    private function _convertData(TelegramObject $telegramObject): float {
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
                return ($val / 10);
            case Info["LeistungSolar"]:
            case Info["Durchfluss"]:
                $val = $this->_extractValue($telegramObject->DATA, $telegramObject->HIGH_BYTE);
                return ($val / 100);
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
     * Calculate the Value from the low byte and high byte
     *
     * @param lowByte
     * @param highByte
     */
    private function _extractValue(int $lowByte, int $highByte): int {
        $usValue;

        if ($highByte <= 127) {
            $usValue = $highByte * 256 + $lowByte;
        } else if ($highByte === 128 && $lowByte === 0) {
            $usValue = $highByte * 256 + $lowByte;
        } else {
            $usValue = -32768 + ($highByte - 128) * 256 + $lowByte;
        }
        return $usValue;
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
    
    // Method: POST, PUT, GET etc
    // Data: array("param" => "value") ==> index.php?param=value
    private function _callAPI($method, $url, $data = false) {
        $curl = curl_init();

        switch ($method)
        {
            case "POST":
                curl_setopt($curl, CURLOPT_POST, 1);

                if ($data)
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
                break;
            case "PUT":
                curl_setopt($curl, CURLOPT_PUT, 1);
                break;
            default:
                if ($data)
                    $url = sprintf("%s?%s", $url, http_build_query($data));
        }

        // Optional Authentication:
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_DIGEST);
        curl_setopt($curl, CURLOPT_USERPWD, $this->username.":".$this->password);

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $res = curl_exec($curl);
        $error = curl_error($curl);
        
        $result = array( 'body' => '',
                         'curl_error' => '',
                         'http_code' => '',
                         'last_url' => '');
        if ($error != "") {
            $result['curl_error'] = $error;
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
