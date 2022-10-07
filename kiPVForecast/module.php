<?

class PVForecast extends IPSModule
{

	private $fc;
	public function Create()
	{
		//Never delete this line!
		parent::Create();

		$this->RegisterPropertyInteger('kwp', 1000);
		$this->RegisterPropertyString('location', '');
		$this->RegisterPropertyString('dwd_station', '');
		$this->RegisterPropertyInteger('azimuth', 0);
		$this->RegisterPropertyInteger('tilt', 30);
		$this->RegisterPropertyInteger('efficiency', 95);
		$this->RegisterPropertyInteger('cloudeffect', 65);
		$this->RegisterPropertyFloat('tempkoeff', 0.65);
		$this->RegisterPropertyInteger('horizon', 0);
		
		$this->RegisterPropertyBoolean('obj', false);
		$this->RegisterPropertyInteger('obj_direction', 0);
		$this->RegisterPropertyInteger('obj_distance', 0);
		$this->RegisterPropertyInteger('obj_height', 10);
		$this->RegisterPropertyInteger('obj_size', 50);
		$this->RegisterPropertyInteger('obj_effect', 50);
		$this->RegisterPropertyInteger('forecastVariables', 3);

		$this->RegisterTimer('Update', 1000*60*60, 'pvFC_Update($_IPS[\'TARGET\']);');
	}

	public function ApplyChanges() {
        //Never delete this line!
        parent::ApplyChanges();
		$this->Update();
    }

	private function initfc(){
		$station =  $this->ReadPropertyString("dwd_station");
		if(!empty($this->ReadPropertyString("location"))){
			$latlon  = json_decode($this->ReadPropertyString("location"),true);
		} else {
			$latlon["longitude"] = 0;
			$latlon["latitude"] = 0;
		}

		$PV      =[     "kwp"         =>  $this->ReadPropertyInteger('kwp'), 
						"azimuth"     =>  $this->ReadPropertyInteger('azimuth'),      // eigentlich sind es 30 aber soll ist vergleich zeigt leichte verschiebung vom abend - also mehr nach rechts drehen
						"tilt"        =>  $this->ReadPropertyInteger('tilt'),
						"pvtype"      => "D",                                         // D= Dachform geständert, Ausrichtung der offenen seite = azimuth
						"efficiency"  =>  $this->ReadPropertyInteger('efficiency'),
						"cloudeffect" =>  $this->ReadPropertyInteger('cloudeffect'),  // Effekt für Leistungsreduktion bei Bewölkung in Prozent
						"lon"         => $latlon["longitude"],
						"lat"         => $latlon["latitude"],
						"tempkoeff"   => $this->ReadPropertyFLoat('tempkoeff'),       // Temperaturkoeffizient lt. Datenblatt
						"horizon"     => $this->ReadPropertyInteger('horizon'),       // Horizont für Einfallswinkel Sonne

						// Beschattungsobjekt
						"obj_direction" => $this->ReadPropertyInteger('obj_direction'),   // Himmelsrichtung des Beschattungsobjektes Grad von Norden =0
						"obj_distance"  => $this->ReadPropertyInteger('obj_distance'),      // Abstand des Objektes in Meter
						"obj_height"    => $this->ReadPropertyInteger('obj_height'),      // Höhe des Objektes in Meter
						"obj_size"      => $this->ReadPropertyInteger('obj_size'),    // Prozentualer Abdeckungsanteil der PV Anlage durch Objekt (Mittelwert)
						"obj_effect"    => $this->ReadPropertyInteger('obj_effect')/100    // Azuswirkung der Beschattung auf Ertrag (kwp mit gemessenem Ertrag bei Beschattung (grob))
		];
		$this->fc = false;
		if(!empty($station))$this->fc = new PVForecastcls($station, $PV, $this->InstanceID);
	}

	public function Update($force=false){
		$this->initfc();
		if($this->fc){
			if($force)$this->fc->loadForecast(true);
			$this->fc->CreateFCVariables($this->ReadPropertyInteger("forecastVariables"));
		}else{
			return false;
		}

	}

