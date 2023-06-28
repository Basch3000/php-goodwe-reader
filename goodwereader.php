<?php
class GoodWeReader
{
	private $sIP = "";
	private $iPort = 0;
	private $bLog = true;

	private $sPayload = "";

	public function __construct(string $sIP, int $iPort = 8899)
	{
		$this->sIP = $sIP;
		$this->iPort = $iPort;
	}

	public function fetchInfo()
	{
		$sData = $this->readInverter();
		if($sData == false)
		{
			$this->logMessage("Incorrect response from inverter.");
			return;
		}

		if(substr($sData, 0, 2) != "\xaa\x55")
		{
			$this->logMessage("Invalid header response from inverter.");
			return;
		}

		$sCRC = substr($sData, -2);
		$this->sPayload = substr($sData, 2, 149);

		if($sCRC != $this->calcCrc())
		{
			$this->logMessage("Payload integrity could not be verified.");
			return;
		}

		$this->logMessage("Payload fetched and integrity verified.");
		$this->logMessage("Preparing response.");

		$aReturn = [
			"datetime" => $this->getDateTime(),
			"string_1" => [
				"voltage" => $this->getPart(9),
				"current" => $this->getPart(11),
			],
			"string_2" => [
				"voltage" => $this->getPart(13),
				"current" => $this->getPart(15)
			],
			"string_3" => [
				"voltage" => $this->getPart(17),
				"current" => $this->getPart(19)
			],
			"string_4" => [
				"voltage" => $this->getPart(21),
				"current" => $this->getPart(23)
			],

			"phase_1" => [
				"voltage" => $this->getPart(39),
				"current" => $this->getPart(45),
				"frequency" => $this->getPart(51, 100)
			],

			"phase_2" => [
				"voltage" => $this->getPart(41),
				"current" => $this->getPart(47),
				"frequency" => $this->getPart(53, 100)
			],

			"phase_3" => [
				"voltage" => $this->getPart(43),
				"current" => $this->getPart(49),
				"frequency" => $this->getPart(55, 100)
			],

			"power_to_grid" => $this->getPart(59, 0),
            "temperature"   => $this->getPart(85),
            "yield_today"   => $this->getPart(91),
            "yield_total"   => unpack("N", substr($this->sPayload, 93, 4))[1] / 10,
            "working_hours" => $this->getPart(99, 0)
		];

		for($i = 1; $i < 5; $i++)
		{
			if($aReturn["string_".$i]["voltage"] == 6553.5)
			{
				// removes string not connected or not supported by this inverter
				unset($aReturn["string_".$i]);
				continue;
			}
			// calculates the produced power of the string. not data from inverter.
			$aReturn["string_".$i]["power"] = $aReturn["string_".$i]["voltage"] * $aReturn["string_".$i]["current"];
		}

		for($i = 1; $i < 4; $i++)
		{
			if($aReturn["phase_".$i]["voltage"] == 6553.5)
			{
				// removes phases 2 and 3 for single phase inverters
				unset($aReturn["phase_".$i]);
				continue;
			}
			// calculates the produced power to the phase. not data from inverter. seems incorrect at dusk
			$aReturn["phase_".$i]["power"] = $aReturn["phase_".$i]["voltage"] * $aReturn["phase_".$i]["current"];
		}

		$this->logMessage("Done.");

		return $aReturn;
	}

	private function getPart(int $iPos, $iDivider = 10)
	{
		if($iDivider) return unpack("n", substr($this->sPayload, $iPos, 2))[1] / $iDivider;
		return unpack("n", substr($this->sPayload, $iPos, 2))[1];
	}

	private function getDateTime()
	{
		$sDateTime = "20".ord($this->sPayload[3])."-".str_pad(ord($this->sPayload[4]), 2, "0", STR_PAD_LEFT)."-".str_pad(ord($this->sPayload[5]), 2, "0", STR_PAD_LEFT);
		$sDateTime .= " ".str_pad(ord($this->sPayload[6]), 2, "0", STR_PAD_LEFT).":".str_pad(ord($this->sPayload[7]), 2, "0", STR_PAD_LEFT).":".str_pad(ord($this->sPayload[8]), 2, "0", STR_PAD_LEFT);
		return $sDateTime;
	}

	private function readInverter()
	{
		$this->logMessage("Connecting to inverter on ".$this->sIP.":".$this->iPort."...");

		$oSock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		socket_set_option($oSock, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 2, 'usec' => 0]);
		$bSuccess = socket_connect($oSock, "192.168.1.47", 8899);
		if(!$bSuccess) return false;

		$sRequest = "\x7f\x03\x75\x94\x00\x49\xd5\xc2";
		socket_send($oSock, $sRequest, strlen($sRequest), 0);

		$sData = socket_read($oSock, 1024);

		socket_close($oSock);

		if(strlen($sData) != 153) return false;

		return $sData;
	}

	private function calcCrc()
	{
		$iCRC = 0xFFFF;
		for($i = 0; $i < strlen($this->sPayload); $i++)
		{
			$iCRC ^= ord($this->sPayload[$i]);
			for($j = 0; $j < 8; $j++)
			{
				$bOdd = ($iCRC & 0x0001) != 0;
				$iCRC >>= 1;
				if($bOdd) $iCRC ^= 0xA001;
			}
		}
		return pack("v", $iCRC);
	}

	private function logMessage($sMessage)
	{
		if($this->bLog != true) return;
		echo("[".date("H:i:s")."] ".$sMessage."\n");
	}
}

$oGoodWeReader = new GoodWeReader("192.168.1.47");
$aOutput = $oGoodWeReader->fetchInfo();

var_dump($aOutput);

