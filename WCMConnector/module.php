<?
require __DIR__ . '/../libs/wcm-client/wcm.php';

class WCMConnector extends IPSModule {
    private const BETRIEBSART_HK_PREFIX = "BetriebsartHeizkreis";

    public function Create() {
        parent::Create();

        $this->RegisterPropertyString("URL", "");
        $this->RegisterPropertyString("Username", "");
        $this->RegisterPropertyString("Password", "");
        $this->RegisterPropertyInteger("UpdateInterval", 0);
        $this->RegisterPropertyInteger("FirstHK", 2);
        $this->RegisterPropertyInteger("LastHK", 5);
        
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
        if(IPS_SemaphoreEnter("WCM_Communication", 10000)) {
            try {
                switch($Ident) {
                    case "ParameterUpdate":
                        SetValue($this->GetIDForIdent($Ident), $Value);
                    
                        if($Value === True) {
                            $this->RetrieveWCMStatus(true);
                            $this->SetTimerInterval("Update", $this->ReadPropertyInteger("UpdateInterval") * 1000);
                        } else {
                            $this->SetTimerInterval("Update", 0);
                            $this->RetrieveWCMStatus(false);
                        }
                        break;
                    case "KesselMaxLeistungHeizung":
                        $this->UpdateWCMMaxLeistungHeizung($Value);
                        SetValue($this->GetIDForIdent($Ident), $Value);
                        break;
                    case "KesselMaxLeistungWW":
                        $this->UpdateWCMMaxLeistungWW($Value);
                        SetValue($this->GetIDForIdent($Ident), $Value);
                        break;
                    default:
                        $api = new Weishaupt(new WeishauptOptions($this->ReadPropertyString("URL"), $this->ReadPropertyString("Username"), $this->ReadPropertyString("Password")));
                    
                        if(strpos($Ident, self::BETRIEBSART_HK_PREFIX) !== false) {
                            $api->bufferedUpdateBetriebsartHK(intval(substr($Ident, strlen(self::BETRIEBSART_HK_PREFIX))), $Value);
                            SetValue($this->GetIDForIdent($Ident), $Value);
                        } elseif(strpos($Ident, "NormalRaumtemperaturHK") !== false) {
                            $api->bufferedUpdateNormalRaumtemperaturHK(intval(substr($Ident, strlen("NormalRaumtemperaturHK"))), $Value);
                            SetValue($this->GetIDForIdent($Ident), $Value);
                        }  elseif(strpos($Ident, "RaumfrosttemperaturHK") !== false) {
                            $api->bufferedUpdateRaumfrosttemperaturHK(intval(substr($Ident, strlen("RaumfrosttemperaturHK"))), $Value);
                            SetValue($this->GetIDForIdent($Ident), $Value);
                        }  elseif(strpos($Ident, "SoWiUmschalttemperaturHK") !== false) {
                            $api->bufferedUpdateSoWiUmschalttemperaturHK(intval(substr($Ident, strlen("SoWiUmschalttemperaturHK"))), $Value);
                            SetValue($this->GetIDForIdent($Ident), $Value);
                        }  elseif(strpos($Ident, "SteilheitHK") !== false) {
                            $api->bufferedUpdateSteilheitHK(intval(substr($Ident, strlen("SteilheitHK"))), $Value);
                            SetValue($this->GetIDForIdent($Ident), $Value);
                        } else {
                            throw new Exception("Invalid Ident");
                        }
                    
                        $api->sendBuffer(10);
                        $api->clearBuffer();
                }
            } finally {
                // Always release the semaphore, also on early break or exception
                IPS_SemaphoreLeave("WCM_Communication");
            }
        } else {
            echo "Durch parallele Anfrage blockiert.";
        }
        
    }

    public function UpdateWCMMaxLeistungHeizung(int $maxLeistung) {
        $api = new Weishaupt(new WeishauptOptions($this->ReadPropertyString("URL"), $this->ReadPropertyString("Username"), $this->ReadPropertyString("Password")));

        $api->bufferedUpdateMaxLeistungHeizung($maxLeistung);
        $api->sendBuffer(10);
        $api->clearBuffer();
    }