	public function getDayForecast($ts){
		$this->initfc();
		if($this->fc){
			return $this->fc->getDayForecast($ts);
		}else{
			return false;
		}
	}

	public function getHourForecast($ts){
		$this->initfc();
		if($this->fc){
			return $this->fc->getHourForecast($ts);
		}else{
			return false;
		}			
	}
	public function getForecast(){
		$this->initfc();
		if($this->fc){
			return $this->fc->getForecast();
		}else{
			return false;
		}			
	}

	
}

class PVForecastcls{
	private $fc;
	private $PV;
	private $dwd_fca;
	private $dwd_station;
	private $instance;
	const TYPE_D_CORRECTION = 1.15; // Faktor for increasing / decreasing pv estimate for Type D Positioning

	const CACHE_AGE = 3600*3;       // Maximales ALter der Berechung und Wettervorhersage

	function __construct($station, $PV, $instance){
		$this->instance = $instance;
		// Wetterdaten laden (DWD)
		$this->PV = $PV;
		$this->dwd_station = $station;
		$this->loadForecast();
		#$this->checkEvent(); // Event über Moduleklasse
	}

	#### Tageswert Zurckgeben #####################################################
	public function getDayForecast($ts){
	
		foreach($this->fc["daily"] as $fc){
			if(date("Ymd", $fc["ts"]) == date("Ymd", $ts)){
				return $fc["pv_estimate"];
			}
		}
		return false;
	}

	#### Stundenwert Zurckgeben #####################################################
	public function getHourForecast($ts){
	
		foreach($this->fc["hourly"] as $fc){
			if(date("YmdH", $fc["ts"]) == date("YmdH", $ts)){
				return $fc["pv_estimate"];
			}
		}
		return false;
	}

	#### Rueckgabe der Forecast Daten ################################################
	public function getForecast(){
		return $this->fc;
	}

	#### CreateFCVariables ##########################################################
	public function CreateFCVariables($days){
		$cnt = 0;
		foreach($this->fc["daily"] as $fc){
			$varName = ($cnt == 0)? 'Vorhersage Heute' : "Vorhersage Heute + $cnt";
			$id = $this->CreateVariableByName($this->instance,$varName,2, "~Watt");
			
			if($cnt == 0 && date("G")<10 || $cnt > 0) setValue($id, $fc["pv_estimate"]);

			if($cnt >= $days)break;
			$cnt++;
		}

	}

	### LoadForecast from Variable or do calculation ###############################
	public function LoadForecast($force=false){
		$id = $this->CreateVariableByName($this->instance, "PVForecast",3);
		$vdata = IPS_GetVariable($id);
		if($vdata["VariableChanged"] + PVForecastcls::CACHE_AGE < time() || $force){            
			$this->getWeatherForecast($this->dwd_station);
			$this->pvForecast($this->PV);
			$this->SaveToCache();
		}else{
			$this->fc = json_decode(getValue($id),true);
		}
	}

	#### Create Cache Variable #####################################################
	function SaveToCache(){
		$id = $this->CreateVariableByName($this->instance, "PVForecast",3);
		setValue($id, json_encode($this->fc));
	}


	#### Wetterdaten laden  ######################################################
	private function getWeatherForecast($station){
		$this->dwd_loadXML($station);
	}

