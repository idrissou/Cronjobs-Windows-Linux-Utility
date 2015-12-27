<?php
/* About the file
 *	Name:			class.sensorManager.inc.php
 *	Description:	Offers functionality to read, create, edit and delete sensor data.
 *					---
**/

class sensorManager{

	private $isWin;
	private $sensorListPath;
	private $csvManager;
	private $mandatoryColumns;
	private $optionalColumns;
	private $idCol;
	private $cronManager;

	/* Constructor
	 *	Expects path of sensor list file.
	 *	Requires csvManager and try to use fileManager.
	**/
	function __construct($sensorListPath, $phpLocation = 'php', $libPath = './lib/', $settings = array()){
		//=== include libraries and validate parameter ===//
		if(!@include_once($libPath.'class.csvManager.inc.php')){
			throw new Exception('Unable to include csvNabager for sensorManager.');
		}
		$this->csvManager = new csvManager();
		if(@include_once($libPath.'class.fileManager.inc.php')){
			$pathValidator = new fileManager();
			if(!$pathValidator->validPath($sensorListPath, false)){
				throw new Exception('Invalid constructor - first parameter must be a valid file path.');
			}
			else{
				$this->sensorListPath = $sensorListPath;
			}
		}
		else{
			trigger_error('Unable to include fileManager for path validation.', E_USER_WARNING);
			if(!is_string($sensorListPath)){
				throw new Exception('Invalid constructor - first parameter must be a string (file path expected).');
			}
		}
		
		$this->isWin = strpos(PHP_OS, 'WIN') === 0; //0, not false!
		if(@include_once($libPath.'class.cronManager.inc.php')){
			if($this->isWin){
				$this->cronManager = new cronManager($settings['password'], $settings['computer'], $settings['user']);
			}
			else{
				if(empty($settings['tmpFile'])){
					$settings['tmpFile'] = '/tmp/.crontabTemp.txt';
				}
				$this->cronManager = new cronManager($settings['tmpFile']);
			}
		}
		else{
			throw new Exception('Unable to include cronManager for path validation.');
		}
		
		
		//=== define attributes ===//
		$this->mandatoryColumns = array(
			'id', 'type', 'target', 'green-rec-min', 'orange-rec-min', 'yellow-trigger-ms', 'orange-trigger-ms'
		);
		$this->optionalColumns = array(
			'source', 'user', 'pass', 'subpath', 'yellow-rec-min', 'red-rec-min', 'red-trigger-min'
		);
		$this->idCol = $this->mandatoryColumns[0];
		$this->phpLocation = $phpLocation;
	}
	
	/* listSensors()
	 *	Reads every line of sensor list file.
	 *	Returns array of data arrays.
	 *	Alias for readSensorRow(false);
	**/
	function listSensors(){
		return $this->readSensor(false);
	}
	
	/* readSensorRow()
	 *	Reads a line from sensor list file.
	 *	Reads every line if first parameter is false,
	 *	otherwise first parameter must be a identifier-string.
	 *	Returns sensor data array or array of sensor data arrays.
	 *	Returns and empty array if there are no entries.
	 *	Returns false on error.
	**/
	function readSensor($identifier = false){
		if(!file_exists($this->sensorListPath)){
			trigger_error('Sensor list file "'.$this->sensorListPath.'" not found.', E_USER_NOTICE);
			return array();
		}

		$fileHandle = fopen($this->sensorListPath, 'r');
		if(!$fileHandle){
			return false;
		}
		$head = fgetcsv($fileHandle, $this->csvManager->rowLength, $this->csvManager->delimiter, $this->csvManager->enclosure, $this->csvManager->escape);
		if(!$head){
			return false;
		}

		$sensors = array();
		while($assocRow = $this->csvManager->fgetcsv_assoc($fileHandle, $head)){
			if($assocRow = $this->validateRow($assocRow)){
				if(!$identifier || $identifier == $assocRow[$this->idCol]){	
					$sensors[$assocRow[$this->idCol]] = $assocRow;
				}
			}
			else{
				continue;
			}
		}
		fclose($fileHandle);
		
		if($identifier && count($sensors) && isset($sensors[$identifier])){
			return $sensors[$identifier];
		}
		else{
			return $sensors;
		}
	}
	
	/* writeSensor()
	 *	Adds or edits a sensor on sensor list file.
	 *	Returns nothing, throws exception on error.
	**/
	function writeSensor($values){
		if($values = $this->validateRow($values)){
			if(!$this->csvManager->write($this->sensorListPath, $values, $this->idCol)){
				throw new Exception('Unable to write sensor into file: Internal error.');
			}
			else{
				$this->writeCron($values);
			}
		}
		else{
			throw new Exception('Invalid data format.');
		}
	}
	
	private function writeCron($values){
		$identifier = $values[$this->idCol];
		if(count($values) === 1){
			$this->cronManager->delete($identifier);
		}
		else{
			$minutes = $values['minutes'];
			$executionLine = '"'.$workingDir.'/trigger.php?identifier='.$identifier.'"';
			if($this->isWin){
				$executionLine = $this->phpLocation.' '.$executionLine;
			}
			else{
				$workingDir = exec('pwd');
				$executionLine = $this->phpLocation.' '.$executionLine;
			}
			$this->cronManager->write($identifier, $executionLine, $minutes, 'm');
		}
	}
	
	/* deleteSensor()
	 *	Deletes a sensor from sensor list file.
	 *	Uses writeSensor() with empty values for file deletion.
	 *	Returns nothing, throws exception on error.
	**/
	function deleteSensor($identifier){
		$this->writeSensor(array($this->idCol => $identifier)); //Only id without values triggers deletion.
	}

	/* validateRow()
	 *	Checks whether data from or for the CSV file is well formatted.
	 *	Returns a well formatted row or false if it is invalid.
	**/
	private function validateRow($values){
		$formattedValues = array();

		foreach($this->mandatoryColumns as $index => $mandatory){
			if(!isset($values[$mandatory]) || $values[$mandatory] === false){
				return false;
			}
			else{
				$formattedValues[$mandatory] = $values[$mandatory];
			}
		}
		
		foreach($this->optionalColumns as $optional){
			if(!isset($values[$optional])){
				$formattedValues[$optional] = '';
			}
			else{
				$formattedValues[$optional] = $values[$optional];
			}
		}
		return $formattedValues;
	}
}