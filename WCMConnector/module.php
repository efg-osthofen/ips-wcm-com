<?php
require __DIR__ . '/../libs/wcm-client/wcm.php';

class WCMConnector extends IPSModule {
    private const BETRIEBSART_HK_PREFIX = "BetriebsartHeizkreis";
    private const SEMAPHORE = "WCM_Communication";
    private const SEMAPHORE_TIMEOUT = 10000;
    private const BLOCKED_MESSAGE = "Durch parallele Anfrage blockiert.";

    // Limits for the communication with the WCM-COM, in seconds. A read cycle
    // must end well before the semaphore timeout (10 s) and the update interval.
    protected const CONNECT_TIMEOUT = 3.0;
    protected const REQUEST_TIMEOUT = 8.0;
    protected const CYCLE_TIME_BUDGET = 8.0;

    /** Pauses after the 1st, 2nd, 3rd and every further failed cycle, in seconds */
    private const PAUSE_STEPS = [60, 120, 300];

    /** Parameters that rarely change and are only read every SlowParameterInterval minutes */
    private const SLOW_HK_PARAMETERS = ["NormalRaumtemperaturHK", "RaumfrosttemperaturHK", "SoWiUmschalttemperaturHK", "SteilheitHK"];
    private const SLOW_KESSEL_PARAMETERS = ["KesselMaxLeistungHeizung", "KesselMaxLeistungWW"];

    // Instance buffers (in memory, reset on restart) for the communication state
    private const BUFFER_FAILURES = "FailedCycles";
    private const BUFFER_PAUSE_UNTIL = "PauseUntil";
    private const BUFFER_SLOW_READ_AT = "SlowParametersReadAt";
    private const BUFFER_SLOW_DUE = "SlowParametersDue";

    public function Create() {
        parent::Create();

        $this->RegisterPropertyString("URL", "");
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyInteger("UpdateInterval", 0);
        $this->RegisterPropertyInteger("FirstHK", 2);
        $this->RegisterPropertyInteger("LastHK", 5);
        $this->RegisterPropertyInteger("SlowParameterInterval", 15);
        
        $this->RegisterTimer("Update", 0, "WCM_RetrieveWCMStatus(".$this->InstanceID.", true);");
        
        $this->CreateVarProfileWCMBetriebsartHK();
        $this->CreateVarProfileWCMMaxLeistung();
    }
    