	#### PV Berechnen ############################################################
	private function pvForecast($PV){
		$lat      = $PV["lat"];
		$lon      = $PV["lon"];
		$this->fc = $this->dwd_fca;

		foreach($this->fc["hourly"] as $k => $fc){         
			$ts       = $fc["ts"];
			$clouds   = $fc["clouds"];
			$temp     =  $fc["temp"];
			
			// SOnnenposition ermitteln
			$ret        = $this->calcSun($ts, $lon, $lat);
			$sun_azimuth    = $ret["azimuth"];
			$sun_elevation  = $ret["elevation"];            

			// Leistungsfaktor festlegen
			$lf = 100;            

			// Beschattung berechnen
			if(isset($PV["obj_direction"])){
				// 2x berechnen und mittelwert bilden für volle stunde und 30min
				for($x=0; $x <=1; $x++){
					$time = $ts + $x * 1800;
					
					$sunpos = $this->calcSun($time, $lon, $lat);
					$alpha   = deg2rad($sunpos["elevation"]);
					$azimuth = $sunpos["azimuth"];
					$shadeAngle  = $azimuth - $PV["obj_direction"] - 90;
					$shadeLength = round($PV["obj_height"] / (tan($alpha) ),2);
					
					if($shadeLength < $PV["obj_distance"] || $shadeAngle >0){
						$lf_minusShadeA[$x] = 100;
					}else{
						$lf_minusShadeA[$x] = $lf*($PV["obj_size"]/100)*$PV["obj_effect"] + $lf*( 1 - ($PV["obj_size"]/100));
					}
				}
				$lf_minusShade = ($lf_minusShadeA[0] + $lf_minusShadeA[1]) / 2;
				$lf = $lf_minusShade;
			} // Objekt

			/* ------------ Kalkulation des Ertrages
			Daten von https://echtsolar.de/wp-content/uploads/2021/02/Ausrichtungstabelle-Photovoltaik-in-Deutschland-768x705.png
			Formel über Newton Polynom: https://de.planetcalc.com/9023/
			*/
			if($PV["pvtype"] == "D"){
				// Dachständerung /\/\/\ 
				/* 1/2 der Leistung muss um 90 grad nach ost und 90 grad nach west gedreht werden*/
				$lfA = $lf_minusShade;
				$lfB = $lf_minusShade;
				
				$az_abwA = round(abs($sun_azimuth - 180 + 90 - $PV["azimuth"]),1);
				$az_abwB = round(abs($sun_azimuth - 180 - 90 - $PV["azimuth"]),1);                
				if($az_abwA > 180 ){
					$lf_minusAA = 90;
				}else{                    
					$lf_minusAA = sin( (1/58) * $az_abwA + 155.5) * 26 + 26;
				}
				if($az_abwB > 180 ){
					$lf_minusBA = 90;
				}else{
					$lf_minusBA = sin( (1/58) * $az_abwB + 155.5) * 26 + 26; // Mit Daten aus Tabelle und näherung über probieren der Sinus-Funktion
				}                
				
				$lfA = $lfA - $lf_minusAA * 0.90; // fällt schwächer ins gewicht
				$lfB = $lfB - $lf_minusBA * 0.90; // fällt schwächer ins gewicht

				// Neigungswikel und Sonne            
				$elev_abw = 90 - $sun_elevation  - $PV["tilt"];
				$x = $elev_abw;
				$lf_minusE   = (1/100) * pow ($x, 2); // Neue Formel anhand Tabellen und Werten 

				if($sun_elevation < @$PV["horizon"] + 5) $lf_minusE = 50; // Sonnenuntergang            
				if($sun_elevation < @$PV["horizon"] ) $lf_minusE = 100; // Sonnenuntergang
				$lf_minusE = round($lf_minusE,1);
				$lfA = $lfA - $lf_minusE  ; // fällt schwächer ins gewicht;
				$lfB = $lfB - $lf_minusE  ; // fällt schwächer ins gewicht;

				// Bewölkung hängt von der Wolkendicke und von dem Einstrahlungswinkel ab.
				$lf_cloud_dec = (100 - (pow($clouds,2.4)/600) * $PV["cloudeffect"]/100) /100;
				
				$lfA = $lfA * $lf_cloud_dec;
				$lfB = $lfB * $lf_cloud_dec;
				$lfA = ($lfA< 0 )? 0 : $lfA;                
				$lfB = ($lfB< 0 )? 0 : $lfB;                
				
				$pv_estimate =  ( $PV["kwp"] / 2) * ($lfA / 100) * $PV["efficiency"]/100;
				$pv_estimate += ( $PV["kwp"] / 2) * ($lfB / 100) * $PV["efficiency"]/100;

				$pv_estimate = $pv_estimate * PVForecastcls::TYPE_D_CORRECTION;

			}else{ // NOrmale Anlage keine /\ Ständerung
				
				$az_abw = round(abs($sun_azimuth - 180 - $PV["azimuth"]),1);
				if($az_abw > 180){
					$lf_minusA = 100;
				}else{
					$lf_minusA = (-0.00000000147) * pow( $az_abw, 5) + (0.00000057668) * pow ($az_abw, 4) + (-0.00008107497) * pow ($az_abw, 3) + (0.00559429998 * pow ($az_abw, 2)) + (0.05160253657 * $az_abw);
				}               
				$lf = $lf - $lf_minusA;

				// Neigungswikel und Sonne            
				$elev_abw = 90 - $sun_elevation  - $A["tilt"];
				$x = $elev_abw;
				$lf_minusE   = (1/100) * pow ($x, 2); // Neue Formel anhand Tabellen und Werten 

				if($sun_elevation < @$A["horizon"] + 5) $lf_minusE = 50; // Sonnenuntergang            
				if($sun_elevation < @$A["horizon"] ) $lf_minusE = 100; // Sonnenuntergang
				$lf_minusE = round($lf_minusE,1);
				$lf = $lf - $lf_minusE;
			
				
				// Bewölkung hängt von der Wolkendicke und von dem Einstrahlungswinkel ab.
				$lf_cloud_dec = (100 - (pow($clouds,2.4)/600) * $PV["cloudeffect"]/100) /100;

				$lf = $lf * $lf_cloud_dec;
				$lf = ($lf < 0 )? 0 : $lf;                
				$pv_estimate = $PV["kwp"] * ($lf / 100) * $PV["efficiency"]/100;
			} // Staenderung

			// Temperaturverlust
		if($temp > 18){
			$tempDelta = $temp - 18;  
			// Annahme MaxTemp = 50 bei 5 Grad Unterschied und keine Wolken
			$tempMinus = $PV["tempkoeff"] * (( (2.917 * $temp) - 27.5) - 25 ) * (100 - $clouds) / 100;               
			$pv_estimate = $pv_estimate * (100-$tempMinus)/100;
		}
		$pv_estimate = round($pv_estimate/10)*10;
		$this->fc["hourly"][$k]["pv_estimate"] = $pv_estimate;

		} // foreach FC

		// Tagesforecast
		$day_fc = 0;
		$d_o =0;
		foreach($this->fc["daily"] as $k => $fc){
			$d = date("z", $fc["ts"]);
			
			if($d_o != $d && $d_o != 0){
					$this->fc["daily"][$k_o]["pv_estimate"] = $day_fc;
					$day_fc = 0;
			}

			foreach($this->fc["hourly"] as $kh => $fch){
				if($d == date("z", $fch["ts"])){
					$day_fc+= $fch["pv_estimate"];
				}
			}

			$k_o = $k;
			$d_o = $d;

		}
	} // function Forecast