    public function UpdateWCMMaxLeistungWW(int $maxLeistung) {
        $api = new Weishaupt(new WeishauptOptions($this->ReadPropertyString("URL"), $this->ReadPropertyString("Username"), $this->ReadPropertyString("Password")));

        $api->bufferedUpdateMaxLeistungWW($maxLeistung);
        $api->sendBuffer(10);
        $api->clearBuffer();
    }

    public function RetrieveWCMStatus(bool $sendBuffer = true) {
        $api = new Weishaupt(new WeishauptOptions($this->ReadPropertyString("URL"), $this->ReadPropertyString("Username"), $this->ReadPropertyString("Password")));

        $bufferPositions = [];
        
        $bufferPositions["KesselFehlercode"] = $api->bufferedRequestFehlercode();

        for($i = $this->ReadPropertyInteger("FirstHK"); $i <= $this->ReadPropertyInteger("LastHK"); $i++) {
            $bufferPositions[self::BETRIEBSART_HK_PREFIX.$i] = $api->bufferedRequestBetriebsartHK($i);

            $bufferPositions["VorlauftemperaturHK".$i] = $api->bufferedRequestVorlauftemperaturHK($i);
            $bufferPositions["SollTempHK".$i] = $api->bufferedRequestSollTempHK($i);
            $bufferPositions["WaermeanforderungHK".$i] = $api->bufferedRequestWaermeanforderungHK($i);
            $bufferPositions["NormalRaumtemperaturHK".$i] = $api->bufferedRequestNormalRaumtemperaturHK($i);
            $bufferPositions["RaumfrosttemperaturHK".$i] = $api->bufferedRequestRaumfrosttemperaturHK($i);
            $bufferPositions["SoWiUmschalttemperaturHK".$i] = $api->bufferedRequestSoWiUmschalttemperaturHK($i);
            $bufferPositions["SteilheitHK".$i] = $api->bufferedRequestSteilheitHK($i);
        }
        
        $bufferPositions["KesselLaststellung"] = $api->bufferedRequestLaststellung();
        $bufferPositions["KesselMaxLeistungHeizung"] = $api->bufferedRequestMaxLeistungHeizung();
        $bufferPositions["KesselMaxLeistungWW"] = $api->bufferedRequestMaxLeistungWW();
        $bufferPositions["KesselAussentemperatur"] = $api->bufferedRequestAussentemperatur();
        $bufferPositions["KesselGedaempfteAussentemperatur"] = $api->bufferedRequestGedaempfteAussentemperatur();
        $bufferPositions["KesselWaermeanforderung"] = $api->bufferedRequestWaermeanforderung();
        $bufferPositions["KesselVorlauftemperatur"] = $api->bufferedRequestVorlauftemperatur();
        $bufferPositions["KesselVorlauftemperaturEstb"] = $api->bufferedRequestVorlauftemperaturEstb();
        $bufferPositions["KesselRuecklauftemperatur"] = $api->bufferedRequestRuecklauftemperatur();
        $bufferPositions["KesselWarmwassertemperatur"] = $api->bufferedRequestWarmwassertemperatur();
        $bufferPositions["KesselAbgastemperatur"] = $api->bufferedRequestAbgastemperatur();
        $bufferPositions["PufferB10Oben"] = $api->bufferedRequestB10PufferOben();
        $bufferPositions["PufferB11Unten"] = $api->bufferedRequestB11PufferUnten();

        $error = false;
        if($sendBuffer == true) {
            try {
                $response = $api->sendBuffer();
            } catch(Exception $e) {
                $error = true;
                $this->LogMessage($e->getMessage(), KL_ERROR);
            }
        }
        
        foreach($bufferPositions as $key => $value) {
            if($error == true || $sendBuffer == false || !isset($response->getIterator()[$value])) {
                $this->SetValue($key, 0);
            } else {
                $this->SetValue($key, $response->getIterator()[$value]->DATA);
            }
        }
        
        $api->clearBuffer();
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