    public function ApplyChanges() {
        parent::ApplyChanges();
                
        $this->RegisterVariableBoolean("ParameterUpdate", "Parameter Update", "~Switch", 100);
        $this->EnableAction("ParameterUpdate");

        for($i = $this->ReadPropertyInteger("FirstHK"); $i <= $this->ReadPropertyInteger("LastHK"); $i++) {
            $startPosition = $i * 10;
            
            $this->RegisterVariableInteger(self::BETRIEBSART_HK_PREFIX.$i, "HK ".$i." Betriebsart", "WCM.BetriebsartHK", $startPosition + 1);
            $this->EnableAction(self::BETRIEBSART_HK_PREFIX.$i);
            
            $this->RegisterVariableFloat("VorlauftemperaturHK".$i, "HK ".$i." Vorlauftemperatur", "~Temperature", $startPosition + 2);
            $this->RegisterVariableFloat("SollTempHK".$i, "HK ".$i." Soll Temperatur", "~Temperature", $startPosition + 3);
            $this->RegisterVariableFloat("WaermeanforderungHK".$i, "HK ".$i." Waermeanforderung", "~Temperature", $startPosition + 4);
            
            $this->RegisterVariableFloat("NormalRaumtemperaturHK".$i, "HK ".$i." Normal Raumtemperatur", "~Temperature.HM", $startPosition + 5);
            $this->EnableAction("NormalRaumtemperaturHK".$i);
            
            $this->RegisterVariableFloat("RaumfrosttemperaturHK".$i, "HK ".$i." Raumfrosttemperatur", "~Temperature.HM", $startPosition + 6);
            $this->EnableAction("RaumfrosttemperaturHK".$i);
            
            $this->RegisterVariableFloat("SoWiUmschalttemperaturHK".$i, "HK ".$i." So/Wi Umschalttemperatur", "~Temperature.HM", $startPosition + 7);
            $this->EnableAction("SoWiUmschalttemperaturHK".$i);
            
            $this->RegisterVariableFloat("SteilheitHK".$i, "HK ".$i." Steilheit", "", $startPosition + 8);
            $this->EnableAction("SteilheitHK".$i);
        }
        
        $startPosition = $this->ReadPropertyInteger("LastHK") * 10 + 10;
        
        $this->RegisterVariableInteger("KesselFehlercode", "Kessel Fehlercode", "", $startPosition + 1);
        $this->RegisterVariableFloat("KesselAussentemperatur", "Kessel Außentemperatur", "~Temperature", $startPosition + 2);
        $this->RegisterVariableFloat("KesselGedaempfteAussentemperatur", "Kessel Gedämpfte Außentemperatur", "~Temperature", $startPosition + 3);
        $this->RegisterVariableInteger("KesselLaststellung", "Kessel Laststellung", "~Intensity.100", $startPosition + 4);
        $this->RegisterVariableInteger("KesselMaxLeistungHeizung", "Kessel Max. Leistung Heizung", "WCM.MaxLeistung", $startPosition + 5);
        $this->EnableAction("KesselMaxLeistungHeizung");
        $this->RegisterVariableInteger("KesselMaxLeistungWW", "Kessel Max. Leistung WW", "WCM.MaxLeistung", $startPosition + 6);
        $this->EnableAction("KesselMaxLeistungWW");
        $this->RegisterVariableFloat("KesselWaermeanforderung", "Kessel Wärmeanforderung", "~Temperature", $startPosition + 7);
        $this->RegisterVariableFloat("KesselVorlauftemperatur", "Kessel Vorlauftemperatur", "~Temperature", $startPosition + 8);
        $this->RegisterVariableFloat("KesselVorlauftemperaturEstb", "Kessel Vorlauftemperatur eSTB", "~Temperature", $startPosition + 9);
        $this->RegisterVariableFloat("KesselRuecklauftemperatur", "Kessel Rücklauftemperatur", "~Temperature", $startPosition + 10);
        $this->RegisterVariableFloat("KesselWarmwassertemperatur", "Kessel Warmwassertemperatur", "~Temperature", $startPosition + 11);
        $this->RegisterVariableFloat("KesselAbgastemperatur", "Kessel Abgastemperatur", "~Temperature", $startPosition + 12);
        $this->RegisterVariableFloat("PufferB10Oben", "Puffer B10 Temperatur Oben", "~Temperature", $startPosition + 13);
        $this->RegisterVariableFloat("PufferB11Unten", "Puffer B11 Temperatur Unten", "~Temperature", $startPosition + 14);
        
        $this->RequestAction("ParameterUpdate", $this->GetValue("ParameterUpdate"));
    }
    
    public function RequestAction($Ident, $Value) {
        if(IPS_SemaphoreEnter(self::SEMAPHORE, self::SEMAPHORE_TIMEOUT)) {
            try {
                switch($Ident) {
                    case "ParameterUpdate":
                        SetValue($this->GetIDForIdent($Ident), $Value);
                        // Switching on reads everything at once, also during a pause after errors
                        $this->ResetCommunicationState();

                        if($Value === True) {
                            $this->ReadStatus(true);
                            $this->SetTimerInterval("Update", $this->ReadPropertyInteger("UpdateInterval") * 1000);
                        } else {
                            $this->SetTimerInterval("Update", 0);
                            $this->ReadStatus(false);
                        }
                        break;
                    case "KesselMaxLeistungHeizung":
                        $this->WriteMaxLeistungHeizung($Value);
                        $this->SetWCMValue($Ident, $Value, true);
                        break;
                    case "KesselMaxLeistungWW":
                        $this->WriteMaxLeistungWW($Value);
                        $this->SetWCMValue($Ident, $Value, true);
                        break;
                    default:
                        $api = $this->CreateApi();
                    
                        if(strpos($Ident, self::BETRIEBSART_HK_PREFIX) !== false) {
                            $api->bufferedUpdateBetriebsartHK(intval(substr($Ident, strlen(self::BETRIEBSART_HK_PREFIX))), $Value);
                        } elseif(strpos($Ident, "NormalRaumtemperaturHK") !== false) {
                            $api->bufferedUpdateNormalRaumtemperaturHK(intval(substr($Ident, strlen("NormalRaumtemperaturHK"))), $Value);
                        }  elseif(strpos($Ident, "RaumfrosttemperaturHK") !== false) {
                            $api->bufferedUpdateRaumfrosttemperaturHK(intval(substr($Ident, strlen("RaumfrosttemperaturHK"))), $Value);
                        }  elseif(strpos($Ident, "SoWiUmschalttemperaturHK") !== false) {
                            $api->bufferedUpdateSoWiUmschalttemperaturHK(intval(substr($Ident, strlen("SoWiUmschalttemperaturHK"))), $Value);
                        }  elseif(strpos($Ident, "SteilheitHK") !== false) {
                            $api->bufferedUpdateSteilheitHK(intval(substr($Ident, strlen("SteilheitHK"))), $Value);
                        } else {
                            throw new Exception("Invalid Ident");
                        }

                        $this->SendWriteBuffer($api);

                        // Only update the variable once the WCM-COM accepted the new value
                        $this->SetWCMValue($Ident, $Value, true);
                        $this->MarkSlowParameterDue($Ident);
                }
            } finally {
                // Always release the semaphore, also on early break or exception
                IPS_SemaphoreLeave(self::SEMAPHORE);
            }
        } else {
            echo self::BLOCKED_MESSAGE;
        }

    }