	#### checkEvent #####################################################################
	private function checkEvent(){
		global $_IPS;
		$subIDs = IPS_GetChildrenIDs($this->instance);
		$evt = false;
		foreach($subIDs as $id){
			$obj = IPS_GetObject($id);
			if($obj["ObjectType"] == 4)$evt = true;
		}
		if(!$evt){
			$eid = IPS_CreateEvent(1);
			IPS_SetName($eid,"Stündliche Aktualisierung");
			IPS_SetParent($eid, $this->instance);
			IPS_SetEventCyclicTimeFrom($eid, 0, 10, 0);
			IPS_SetEventCyclic($eid,0,0,0,0,3,1);
			IPS_SetEventActive($eid, true);    
		}        
	} // function Event


	#### Sonnnenstandsberechnung  #################################################
	private function calcSun($ts, $dLongitude, $dLatitude){
		// Correction Timezone
		$ts = $ts - 2*3600;

		$iYear = date("Y", $ts);
		$iMonth = date("m", $ts);
		$iDay = date("d", $ts);
		$dHours = date("H", $ts);
		$dMinutes = date("i", $ts);
		$dSeconds = date("s", $ts);

		$pi = 3.14159265358979323846;
		$twopi = (2*$pi);
		$rad = ($pi/180);
		$dEarthMeanRadius = 6371.01;	// In km
		$dAstronomicalUnit = 149597890;	// In km

		// Calculate difference in days between the current Julian Day
		// and JD 2451545.0, which is noon 1 January 2000 Universal Time

		// Calculate time of the day in UT decimal hours
		$dDecimalHours = floatval($dHours) + (floatval($dMinutes) + floatval($dSeconds) / 60.0 ) / 60.0;
		

		// Calculate current Julian Day

		$iYfrom2000 = $iYear;//expects now as YY ;
		$iA= (14 - ($iMonth)) / 12;
		$iM= ($iMonth) + 12 * $iA -3;
		$liAux3=(153 * $iM + 2)/5;
		$liAux4= 365 * ($iYfrom2000 - $iA);
		$liAux5= ( $iYfrom2000 - $iA)/4;
		$dElapsedJulianDays= floatval(($iDay + $liAux3 + $liAux4 + $liAux5 + 59)+ -0.5 + $dDecimalHours/24.0);

		// Calculate ecliptic coordinates (ecliptic longitude and obliquity of the
		// ecliptic in radians but without limiting the angle to be less than 2*Pi
		// (i.e., the result may be greater than 2*Pi)

		$dOmega= 2.1429 - 0.0010394594 * $dElapsedJulianDays;
		$dMeanLongitude = 4.8950630 + 0.017202791698 * $dElapsedJulianDays; // Radians
		$dMeanAnomaly = 6.2400600 + 0.0172019699 * $dElapsedJulianDays;
		$dEclipticLongitude = $dMeanLongitude + 0.03341607 * sin( $dMeanAnomaly ) + 0.00034894 * sin( 2 * $dMeanAnomaly ) -0.0001134 -0.0000203 * sin($dOmega);
		$dEclipticObliquity = 0.4090928 - 6.2140e-9 * $dElapsedJulianDays +0.0000396 * cos($dOmega);

		// Calculate celestial coordinates ( right ascension and declination ) in radians
		// but without limiting the angle to be less than 2*Pi (i.e., the result may be
		// greater than 2*Pi)

		$dSin_EclipticLongitude = sin( $dEclipticLongitude );
		$dY1 = cos( $dEclipticObliquity ) * $dSin_EclipticLongitude;
		$dX1 = cos( $dEclipticLongitude );
		$dRightAscension = atan2( $dY1,$dX1 );
		if( $dRightAscension < 0.0 ) $dRightAscension = $dRightAscension + $twopi;
		$dDeclination = asin( sin( $dEclipticObliquity )* $dSin_EclipticLongitude );

		// Calculate local coordinates ( azimuth and zenith angle ) in degrees

		$dGreenwichMeanSiderealTime = 6.6974243242 +	0.0657098283 * $dElapsedJulianDays + $dDecimalHours;

		$dLocalMeanSiderealTime = ($dGreenwichMeanSiderealTime*15 + $dLongitude)* $rad;
		$dHourAngle = $dLocalMeanSiderealTime - $dRightAscension;
		$dLatitudeInRadians = $dLatitude * $rad;
		$dCos_Latitude = cos( $dLatitudeInRadians );
		$dSin_Latitude = sin( $dLatitudeInRadians );
		$dCos_HourAngle= cos( $dHourAngle );
		$dZenithAngle = (acos( $dCos_Latitude * $dCos_HourAngle * cos($dDeclination) + sin( $dDeclination )* $dSin_Latitude));
		$dY = -sin( $dHourAngle );
		$dX = tan( $dDeclination )* $dCos_Latitude - $dSin_Latitude * $dCos_HourAngle;
		$dAzimuth = atan2( $dY, $dX );
		if ( $dAzimuth < 0.0 )
			$dAzimuth = $dAzimuth + $twopi;
		$dAzimuth = $dAzimuth / $rad;
		// Parallax Correction
		$dParallax = ($dEarthMeanRadius / $dAstronomicalUnit) * sin( $dZenithAngle);
		$dZenithAngle = ($dZenithAngle + $dParallax) / $rad;
		$dElevation = 90 - $dZenithAngle;
			
		return Array("azimuth" => $dAzimuth, "elevation" => $dElevation);
	}

