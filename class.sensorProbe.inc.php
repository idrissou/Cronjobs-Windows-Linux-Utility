<?php
/* About the file
 *	Name:			class.sensorProbe.inc.php
 *	Description:	---
 *					---
**/
if(!isset($libPath)){
	$libPath = './';
}
if(!@include_once($libPath.'class.sensor.inc.php')){
	throw new Exception('Unable to include sensor for sensorProbe.');
}

class sensorProbe extends sensor{
	//=== sensor attributes ===//
	//-- protected $typeFlags;
	//-- protected $identifier;
	//-- protected $sourcePath;
	//-- protected $targetPath;
	//-- protected $username;
	//-- protected $password;
	//-- protected $subpath;
	//-- protected $version;
	
	function evaluate(){
		$isWin = strpos(PHP_OS, 'WIN') === 0;
		
		$start = 0;
		$end = 0;
		$time = 0;
		$returnString = '';
		//=== read flags ===//
		
		//--- band width check ---//
		if($this->typeFlags->getFlagByName('bandWidth')){
			if(file_exists($this->sourcePath)){
				unlink($this->sourcePath);
			}
			$start = microtime_ms();
			//... execute bandwidth sensor ...//
			if(empty($this->targetPath)){
				trigger_error('Target path is empty.', E_USER_WARNING);
				$time = -1;
			}
			else if(empty($this->sourcePath)){
				trigger_error('Source path is empty.', E_USER_WARNING);
				$time = -1;
			}
			else{
				copy($this->targetPath, $this->sourcePath); //Attention: "target" will be copied to "source"!
			}
			//...............//
			$end = microtime_ms();
			if(file_exists($this->sourcePath)){
				unlink($this->sourcePath);
			}
			$time += $end - $start;
		}
		
		//--- database server check ---//
		if($this->typeFlags->getFlagByName('sql')){
			if($this->typeFlags->getFlagByName('sensorPage') || $this->sourcePath){
				//... use extern sensor ...//
				if($returnTime > 0){
					$time += $returnTime;
				}
				else if($time > 0){
					$time *= -1;
				}
				else{
					$time = -1;
				}
				//...............//
			}
			else{
				$start = microtime_ms();
				//... execute SQL sensor...//
				$connection = mysql_connect($this->targetPath, $this->username, $this->password);
				if($this->subpath){
					$success = mysql_select_db($this->subpath);
					$query = 'SHOW TABLES FROM `'.$this->subpath.'`';
				}
				else{
					$query = 'SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES';
				}
				$success = mysql_query($query);
				//...............//
				$end = microtime_ms();
				if($time < 0){
					$success = false;
				}
				$time = abs($time) + $end - $start;
				if(!$success){
					$time *= -1;
				}
			}
		}
		
		else if($this->typeFlags->getFlagByName('sensorPage')){
			if(!$this->sourcePath && !$this->targetPath){
				$start = microtime_ms();
				//... execute PHP-sensor ...//
				for($i = 0; $i < 10000; $i++){
					//slow down
					$magic = 1;
					for($j = 0; $j <= 5; $j++){
						$magic *= pow($j, $j);
						if($j === 3){
							$note = "A Mala has $magic beads.";
						}
					}
					$magic -= 24 * 60 * 60 * 1000;
					
					if(($end - $start) > $timeout){
						$timeouted = true;
						break;
					}
				}
				//...............//
				$end = microtime_ms();
				$time += $end - $start;
			}
			else{
				//... use extern sensor ...//
				$returnTime = $this->evaluateExtern();
				if($returnTime > 0){
					$time += $returnTime;
				}
				else if($time > 0){
					$time *= -1;
				}
				else{
					$time = -1;
				}
				//...............//
			}
		}
		
		else if($this->typeFlags->getFlagByName('ping')){
			if($this->sourcePath && $this->targetPath){
				$returnTime = $this->evaluateExtern();
				if($returnTime > 0){
					$time += $returnTime;
				}
				else if($time > 0){
					$time *= -1;
				}
				else{
					$time = -1;
				}
			}
			else{
				$path = $this->targetPath?$this->targetPath:$this->sourcePath;
				$pingCount = $this->subpath;
				if(!is_numeric($pings) || $pings < 1 || $pings > 100){
					$pingCount = 1;
				}
				
				$result = exec('ping -'.($isWin?'n':'c').' '.$pingCount.' '.$path);
				if($isWin){
					$averageTime = substr($result, strrpos($result, ' ') + 1, -2);
				}
				else{
					$posOfEqualSign = strpos($result, '=');
					$posOfFirstSlashAfterEqual = strpos($result, '=', $posOfEqualSign);
					$posOfSecondSlashAfterEqual = strpos($result, '=', $posOfFirstSlashAfterEqual);
					$averageTime = substr($result, $posOfFirstSlashAfterEqual + 1, $posOfSecondSlashAfterEqual - $posOfFirstSlashAfterEqual - 1);
				}
				if(is_numeric($averageTime)){
					$time += round($averageTime);
				}
				else{
					if($time > 0){
						$time *= 1;
					}
					else if($time == 0){
						$time = -1;
					}
				}
			}
		}
		
		else if(!$this->typeFlags->getFlagByName('bandWidth')){
			$returnTime = $this->evaluateExtern();
			if($returnTime > 0){
				$time += $returnTime;
			}
			else if($time > 0){
				$time *= -1;
			}
			else{
				$time = -1;
			}
		}
		return $time;
	}
	
	/* evaluateExtern()
	 *	Gets value from extern sensor (milliseconds as integer)
	 *	Returns -1 on error
	**/
	private function evaluateExtern(){
		$path = $this->sourcePath?$this->sourcePath:$this->targetPath;
		if($path == ''){
			return -1;
		}
		if(strpos($path, '?') === false){
			$url = $path.'?visit='.md5($this->identifier);
		}
		else if(strpos($path, '?visit=') === false && strpos($path, '&visit=') === false ){
			$url = $path.'&visit='.md5($this->identifier);
		}
		else{
			$url = $path;
		}
		$timeString = @file_get_contents($url);
		if(empty($timeString)){
			trigger_error('Unable to get contents from '.$path, E_USER_NOTICE);
		}
		if(strpos($timeString, ' ') === false){
			$value = (int) $timeString;
		}
		else{
			$value = (int) substr($timeString, 0, strpos($timeString, ' '));
		}
		if(!is_numeric($value)){
			return -1;
		}
		else{
			return $value;
		}
	}
}

/* microtime_ms()
 *	Returns result of microtime() as a integer, converted to milliseconds
**/
function microtime_ms(){
	list($usec, $sec) = explode(' ', microtime());
	return (int) (((float) $usec + (float) $sec) * 1000);
}
?>