    // Public functions (WCM_*) can be called from scripts and the timer at any
    // time, so each of them takes the semaphore. RequestAction already holds it
    // and therefore calls the private implementations directly.

    public function UpdateWCMMaxLeistungHeizung(int $maxLeistung) {
        if(!IPS_SemaphoreEnter(self::SEMAPHORE, self::SEMAPHORE_TIMEOUT)) {
            echo self::BLOCKED_MESSAGE;
            return;
        }

        try {
            $this->WriteMaxLeistungHeizung($maxLeistung);
        } finally {
            IPS_SemaphoreLeave(self::SEMAPHORE);
        }
    }

    public function UpdateWCMMaxLeistungWW(int $maxLeistung) {
        if(!IPS_SemaphoreEnter(self::SEMAPHORE, self::SEMAPHORE_TIMEOUT)) {
            echo self::BLOCKED_MESSAGE;
            return;
        }

        try {
            $this->WriteMaxLeistungWW($maxLeistung);
        } finally {
            IPS_SemaphoreLeave(self::SEMAPHORE);
        }
    }

    public function RetrieveWCMStatus(bool $sendBuffer = true) {
        if(!IPS_SemaphoreEnter(self::SEMAPHORE, self::SEMAPHORE_TIMEOUT)) {
            // Keep the current values and try again with the next timer run
            $this->SendDebug("RetrieveWCMStatus", "Skipped, WCM-COM is busy with another request", 0);
            return;
        }

        try {
            $this->ReadStatus($sendBuffer);
        } finally {
            IPS_SemaphoreLeave(self::SEMAPHORE);
        }
    }

    private function CreateApi(): Weishaupt {
        return new Weishaupt(new WeishauptOptions(
            $this->ReadPropertyString("URL"),
            $this->ReadPropertyString("Username"),
            $this->ReadPropertyString("Password"),
            static::CONNECT_TIMEOUT,
            static::REQUEST_TIMEOUT
        ));
    }

    /** Current time in seconds. */
    protected function Now(): float {
        return microtime(true);
    }

    private function WriteMaxLeistungHeizung(int $maxLeistung) {
        $api = $this->CreateApi();

        $api->bufferedUpdateMaxLeistungHeizung($maxLeistung);
        $this->SendWriteBuffer($api);
        $this->MarkSlowParameterDue("KesselMaxLeistungHeizung");
    }

    private function WriteMaxLeistungWW(int $maxLeistung) {
        $api = $this->CreateApi();

        $api->bufferedUpdateMaxLeistungWW($maxLeistung);
        $this->SendWriteBuffer($api);
        $this->MarkSlowParameterDue("KesselMaxLeistungWW");
    }

