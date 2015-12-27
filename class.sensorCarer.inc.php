<?php
/* About the file
 *	Name:			class.sensorCarer.inc.php
 *	Description:	---
 *					---
**/
class sensorCarer{
	private $libPath;
	private $mailSettings;
	private $warningLevelFile;
	private $internalErrorIdentifier;
	private $cronSettings;

	function __construct($warningLevelFile = './tmp/errorLevel.csv', $mailSettings = false, $cronSettings = false, $libPath = './lib/'){
		//=== includes and parameter validation ===//
		if(@include_once($libPath.'class.fileManager.inc.php')){
			$pathValidator = new fileManager();
			if(!$pathValidator->validPath($warningLevelFile, false)){
				throw new Exception('Invalid constructor - first parameter must be a valid file path.');
			}
			else{
				$this->warningLevelFile = $warningLevelFile;
			}
		}
		else{
			trigger_error('Unable to include fileManager for path validation.', E_USER_WARNING);
			if(!is_string($warningLevelFile)){
				throw new Exception('Invalid constructor - first parameter must be a string (file path expected).');
			}
			else{
				$this->warningLevelFile = $warningLevelFile;
			}
		}
		
		if($mailSettings != false){
			if(		!isset($mailSettings['server'])
				||	!isset($mailSettings['subject'])
				||	!isset($mailSettings['title'])
				||	!isset($mailSettings['from'])
				||	!isset($mailSettings['recipientsByLevel'])
			){
				return false;
			}
			else{
				$this->mailSettings = $mailSettings;
			}
		}
		else{
			trigger_error('No valid mail settings found.', E_USER_NOTICE);
			$this->mailSettings = false;
		}
		
		if($cronSettings != false){
			if(		!isset($cronSettings['computer'])
				||	!isset($cronSettings['user'])
				||	!isset($cronSettings['password'])
			){
				return false;
			}
			
			if(@include_once($libPath.'class.cronManager.inc.php')){
				$isWin = strpos(PHP_OS, 'WIN') === 0; //0, not false!;
				if($isWin){
					$this->cronManager = new cronManager($cronSettings['password'], $cronSettings['computer'], $cronSettings['user']);
				}
				else{
					if(empty($this->cronSettings['tmpFile'])){
						$this->cronSettings['tmpFile'] = '/tmp/.crontabTemp.txt';
					}
					$this->cronManager = new cronManager($cronSettings['tmpFile']);
				}
			}
		}
		else{
			trigger_error('No valid cron settings found.', E_USER_WARNING);
			$this->cronManager = false;
		}
		
		//=== includes only ===//
		if(!@include_once($libPath.'class.sensor.inc.php')){
			throw new Exception('Unable to include class sensor for sensorCarer.');
		}

		//=== variable initialisation ===//
		$this->values = array();
		$this->libPath = $libPath;
		$this->internalErrorIdentifier = 'internalError';
	}
	