	#### Variable Erzeugen  #######################################################
	private function CreateVariableByName($id, $name, $type, $profile = ""){
		# type: 0=boolean, 1 = integer, 2 = float, 3 = string;
		global $_IPS;
		$vid = @IPS_GetVariableIDByName($name, $id);
		if($vid === false)
		{
			$vid = IPS_CreateVariable($type);
			IPS_SetParent($vid, $id);
			IPS_SetName($vid, $name);
			IPS_SetInfo($vid, "this variable was created by script #".$this->instance);
			if($profile !== "") { IPS_SetVariableCustomProfile($vid, $profile); }
		}
		return $vid;
	}

	#################################################################################
	#### DWD WETTERDATEN ############################################################
	#################################################################################


	private function dwd_loadXML($station){
		//cache verzeichnis anlegen, wenn noch nicht da:
		if(! is_dir(dirname(__FILE__) . "/cache")){
			mkdir(dirname(__FILE__) . "/cache");    
		}
		$url       = "http://opendata.dwd.de/weather/local_forecasts/mos/MOSMIX_L/single_stations/" . $station . "/kml/MOSMIX_L_LATEST_" . $station . ".kmz";
		$fn_cache  = dirname(__FILE__) . "/cache/".$this->instance."-".$station.".cache";
		$fn_xml    = dirname(__FILE__) . "/cache/".$this->instance."-".$station.".xml";

		date_default_timezone_set("Europe/Berlin");
		$response = file_get_contents($url);
		file_put_contents($fn_cache, $response); 
		$zip = new ZipArchive;
		$res = $zip->open($fn_cache);
		if ($res === TRUE) {
			$zc = $zip->statIndex(0);
			$zf = $zc["name"];
			$zip->extractTo(dirname(__FILE__) . "/cache/", $zf);
			$zip->close();
			copy(dirname(__FILE__) . "/cache/".$zf, $fn_xml);
			unlink(dirname(__FILE__) . "/cache/".$zf);
		} else {
			echo 'Fehler, Code:' . $res;
		}

		$xmlstr = file_get_contents($fn_xml);

		// Namespace aufräumen
		$xmlstr = str_replace("<kml:", "<", $xmlstr);
		$xmlstr = str_replace("</kml:", "</", $xmlstr);

		$xmlstr = str_replace("<dwd:", "<", $xmlstr);
		$xmlstr = str_replace("</dwd:", "</", $xmlstr);

		$xmlstr = str_replace(" dwd:", " ", $xmlstr);

		$xml = simplexml_load_string($xmlstr);
		$ts = $xml->Document->ExtendedData->ProductDefinition->ForecastTimeSteps->TimeStep;

		$this->dwd_fca["info"]["model"]=$xml->Document->ExtendedData->ProductDefinition->ProductID->__toString();
		$this->dwd_fca["info"]["generation_time"]=$xml->Document->ExtendedData->ProductDefinition->IssueTime->__toString();


		foreach ($ts as $t) {
			$fc = ["ts" => strtotime($t), 
				"tiso" => $t->__toString(), 
				"day"  =>  date("z", strtotime($t)) - date("z"),
				"hour"  => date("G", strtotime($t))];
			$this->dwd_fca["hourly"][] = $fc;
		}
		//################## DATEN AUS XML EXTRAHIEREN / FLEXIBEL ERWEITERBAR ###########################################
		// Daten aus XML lesen (siehe oben verlinktes excel), 1 Parameter Wertekennung von DWD, 2. Parameter name im Json.
		$this->dwd_getData("Rad1h", "radiation", $xml);
		$this->dwd_getData("RRad1", "radiation_intensity", $xml);
		$this->dwd_getData("Neff", "clouds", $xml);
		$this->dwd_getData("RRL1c", "prec", $xml);
		$this->dwd_getData("RRS1c", "snow", $xml);
		$this->dwd_getData("SunD1", "sun", $xml);
		$this->dwd_getData("T5cm", "temp", $xml);        

		// Tageswerte berechnen
		$dayOld = 0;
		$t_min = 999;
		$t_max = -999;
		$t_avg  = 0;
		$cnt = 0;
		$cloud_avg = 0;
		$prec = 0;
		$snow = 0;
		$sun = 0;
		foreach($this->dwd_fca["hourly"] as $fc){
			$day = date("z", $fc["ts"]) - date("z");
			
			if($day != $dayOld){
			$this->dwd_fca["daily"][$dayOld]["ts"] = $ts_old;
			$this->dwd_fca["daily"][$dayOld]["txtx"] = date("D d.m.Y", $this->dwd_fca["daily"][$dayOld]["ts"]);

			$this->dwd_fca["daily"][$dayOld]["temp_max"] = $t_max;
			$t_max = -999;

			$this->dwd_fca["daily"][$dayOld]["temp_min"] = $t_min;
			$t_min = 999;
			
			$this->dwd_fca["daily"][$dayOld]["temp_avg"] = round($t_avg / $cnt,1);
			$t_avg = 0;

			$this->dwd_fca["daily"][$dayOld]["cloud_avg"] = round($cloud_avg / $cnt);
			$cloud_avg = 0;

			$this->dwd_fca["daily"][$dayOld]["prec"] = $prec;
			$prec=0;

			$this->dwd_fca["daily"][$dayOld]["snow"] = $snow;
			$snow=0;

			$this->dwd_fca["daily"][$dayOld]["sun"] = round($sun / 60);
			$sun=0;

			$cnt = 0;      
			}
			
			$t_min = ($fc["temp"] < $t_min)? $fc["temp"] : $t_min;
			$t_max = ($fc["temp"] > $t_max)? $fc["temp"] : $t_max;
			$cnt++;
			$t_avg     += $fc["temp"];
			$cloud_avg += $fc["clouds"];
			$prec      += $fc["prec"];
			$snow      += $fc["snow"];
			$sun       += $fc["sun"];

			$dayOld = $day;
			$ts_old = mktime(0,0,0, date("m",$fc["ts"]), date("d",$fc["ts"]), date("Y",$fc["ts"]) );
			$tiso   = $fc["tiso"];
		}

	} // DWD loadXML

	#### DWD XML Extraktion
	private function dwd_getData($idstr, $idtxt, &$xml){
		
		$gs = $xml->Document->Placemark->ExtendedData;
		foreach ($gs->Forecast as $g) {
			$id = $g->attributes()["elementName"][0]->__toString();
			if ($id == $idstr) {
			$val = $g->value->__toString();
			$valA = str_split($val, 11);
			}
		}
		foreach($this->dwd_fca["hourly"] as $k => $fc){
			$setval = trim($valA[$k]);
			
			// ############ Aufbereitung der Daten je nach Daten aus XML ################
			if($idstr == "SunD1") $setval = $setval / 60;
			if($idstr == "RRad1") $setval = floatval($setval);
			if($idstr == "T5cm")  $setval = $setval - 273;

			$this->dwd_fca["hourly"][$k][$idtxt] = $setval;
			
		}
	}// dwd_getData;

}