    /**
     * Sends buffered write telegrams and fails if the WCM-COM rejected any of them.
     * HTTP errors, "server is busy" and invalid answers are retried. If the
     * WCM-COM doesn't answer at all, there is no retry and the cyclic reading
     * pauses, as after a failed read cycle.
     */
    private function SendWriteBuffer(Weishaupt $api) {
        try {
            $response = $api->sendBuffer(10);
        } catch(WeishauptConnectionException $e) {
            $pause = $this->RegisterFailedCycle();
            $this->SendDebug("WriteWCM", sprintf("Schreiben fehlgeschlagen (%s): %s | Pause %d s", $this->DescribeError($e), $e->getMessage(), $pause), 0);
            throw $e;
        }
        $api->clearBuffer();

        foreach($response->getIterator() as $telegram) {
            if($telegram->ERROR) {
                throw new Exception("WCM-COM rejected the write request for INFONR ".$telegram->INFONR.".");
            }
        }
    }

    /**
     * Sets a WCM value variable. Invalid values (N/V, error, no communication,
     * updates switched off) are set to 0 and the variable is disabled, so the
     * visualization shows it as not usable until a valid value arrives.
     */
    private function SetWCMValue(string $ident, $value, bool $valid) {
        $this->SetValue($ident, $valid ? $value : 0);

        $id = $this->GetIDForIdent($ident);
        if(IPS_GetObject($id)["ObjectIsDisabled"] === $valid) {
            IPS_SetDisabled($id, !$valid);
        }
    }

    // --- Communication state: pause after errors, slow parameters --------

    /** Forgets errors and pauses, the next read cycle reads all parameters. */
    private function ResetCommunicationState() {
        $this->SetBuffer(self::BUFFER_FAILURES, "0");
        $this->SetBuffer(self::BUFFER_PAUSE_UNTIL, "0");
        $this->SetBuffer(self::BUFFER_SLOW_READ_AT, "");
        $this->SetBuffer(self::BUFFER_SLOW_DUE, "[]");
    }

    /**
     * Counts a failed cycle and starts the pause (1, 2, 5, 5, ... minutes).
     * The next successful cycle reads all parameters.
     * @return int pause in seconds
     */
    private function RegisterFailedCycle(): int {
        $failures = intval($this->GetBuffer(self::BUFFER_FAILURES)) + 1;
        $pause = self::PAUSE_STEPS[min($failures, count(self::PAUSE_STEPS)) - 1];

        $this->SetBuffer(self::BUFFER_FAILURES, strval($failures));
        $this->SetBuffer(self::BUFFER_PAUSE_UNTIL, strval($this->Now() + $pause));
        $this->SetBuffer(self::BUFFER_SLOW_READ_AT, "");

        return $pause;
    }

    /** @return float seconds until the pause after errors ends, 0 if there is none */
    private function RemainingPause(): float {
        return max(0.0, floatval($this->GetBuffer(self::BUFFER_PAUSE_UNTIL)) - $this->Now());
    }