	function care($values = false, $types = false){
		$error = false;
		$recipients = array();
		$levels = array();
		foreach($values as $identifier => $value){
			if(!isset($types[$identifier])){
				trigger_error('Data values without type given.', E_USER_WARNING);
				$error = true;
				$currentErrorLevel = $this->readDataLevel($this->internalErrorIdentifier);
				if(!is_bool($currentErrorLevel) && $currentErrorLevel > 0){
					if(!$this->writeDataLevel($this->internalErrorIdentifier, 1)){
						trigger_error('Unable to write data level.', E_USER_WARNING);
					}
					$recipients = $this->getRecipients(1, 0, $recipients);
				}
			}
			else{
				$timeOfChange = 0;
				$oldLevel = $this->readDataLevel($identifier, $timeOfChange);
				$dynamicLevel = $this->calculateDynamicDataLevel($identifier, $value, $types[$identifier], $oldLevel, $timeOfChange);
				if($oldLevel != $dynamicLevel){
					if(!$this->writeDataLevel($identifier, $dynamicLevel)){
						trigger_error('Unable to write data level.', E_USER_WARNING);
					}
					$this->setCronjob($dynamicLevel, $oldLevel);
					$recipients = $this->getRecipients($dynamicLevel, $oldLevel, $recipients);
				}
				$levels[$identifier] = array($oldLevel, $dynamicLevel);
			}
		}
		
		//--- prepare output ---//
		$maxLevel = NAN;
		$levelCount = array(0 => 0, 1 => 0, 2 => 0, 3 => 0);
		$data = array();
		$increased = array();
		$decreased = array();
		
		foreach($levels as $id => $levelArray){
			//... get highest level ...
			$maxLevel = max($maxLevel, $levelArray[1]);
			if($maxLevel === NAN){ //this have to be checked because of different behaviours between PHP versions / OS
				$maxLevel = max($levelArray[1], $maxLevel);
			}
			//... count levels ...//
			$levelCount[$levelArray[1]]++;
			//... convert data to message line ...//
			if($levelArray[0] != $levelArray[1]){
				$data[] = $id.': '.$this->_levelToString($levelArray[0]).' => '.$this->_levelToString($levelArray[1]).' ('.$values[$id].' ms)';
				if($levelArray[0] < $levelArray[1]){
					$increased[] = $id; 
				}
				else{
					$decreased[] = $id;
				}
			}
			else{
				$data[] = $id.': '.$levelArray[1].' ('.$values[$id].' ms)';
			}
		}
		
		$messages = array();
		if($count = count($increased)){
			$plural = $count > 1;
			if($plural){
				$message = 'The following sensors increased warning level: ';
				foreach($increased as $id){
					$message .= $id.', ';
				}
				$message = substr($message, 0, -2).'.';
				$messages[] = $message;
			}
			else{
				$messages[] = 'The sensor "'.$id.'" increased its warning level.';
			}
		}

		$subject = '[AvailMon]';
		$title = '';
		switch($maxLevel){
			case 0:
				$subject .= ' Green';
				$title .= 'Green light';
			break;
			case 1:
			
				$subject .= ' Yellow';
				if($levelCount[1] > 1){
					$subject .= ' ('.$levelCount[1].')';
					$title .= $levelCount[1].'x ';
				}
				$title .= 'State Yellow';
			break;
			case 2:
				$subject .= ' Orange';
				if($levelCount[2] > 1){
					$subject .= ' ('.$levelCount[2].')';
					$title .= $levelCount[2].'x ';
				}
				$title .= 'State Orange';
				if($levelCount[1] > 0){
					$subject .= ' ('.$levelCount[1].' Yellow)';
					$title .= ' + '.$levelCount[1].'x Yellow';
				}
			break;
			case 3:
				$subject .= ' Red';
				if($levelCount[3] > 1){
					$subject .= ' ('.$levelCount[3].')';
					$title .= $levelCount[3].'x ';
				}
				$title .= 'State Red';
				if($levelCount[2] > 0){
					$subject .= ' ('.$levelCount[2].' Orange)';
					$title .= ' + '.$levelCount[2].'x Orange';
				}
				if($levelCount[1] > 0){
					$subject .= ' ('.$levelCount[1].' Yellow)';
					$title .= ' + '.$levelCount[1].'x Yellow';
				}
			break;
			default:
				$subject .= ' Unknown';
				$title = 'Unknown State';
			break;
		}
		if(!empty($recipients)){ //if the level didn't change, the recipients are empty.	
			//if(!$this->sendMail($recipients, $subject, $title, $messages, $data, $maxLevel)){
			//	trigger_error('Unable to send mail.', E_USER_WARNING);
			//}
		}
		
		$returnInformation = array(
			'title' => $title,
			'messages' => $messages,
			'data' => $data,
			'maxLevel' => $maxLevel,
			'maxLevelString' => $this->_levelToString($maxLevel),
			'recipients' => $recipients
		);
		if(isset($timeOfChange)){
			$returnInformation['timeOfChange'] = $timeOfChange;
		}
		
		return $returnInformation;
	}
	
	private function _levelToString($level){
		switch($level){
			case 3: return 'red';
			case 2: return 'orange';
			case 1: return 'yellow';
			case 0: return 'green';
			default: return 'unknown';
		}
	}

	private function calculateDynamicDataLevel($identifier, $value, $type, $oldLevel, $timeOfChange){
		$redTrigger_minutes = 15;
		$redCoolDownTrigger_minutes = 5;
		$now = time();
		$timeSinceLastLevelChange = $now - $timeOfChange;
		$currentLevel = $this->calculateStaticDataLevel($value, $type);
		
		if($currentLevel >= 2 && $oldLevel == 2 //New level is "Orange" or "Red", old level is "Orange"
			&& $timeSinceLastLevelChange > $redTrigger_minutes * 60 //more then <trigger> minutes past
		){
			return 3; //set level to "red"
		}
		else if($oldLevel == 3 && $currentLevel < 2){ //Old level is "Red", new Level is "Green" or "Yellow"
			if($timeSinceLastLevelChange < $redCoolDownTrigger_minutes * 60 ){ //less then <trigger> minutes past
				return 3;
			}
			else{
				return $currentLevel;
			}
		}
		else if($oldLevel == 3){ //old level is "Red"
			if($redCoolDownTrigger_minutes){
				//refresh "time of change" for usage of "redCoolDownTrigger"
				if(!$this->writeDataLevel($identifier, 3)){
					trigger_error('Unable to write data level.', E_USER_WARNING);
				}
			}
			return 3;
		}
		else{
			return $currentLevel;
		}
	}
	
	private function calculateStaticDataLevel($value, $type){
		$typeFlag = new sensorFlag($type);
		if($typeFlag->getFlagByName('bandWidth')){
			$yellowTrigger_miliseconds = 10 * 1000;
			$orangeTrigger_miliseconds = 60 * 1000;
		}
		else{
			$yellowTrigger_miliseconds = 100; //150;
			$orangeTrigger_miliseconds = 200; //2000;
		}
		if($value < $yellowTrigger_miliseconds){
			return 0; //"green"
		}
		else if($value < $orangeTrigger_miliseconds){
			return 1; //"yellow"
		}
		else{
			return 2; //"orange"
		}
	}
	
	private function readDataLevel($identifier, &$time = NULL){
		if(!file_exists($this->warningLevelFile)){
			return 0;
		}
		else{
			$handle = fopen($this->warningLevelFile, 'r');
			if(!$handle){
				trigger_error('Unable to read warning level file "'.$this->warningLevelFile.'".', E_USER_WARNING);
				return -1;
			}
			if(!include_once($this->libPath.'class.csvManager.inc.php')){
				trigger_error('Unable to include "csvManager". Not error level data read.', E_USER_WARNING);
				return -1;
			}
			$csv = new csvManager();
			$head = fgetcsv($handle, $csv->rowLength, $csv->delimiter);
			if(!in_array('id', $head) || !in_array('level', $head) || !in_array('time', $head)){
				trigger_error('File "'.$this->warningLevelFile.'" exists but has invalid head.', E_USER_WARNING);
				return -1;
			}
			if(!$head){
				trigger_error('File "'.$this->warningLevelFile.'" exists but is empty.', E_USER_NOTICE);
				return 0;
			}
			$result = 0;
			while($row = $csv->fgetcsv_assoc($handle, $head)){
				if(isset($row['id']) && isset($row['level']) && isset($row['time'])){
					if($row['id'] == $identifier){
						$result = $row['level'];
						if($time !== NULL){
							$time = $row['time'];
						}
						break;
					}
				}
				else{
					trigger_error('File "'.$this->warningLevelFile.'" has a row in wrong format.', E_USER_NOTICE);
				}
			}
			fclose($handle);
			return $result;
		}
	}
	
	private function writeDataLevel($identifier, $dataLevel, $timeStamp = false){
		if($timeStamp == false){
			$timeStamp = time();
		}
		if(!include_once($this->libPath.'class.csvManager.inc.php')){
			trigger_error('Unable to include "csvManager". Not warning level data written.', E_USER_WARNING);
			return false;
		}
		$csv = new csvManager();
		$rowToWrite = array('id' => $identifier, 'level' => $dataLevel, 'time' => $timeStamp);
		return $csv->write($this->warningLevelFile, $rowToWrite, 'id');
	}
	