    private function IsSlowParameter(string $ident): bool {
        if(in_array($ident, self::SLOW_KESSEL_PARAMETERS, true)) {
            return true;
        }
        foreach(self::SLOW_HK_PARAMETERS as $prefix) {
            if(strpos($ident, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    private function AllSlowParametersDue(): bool {
        $readAt = $this->GetBuffer(self::BUFFER_SLOW_READ_AT);
        $interval = $this->ReadPropertyInteger("SlowParameterInterval");

        return $interval <= 0 || $readAt === "" || $this->Now() - floatval($readAt) >= $interval * 60;
    }

    /** @return list<string> slow parameters to read in the next cycle in any case (after a write) */
    private function DueSlowParameters(): array {
        $due = json_decode($this->GetBuffer(self::BUFFER_SLOW_DUE), true);

        return is_array($due) ? $due : [];
    }

    /** A written slow parameter is read back once in the next cycle. */
    private function MarkSlowParameterDue(string $ident) {
        if(!$this->IsSlowParameter($ident)) {
            return;
        }
        $due = $this->DueSlowParameters();
        if(!in_array($ident, $due, true)) {
            $due[] = $ident;
            $this->SetBuffer(self::BUFFER_SLOW_DUE, json_encode($due));
        }
    }

    private function DescribeError(Exception $e): string {
        if($e instanceof WeishauptTimeoutException) {
            return "Timeout";
        }
        if($e instanceof WeishauptConnectionException) {
            return "keine Verbindung";
        }
        if($e instanceof WeishauptBusyException) {
            return "Server busy";
        }
        return "Fehlerhafte Antwort";
    }

    private static function DescribeStats(array $stats): string {
        return sprintf(
            "%d Telegramme, %d/%d Blöcke, %d HTTP-Anfragen, %d Verbindung(en), %s s",
            $stats["telegrams"],
            $stats["sent_blocks"],
            $stats["blocks"],
            $stats["http_requests"],
            $stats["connections"],
            number_format($stats["duration"], 2, ",", "")
        );
    }

    // --- Reading ------------------------------------------------------------

    /**
     * Requests for all value variables, in the order they are sent.
     * Fehlercode has to be the first telegram.
     * @return array<string, callable(Weishaupt): int> ident => adds the read telegram, returns its buffer position
     */
    private function ReadRequests(): array {
        $requests = [];
        $requests["KesselFehlercode"] = fn(Weishaupt $api) => $api->bufferedRequestFehlercode();

        for($i = $this->ReadPropertyInteger("FirstHK"); $i <= $this->ReadPropertyInteger("LastHK"); $i++) {
            $requests[self::BETRIEBSART_HK_PREFIX.$i] = fn(Weishaupt $api) => $api->bufferedRequestBetriebsartHK($i);
            $requests["VorlauftemperaturHK".$i] = fn(Weishaupt $api) => $api->bufferedRequestVorlauftemperaturHK($i);
            $requests["SollTempHK".$i] = fn(Weishaupt $api) => $api->bufferedRequestSollTempHK($i);
            $requests["WaermeanforderungHK".$i] = fn(Weishaupt $api) => $api->bufferedRequestWaermeanforderungHK($i);
            $requests["NormalRaumtemperaturHK".$i] = fn(Weishaupt $api) => $api->bufferedRequestNormalRaumtemperaturHK($i);
            $requests["RaumfrosttemperaturHK".$i] = fn(Weishaupt $api) => $api->bufferedRequestRaumfrosttemperaturHK($i);
            $requests["SoWiUmschalttemperaturHK".$i] = fn(Weishaupt $api) => $api->bufferedRequestSoWiUmschalttemperaturHK($i);
            $requests["SteilheitHK".$i] = fn(Weishaupt $api) => $api->bufferedRequestSteilheitHK($i);
        }

        $requests["KesselLaststellung"] = fn(Weishaupt $api) => $api->bufferedRequestLaststellung();
        $requests["KesselMaxLeistungHeizung"] = fn(Weishaupt $api) => $api->bufferedRequestMaxLeistungHeizung();
        $requests["KesselMaxLeistungWW"] = fn(Weishaupt $api) => $api->bufferedRequestMaxLeistungWW();
        $requests["KesselAussentemperatur"] = fn(Weishaupt $api) => $api->bufferedRequestAussentemperatur();
        $requests["KesselGedaempfteAussentemperatur"] = fn(Weishaupt $api) => $api->bufferedRequestGedaempfteAussentemperatur();
        $requests["KesselWaermeanforderung"] = fn(Weishaupt $api) => $api->bufferedRequestWaermeanforderung();
        $requests["KesselVorlauftemperatur"] = fn(Weishaupt $api) => $api->bufferedRequestVorlauftemperatur();
        $requests["KesselVorlauftemperaturEstb"] = fn(Weishaupt $api) => $api->bufferedRequestVorlauftemperaturEstb();
        $requests["KesselRuecklauftemperatur"] = fn(Weishaupt $api) => $api->bufferedRequestRuecklauftemperatur();
        $requests["KesselWarmwassertemperatur"] = fn(Weishaupt $api) => $api->bufferedRequestWarmwassertemperatur();
        $requests["KesselAbgastemperatur"] = fn(Weishaupt $api) => $api->bufferedRequestAbgastemperatur();
        $requests["PufferB10Oben"] = fn(Weishaupt $api) => $api->bufferedRequestB10PufferOben();
        $requests["PufferB11Unten"] = fn(Weishaupt $api) => $api->bufferedRequestB11PufferUnten();

        return $requests;
    }

    /**
     * Reads the values from the WCM-COM (one attempt, limited in time).
     *
     * Slow parameters are only read when they are due; in the other cycles
     * they keep their value. A failed cycle sets all values to 0, disables
     * them and pauses the cyclic reading. $sendBuffer = false sets all values
     * to 0 and disables them without contacting the WCM-COM.
     */
    private function ReadStatus(bool $sendBuffer) {
        $requests = $this->ReadRequests();

        if(!$sendBuffer) {
            foreach(array_keys($requests) as $ident) {
                $this->SetWCMValue($ident, 0, false);
            }
            return;
        }

        $remainingPause = $this->RemainingPause();
        if($remainingPause > 0) {
            // Variables stay as the failed cycle left them (disabled)
            $this->SendDebug("RetrieveWCMStatus", sprintf("Pause nach Fehler, nächster Versuch in %d s", ceil($remainingPause)), 0);
            return;
        }

        $api = $this->CreateApi();
        $readAllSlow = $this->AllSlowParametersDue();
        $dueSlow = $this->DueSlowParameters();

        $bufferPositions = [];
        $slowRead = 0;
        foreach($requests as $ident => $request) {
            if($this->IsSlowParameter($ident)) {
                if(!$readAllSlow && !in_array($ident, $dueSlow, true)) {
                    continue;
                }
                $slowRead++;
            }
            $bufferPositions[$ident] = $request($api);
        }

        try {
            $response = $api->sendBuffer(1, static::CYCLE_TIME_BUDGET);
        } catch(Exception $e) {
            $this->LogMessage($e->getMessage(), KL_ERROR);

            // Real failure: all values (also the slow ones not read in this cycle) become invalid
            foreach(array_keys($requests) as $ident) {
                $this->SetWCMValue($ident, 0, false);
            }

            $pause = $this->RegisterFailedCycle();
            $this->SendDebug("RetrieveWCMStatus", sprintf(
                "Zyklus fehlgeschlagen (%s): %s | %s | Pause %d s (Fehler Nr. %s)",
                $this->DescribeError($e),
                $e->getMessage(),
                self::DescribeStats($api->getLastStats()),
                $pause,
                $this->GetBuffer(self::BUFFER_FAILURES)
            ), 0);
            return;
        }

        $telegrams = $response->getIterator();
        foreach($bufferPositions as $key => $value) {
            if(!isset($telegrams[$value])) {
                $this->SetWCMValue($key, 0, false);
            } elseif($telegrams[$value]->ERROR) {
                // The WCM-COM has no value for this parameter (e.g. not installed)
                $this->SendDebug("RetrieveWCMStatus", "WCM-COM returned an error for ".$key, 0);
                $this->SetWCMValue($key, 0, false);
            } elseif($telegrams[$value]->NOT_AVAILABLE) {
                // Shown as "N/V" on the heating system (e.g. sensor not connected)
                $this->SendDebug("RetrieveWCMStatus", "WCM-COM reports N/V for ".$key, 0);
                $this->SetWCMValue($key, 0, false);
            } else {
                $this->SetWCMValue($key, $telegrams[$value]->DATA, true);
            }
        }

        $this->SetBuffer(self::BUFFER_FAILURES, "0");
        $this->SetBuffer(self::BUFFER_PAUSE_UNTIL, "0");
        $this->SetBuffer(self::BUFFER_SLOW_DUE, "[]");
        if($readAllSlow) {
            $this->SetBuffer(self::BUFFER_SLOW_READ_AT, strval($this->Now()));
        }

        $this->SendDebug("RetrieveWCMStatus", sprintf(
            "Zyklus OK: %s, seltene Parameter: %s",
            self::DescribeStats($api->getLastStats()),
            $readAllSlow ? "alle" : ($slowRead > 0 ? $slowRead." nach Schreiben" : "keine")
        ), 0);
    }
    
    private function CreateVarProfileWCMBetriebsartHK() {
		if (!IPS_VariableProfileExists("WCM.BetriebsartHK")) {
			IPS_CreateVariableProfile("WCM.BetriebsartHK", 1);
			IPS_SetVariableProfileValues("WCM.BetriebsartHK", 1, 255, 0);
            foreach(BetriebsartHK as $name => $value) {
                IPS_SetVariableProfileAssociation("WCM.BetriebsartHK", $value, $name, "", -1);
            }
		 }
	}
    
    private function CreateVarProfileWCMMaxLeistung() {
		if (!IPS_VariableProfileExists("WCM.MaxLeistung")) {
			IPS_CreateVariableProfile("WCM.MaxLeistung", 1);
			IPS_SetVariableProfileValues("WCM.MaxLeistung", 36, 100, 1);
		 }
	}
}
?>