	private function setCronjob($identifier, $newLevel, $oldLevel = false, $minutes = false){
		if(is_object($this->cronManager)){
			$this->isWin = strpos(PHP_OS, 'WIN') === 0; //0, not false!
			
			if(empty($minutes)){
				$this->cronManager->delete($identifier);
			}
			else{
				if($this->isWin){
					$workingDir = exec('cd');
				}
				else{
					$workingDir = exec('pwd');
				}
				
				$executionLine = '"'.$workingDir.'/trigger.php?identifier='.$identifier.'"';
				$executionLine = $this->phpLocation.' '.$executionLine;
					
				$this->cronManager->write($identifier, $executionLine, $minutes, 'm');
			}
		}
		else{
			trigger_error('Unable to change cronjob: There\'s no <i>cronManager</i>.');
		}
	}
	
	private function getRecipients($newLevel, $oldLevel, $recipientsBefore = array()){
		$recipientList = $recipientsBefore;
		$recipientArrayKeys = array();
		
		//--- Key definition by old and new level ---//
		$maxLevel = max($newLevel, $oldLevel);

		for($i = 1; $i <= $maxLevel; $i++){
			$recipientArrayKeys[] = $i;
		}
		
		//--- Get recipients by keys (no duplicate entries) ---//
		foreach($recipientArrayKeys as $index => $recipArrayKey){
			if(isset($this->mailSettings['recipientsByLevel'][$recipArrayKey])){
				if(is_array($this->mailSettings['recipientsByLevel'][$recipArrayKey])){
					foreach($this->mailSettings['recipientsByLevel'][$recipArrayKey] as $recipient){
						if(!in_array($recipient, $recipientList)){
							$recipientList[] = $recipient;
						}
					}
				}
				else if(is_string($this->mailSettings['recipientsByLevel'][$recipArrayKey])){
					if(!in_array($recipient, $recipientList)){
						$recipientList[] = $recipient;
					}
				}
			}
		}
		return $recipientList;
	}
	
	private function sendMail($recipients, $subject, $title, $message, $data, $colorScheme = 'blue'){
		if(!$this->mailSettings){
			return false;
		}
		
		//=== parameter validation ===//
		if(!is_string($this->mailSettings['server'])){
			throw new Exception('Invalid server address: String expected, '.gettype($this->mailSettings['server']).' given.');
		}
		if(!is_string($subject)){
			throw new Exception('Invalid parameter #2: Subject string expected, '.gettype($subject).' given.');
		}
		if(!is_string($title)){
			throw new Exception('Invalid parameter #3: Message title string expected, '.gettype($title).' given.');
		}
		
		//--- mail pattern ---//
		$mailPattern = '[-+\\.0-9=a-zA-Z_]+@([-0-9a-zA-Z]+\\.)+([0-9a-zA-Z]){2,4}';
		$mailValidationPattern = '/';
		$mailValidationPattern .= '^'.$mailPattern.'$';
		$mailValidationPattern .= '|'; // OR
		$mailValidationPattern .= '^'.'<'.$mailPattern.'>'.'$';
		$mailValidationPattern .= '|'; // OR
		$mailValidationPattern .= '^'.'[-+\\.0-9=a-zA-Z_]{1,}[\\s]<'.$mailPattern.'>'.'$';
		$mailValidationPattern .= '|'; // OR
		$mailValidationPattern .= '^'.'"[-+\\.0-9=a-zA-Z_\\s]{1,}"[\\s]<'.$mailPattern.'>'.'$';
		$mailValidationPattern .= '/';
		
		//--- mail validation ---//
		if(!is_string($this->mailSettings['from']) || !preg_match($mailValidationPattern, $this->mailSettings['from'])){
			throw new Exception('Invalid mail setting "mailFrom": Senders address expected.');
		}
		if(!is_string($this->mailSettings['reply']) || !preg_match($mailValidationPattern, $this->mailSettings['reply'])){
			throw new Exception('Invalid mail setting "replyTo": Reply address expected.');
		}
		if(!is_array($recipients)){
			throw new Exception('Invalid parameter #1: Recipient array expected.');
		}
		foreach($recipients as $mail){
			if(!is_string($mail) || !preg_match($mailValidationPattern, $mail)){
				throw new Exception('Invalid parameter #1: Recipient array with mail adresses expected.');
			}
		}
		if(!is_array($message)){
			throw new Exception('Invalid parameter #4: Message array expected.');
		}
		foreach($message as $paragraph){
			if(!is_string($paragraph)){
				throw new Exception('Invalid parameter #4: Message string array expected.');
			}
		}
		if(!is_array($data)){
			throw new Exception('Invalid parameter #5: Data array expected.');
		}
		
		//--- color scheme interpretation ---//
		if(is_string($colorScheme)){
			$colorScheme = strtolower($colorScheme);
		}
		switch($colorScheme){
			default: case 'blue':
				$headingColor = '#000060';
				$headingShadowColor = '#c0c0f0';
				$backgroundColor = '#f0f0ff';
				$borderColor = '#0000cc';
			break;
			case 3: case '3': case 'red':
				$headingColor = '#900000';
				$headingShadowColor = '#f0c0c0';
				$backgroundColor = '#ffc0c0';
				$borderColor = '#cc0000';
			break;
			case 2: case '2': case 'orange':
				$headingColor = '#c09000';
				$headingShadowColor = '#f0d9c0';
				$backgroundColor = '#ffd090';
				$borderColor = '#cc6600';
			break;
			case 1: case '1': case 'yellow':
				$headingColor = '#c0c000';
				$headingShadowColor = '#f0f0c0';
				$backgroundColor = '#ffff90';
				$borderColor = '#cccc00';
			break;
			case 0: case '0': case 'green':
				$headingColor = '#00e000';
				$headingShadowColor = '#c0f0c0';
				$backgroundColor = '#c0ffc0';
				$borderColor = '#00cc00';
			break;
		}

		//=== message style ===//
		$html = '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">';
		$html .= '<html xmlns="http://www.w3.org/1999/xhtml" xml:lang="de" lang="de">';
		$html .= "\n\t".'<head>';
		$html .= "\n\t\t<title>$title</title>";
		$html .= "\n\t\t".'<meta http-equiv="Content-type" content="text/html"; charset="utf-8" />';
		$html .= "\n\t\t".'<style>';
		
		$html .= "\n\t\t".'<!--';
		$html .= '
			body{
				background-color: '.$backgroundColor.';
				font-family: Tahoma,Arial,Verdana,sans-serif;
			}
			h1{
				color: '.$headingColor.';
				text-shadow: '.$headingShadowColor.' 3px 3px 3px;
				font-size: 32px;
			}
			ul{
				margin: 5px;
				list-style-type: none;
				padding: 0px;
			}
			.box{
				border: 2px outset '.$borderColor.';
				background-color: #ffffff;
				margin: 0 auto;
				width: 500px;
				padding: 10px;
				text-align: center;
				line-height: 1.5em;
				border-radius: 20px;
			}
			.subbox{
				border: 1px inset '.$borderColor.';
				background-color: '.$backgroundColor.';
				line-height: 1em;
				padding: 5px;
				border-radius: 10px;
			}
			.subbox ul{
				font-family: monospace;
			}';
		$html .= "\n\t\t".'-->';
		
		$html .= "\n\t\t".'</style>';
		$html .= "\n\t".'</head>';
		$html .= "\n\t".'<body>';
		$html .= "\n\t\t".'<div class="box">';
		$html .= "\n\t\t\t<h1>$title</h1>";
		foreach($message as &$messageBlock){
			$html .= "\n\t\t\t\t<p>$messageBlock</p>";
		}
		$html .= "\n\t\t\t".'<div class="subbox">Values:';
		$html .= "\n\t\t\t\t<ul>\n";
		foreach($data as &$dataRow){
			$html .= "\n\t\t\t\t\t<li>$dataRow</li>";
		}
		$html .= "\n\t\t\t\t</ul>\n";
		$html .= "\t\t\t</div>\n";
		$html .= "\t\t</div>\n";
		$html .= "\t</body>\n";
		$html .= "</html>\n";
		
		//=== message submit ===//
		$headers = 'MIME-Version: 1.0';
		$headers .= "\n".'Content-Type: text/html; charset="utf-8"';
		$headers .= "\n".'From: '.$this->mailSettings['from'];
		if(!empty($this->mailSettings['reply'])){
			$headers .= "\n".'Reply-To: '.$this->mailSettings['reply'];
		}
		ini_set('SMTP', $this->mailSettings['server']);
		return mail(implode(', ', $recipients), $subject, $html, $headers);
	}
}
