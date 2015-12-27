<?php
/* About the file
 *	Name:			class.logManager.inc.php
 *	Description:	This class handles date-separated logfiles with
 *					summaries and flow files (alternative log files,
 *					created when the scheduled file is locked)
**/
class logManager{
	private $paths;
	private $maxFlowFiles;
	private $logDelimiter;
	private $dateDelimiter;
	private $dateColumnLabel;
	private $stateColumnLabel;
	private $fileManagerPath;
	private $csvManagerPath;
	
	/* Constructor
	 *	Initializes attributes
	 *		- by parameters ($this->path & $this->maxFlowFiles)
	 *		- staticly (all other)
	**/
	function __construct($paths = './logs/', $maxFlowFiles = true, $libPath = './lib/'){
	
		//--- set attributes ---//
		$this->logDelimiter		= ';';
		$this->dateDelimiter 	= '#';
		$this->dateColumnLabel 	= 'Date';
		$this->stateColumnLabel = 'Complete';
		$this->fileManagerPath 	= $libPath.'class.fileManager.inc.php';
		$this->csvManagerPath 	= $libPath.'class.csvManager.inc.php';
		if(is_bool($maxFlowFiles)){
			if($maxFlowFiles){
				$maxFlowFiles = 10;
			}
			else{
				$maxFlowFiles = 0;
			}
		}
		
		//--- validate parameters ---//
		$errorFlag = 0;
		$errorMessage = '';
		$newPaths = $this->validPathArray($paths, $errorMessage);
		if(is_array($newPaths)){
			$this->paths = $newPaths;
		}
		else{
			throw new Exception('Invalid 1st parameter for logManager-constructor: '.$errorMessage);
		}
		
		if(is_numeric($maxFlowFiles) && $maxFlowFiles >= 0 && $maxFlowFiles <= 1000000){
			$this->maxFlowFiles = $maxFlowFiles;
		}
		else{
			throw new Exception('Invalid 2nd parameter for logManager-constructor.');
		}
	}
	
	/* get()
	 *	This Getter function will read private attributes.
	**/
	function get($attribute){
		if(isset($this->$attribute)){
			return $this->$attribute;
		}
		else{
			trigger_error('Invalid attribute.', E_USER_WARNING);
			return false;
		}
	}
	
	/* validPathArray()
	 *	Validates array or interprets string for logfile paths.
	 *	Class fileManager is required.
	**/
	function validPathArray($paths = './', &$errorMessage){
		$validKeys = array('all', 'year', 'month', 'day');
		//--- include used methods ---//
		include_once($this->fileManagerPath);
		if(!class_exists('fileManager')){
			$errorMessage = 'Unable to include "fileManager" (path="'.$this->fileManagerPath.'")';
			return false;
		}
		
		//--- check syntax and return true or false ---//
		if(is_array($paths)){
			foreach($paths as $name => &$path){
				$name_ci = strtolower($name);
				if(in_array($name_ci, $validKeys)){
					if(fileManager::validPath($path, false, $this->dateDelimiter)){
						if(!(substr_count($path, $this->dateDelimiter) % 2)){
							if($name_ci != $name && !isset($paths[$name_ci])){
								$paths[$name_ci] = $path;
							}
							continue;
						}
						else{
							$errorMessage = 'Invalid amount of date delimiter ("'.$this->dateDelimiter.'") found: '.$name.' => '.$path;
							return false;
						}
					}
					else{
						$errorMessage = 'Invalid file path found: '.$name.' => '.$path;
						return false;
					}
				}
			}
			return $paths;
		}
		//--- check if an existing directory with writing access is given, create array or return a boolean ---//
		else if(is_string($paths)){
			if(fileManager::validPath($paths, true, $this->dateDelimiter)){
				$handle = fopen($paths.'logManagerTestFile.txt', 'x');
				if($handle){
					$message = 'This is a test file, created';
					$message .= ' by the script '. $_SERVER['PHP_SELF'];
					$message .= ' with the file'.__FILE__;
					$message .= ' at '.date('Y-m-d H:i:s e');
					$message .= '.'."\n";
					$message .= 'That this file still exists is caused by a failure on file deletion.';
					$message .= ' You can delete it unconcerned.';
					$written = fwrite($handle, $message);
					fclose($handle);
					unlink($paths.'logManagerTestFile.txt');
				}
				else{
					$written = false;
				}
				
				if(!$written){
					$errorMessage = 'Unable to write into given directory ("'.$paths.'").';
					return false;
				}
				else{
					$newPaths = array(
						'all' => $paths.'years.csv',
						'year' => $paths.$this->dateDelimiter.'Y'.$this->dateDelimiter.'.csv',
						'month' => $paths.$this->dateDelimiter.'Y-F'.$this->dateDelimiter.'.csv',
						'day' => $paths.$this->dateDelimiter.'Y-m-d'.$this->dateDelimiter.'.csv',
					);
					return $newPaths;
				}
			}
			else{
				$errorMessage = 'Path is no valid directory (syntax)';
				return false;
			}
		}
		//--- no array + no string = invalid
		else{
			$errorMessage = 'Invalid parameter: String or array expected, '.gettype($paths).' given.';
			return false;
		}
	}
	
	/* ()
	 *	Reads data from the logfiles by using the following functions:
	 *	- getFileToRead()
	 *	- getDataFromFile()
	 *	- blurredDate
	**/
	function extractData($startTimeStamp, $endTimeStamp, $timeSpanType, $iterator){
		$file = $this->getIOFile($startTimeStamp, $timeSpanType);
		$this->mergeFlowFiles($file);
		$accuracy = 1;
		if($timeSpanType == 'day'){
			$accuracy = 5;
		}
		return $this->getDataFromFile($file, $startTimeStamp, $endTimeStamp, $timeSpanType, $accuracy);
	}
	
	/* getIOFile()
	 *	Gets the file fitting to timestamp-timespantype-combination
	 *	from $this->paths.
	**/
	function getIOFile($timeStamp, $timeSpanType){
		if($timeSpanType == 'all'){
			return $this->paths['all'];
		}
		if(!isset($this->paths[$timeSpanType])){
			return false;
		}
		else{
			$pathToInterpret = $this->paths[$timeSpanType];
		}
	
		//--- get positons of every date string ---
		$pathLength = strlen($pathToInterpret);
		$inDateString = false;
		$dateStringPositions = array();
		$startPos = -1;
		for($i = 0; $i < $pathLength; $i++){
			if($pathToInterpret[$i] == $this->dateDelimiter){
				if(!$inDateString){
					$startPos = $i;
					$inDateString = true;
				}
				else{
					if($i - 1 >= $startPos + 1){
						$dateStringPositions[] = array('start' => $startPos, 'end' => $i);	
					}
					$inDateString = false;
				}
			}
		}
		if($inDateString){
			throw new Exception('An error occured while interpreting a log file path: A date string wasn\'t closed.');
		}
		
		//--- get content of every date string ---
		$dateStringContents = array();
		foreach($dateStringPositions as $i => $positions){
			$dateStringContents[$i] = substr($pathToInterpret, $positions['start'] + 1, $positions['end'] - $positions['start'] - 1);
		}
		
		//--- calculate content for every date string ---
		foreach($dateStringContents as $i => $content){
			$dateStringContents[$i] = date($content, $timeStamp);
		}
		
		//--- replace every content of every date string ---
		$returnPath = $pathToInterpret;
		$positionOffset = 0;
		foreach($dateStringPositions as $i => $positions){
			if($positions['start'] != $positions['end'] + 1){
				$returnPath = substr_replace($returnPath, $dateStringContents[$i], $positions['start'] - $positionOffset, $positions['end'] - $positions['start'] + 1);
				$positionOffset += 2;
			}
		}
		
		//--- replace escaped date string chars ---
		$returnPath = str_replace($this->dateDelimiter.$this->dateDelimiter, $this->dateDelimiter, $returnPath);

		return $returnPath;
	}
	
	/* getDataFromFile()
	 *	Reads log or log summary and returns content of a given time span.
	**/
	function getDataFromFile($file, $startTimeStamp, $endTimeStamp, $fileType = 'day', $accuracy = 1){
		if(file_exists($file)){
			$fileHandle = fopen($file, 'r');
			if(!$fileHandle){
				return false;
			}
		}
		else{
			return array();
		}

		//--- include used methods ---//
		include_once($this->csvManagerPath);
		if(!class_exists('csvManager')){
			throw new Exception('Unable to include "csvManager".');
			return false;
		}
		
		$csvReader = new csvManager();
		$csvReader->delimiter = $this->logDelimiter;;
		$blurredData = array();
		$missingDateColumnCount = 0;
		$rowCount = 0;
		$firstRow = fgetcsv($fileHandle, $csvReader->rowLength, $csvReader->delimiter);
		while ($row = $csvReader->fgetcsv_assoc($fileHandle, $firstRow)){
			$rowCount++;
			if(!isset($row[$this->dateColumnLabel])){
				$missingDateColumnCount++;
				continue;
			}
			$timeStamp = strtotime($row[$this->dateColumnLabel]);
			if($timeStamp != -1 && $timeStamp !== false
				&& ($startTimeStamp <= 0 || $timeStamp >= $startTimeStamp)
				&& ($endTimeStamp <= 0 || $timeStamp <= $endTimeStamp)
			){
				$blurredDate = $this->blurredDate($timeStamp, $fileType, $accuracy);
				foreach($row as $label => $value){
					$interpret = true;
					switch($fileType){
						default:
						case 'month':
						case 'year':
						case 'all':
							if($label == $this->stateColumnLabel){
								$interpret = false;
							}
						//continue
						case 'day':
							if($label == $this->dateColumnLabel){
								$interpret = false;
							}
						break;
					}
					if($interpret && is_numeric($value)){
						$blurredData[$label][$blurredDate][] = $value;
					}
				}
			}
		}
		fclose($fileHandle);
		if($missingDateColumnCount){
			$message = $missingDateColumnCount.' of '.$rowCount.' column'.(($rowCount > 1)?'s':'').' without date column found in "'.$file.'"';
			if($rowCount && $missingDateColumnCount / $rowCount > 0.1){
				throw new Exception($message);
			}
			else{
				trigger_error($message, E_USER_WARNING);
			}
		}
		$data = array();
		foreach($blurredData as $label => $valuesByDate){
			foreach($valuesByDate as $date => $values){
				$sum = 0;
				foreach($values as $value){
					$sum += $value;
				}
				$data[$label][$date] = $sum / count($values);
			}
		}

		return $data;
	}
	
	/* blurredDate()
	 *	Uses timestamp to create an inaccurate date.
	 *	This date is used for a first summarization.
	**/
	function blurredDate($timeStamp, $type, $accuracy = 1){
		switch(strtolower($type)){
			default:
			case 'day':
				$timeStamp += 60 * $accuracy / 2; //break in the middle
				$timeStamp -= $timeStamp % (60 * $accuracy); //round
				return ((int) date('G', $timeStamp)) * 60 + ((int) date('i', $timeStamp)); //minute of the day
			break;
			case 'month':
				return date('j', $timeStamp); //[1-31] day of month
			break;
			case 'year':
				return date('n', $timeStamp); //[1-12] month
			break;
			case 'all':
				return date('Y', $timeStamp); //year e.g. 2012
			break;
		}
	}
	
	/* writeData()
	 *	Writes contents into file using classes csvManager and fileManager.
	 *	Chooses file to write into. Also writes summary files.
	 *	Date column is added automaticly. 
	**/
	function writeData($values, $timeStamp = false, $timeSpanType = 'day', $state = 0){
		if(is_bool($timeStamp)){
			$timeStamp = false;
		}
		$file = $this->getIOFile($timeStamp, $timeSpanType);
		$t = 0;
		
		//--- include used methods ---//
		include_once($this->fileManagerPath);
		if(!class_exists('fileManager')){
			throw new Exception('Unable to include "fileManager".');
			return false;
		}
		include_once($this->csvManagerPath);
		if(!class_exists('csvManager')){
			throw new Exception('Unable to include "csvManager".');
			return false;
		}
		
		//--- check access + try to create a flow file while there's no access ---//
		while(!($fileHandle = fopen($checkedFile = ($t?fileManager::preExtendFile($file, '#'.$t):$file), 'a+')) && $t >= 0){
			if($t >= $this->maxFlowFiles){
				$t = -1;
				break;
			}
			$t++;
		}
		if($fileHandle){
			fclose($fileHandle);
		}
		else{
			return false;
		}
		if($t < 0){
			return false;
		}

		$row = array();
		if(!array_key_exists($this->dateColumnLabel, $values)){
			switch($timeSpanType){
				case 'day':
					$date = date('H:i:s', $timeStamp);
				break;
				case 'month':
					$date = date('Y-m-d', $timeStamp);
				break;
				case 'year':
					$date = date('Y M', $timeStamp);
				break;
				case 'all':
					$date = date('Y', $timeStamp);
				break;
			}
			$row[$this->dateColumnLabel] = $date;
		}
		if($timeSpanType !== 'day' && !array_key_exists($this->stateColumnLabel, $values)){
			$row[$this->stateColumnLabel] = $state;
		}
		$row = array_merge($row, $values);
		$csvWriter = new csvManager();
		$csvWriter->delimiter = $this->logDelimiter;
		$success = $csvWriter->write($checkedFile, $row, $this->dateColumnLabel);
		unset($csvWriter);
		
		return $success;
	}
	
	/* mergeFlowFiles()
	 *	Checks if there are any flow files for a file.
	 *	Tries to merge all flow files into origin file.
	**/
	function mergeFlowFiles($file){
		include_once($this->fileManagerPath);
		if(!class_exists('fileManager')){
			throw new Exception('Unable to include "fileManager".');
			return -1;
		}
		
		$merged = 0;
		
		for($i = 1; $i <= $this->maxFlowFiles; $i++){
			$flowFile = fileManager::preExtendFile($file, '#'.$i);
			if(!file_exists($flowFile)){
				break; //nothing to merge.
			}
			else{
				if(fileManager::merge($file, $flowFile)){
					$merged++;
				}
				else{
					return -1;
				}
			}
		}
		return $merged;
	}
	
	/* createSummary()
	 *	Creates file summarization and saves it to summary file.
	 *	Creates only summaries required to create the current summary.
	 *	This function is deliberated to be called by GUI or an other interface.
	 *	Returns new summarization state.
	**/
	function createSummary($timeStamp, $timeSpanType, $today = false, $file = false){
		if(is_bool($today)){
			$today = time();
		}
		if(is_bool($timeStamp)){
			$timeStamp = false;
		}
		if(!$file){
			$file = $this->getIOFile($timeStamp, $timeSpanType);
		}
		$summarizationState = $this->getSummarizationState($timeStamp, $timeSpanType, $today, $file);
		if($summarizationState != 0){
			return $summarizationState;
		}
		
		$roundDecimalPlace = 0; //position after decimal point to round to
		
		switch($timeSpanType){
			case 'day':
				$this->mergeFlowFiles($file);
				return $summarizationState;
			break;
			case 'month':
				$stateOneExists = false;
				$stateZeroExists = false;
				$daysInMonth = date('t', $timeStamp);
				$monthString = date('Y-m-', $timeStamp);
				$todayStart = strtotime(date('Y-m-d 00:00:00', $today));
				$summarizationStates = $this->getSummarizationStatesFromFile($file);
						
				for($day = 1; $day < $daysInMonth; $day++){
					$dayOnlyString = ($day < 10 ? '0' : '').$day; //attach leading zero
					$dayString = $monthString.$dayOnlyString;
					$dayStamp = strtotime($dayString);
					$dayFile = $this->getIOFile($dayStamp, 'day');
					
					$currentDayState = 0;
					
					$averageValues = array();
					if(!file_exists($dayFile)){
						if($dayStamp < $todayStart){
							$currentDayState = -1;
						}
					}
					else{
						$accuracy = 15; //minutes
						
						if(!isset($summarizationStates[$dayString]) || $summarizationStates[$dayString] !== 0){
							$values = $this->getDataFromFile($dayFile, strtotime($dayString.' 00:00:00'), strtotime($dayString.' 23:59:59'), 'day', $accuracy);
						}
						else{
							$currentDayState = $summarizationStates[$dayString];
							$values = true;
						}
						
						if($dayStamp < $todayStart){ //in past
							$currentDayState = 1;
						}
						else{
							$currentDayState = 0;
						}

						if(empty($values)){ //no values
							$currentDayState *= -1;
						}
						else{
							$valueCount = array();
							$sums = array();
							foreach($values as $id => $timedValues){
								if(!isset($sums[$id])){
									$sums[$id] = 0;
								}
								if(!isset($valueCount[$id])){
									$valueCount[$id] = 0;
								}
								foreach($timedValues as $time => $value){
									$sums[$id] += $value;
									$valueCount[$id]++;
								}
							}
							foreach($sums as $id => $sum){
								$averageValues[$id] = round($sum / $valueCount[$id], $roundDecimalPlace);
							}
						}
					}
					
					$this->writeData($averageValues, $dayStamp, 'month', $currentDayState);

					if($currentDayState === 0){
						$stateZeroExists = true;
					}
					else if($currentDayState === 1){
						$stateOneExists = true;
					}
				}
				if($stateZeroExists){
					return 0;
				}
				else if($stateOneExists){
					return 1;
				}
				else{
					return -1;
				}
			break;
			case 'year':
				$year = date('Y', $timeStamp);
				$stateOneExists = false;
				$stateZeroExists = false;
				$monthStart = strtotime(date('Y-m-1 00:00:00', $today));
				$summarizationStates = $this->getSummarizationStatesFromFile($file);
				
				for($month = 1; $month <= 12; $month++){
					$monthOnlyString = ($month < 10 ? '0' : '').$month; //attach leading zero
					$monthString = $year.'-'.$monthOnlyString;
					$monthStamp = strtotime($monthString);
					$monthFile = $this->getIOFile($monthStamp, 'month');
					
					$currentMonthState = $this->getSummarizationState($monthStamp, 'month', $today);
					if($currentMonthState === 0){
						$currentMonthState = $this->createSummary($monthStamp, 'month', $today);
					}
					
					if(!file_exists($monthFile)){
						if($monthStamp < $monthStart){
							$currentMonthState = -1;
						}
					}
					else{
						if(!isset($summarizationStates[$monthString]) || $summarizationStates[$monthString] !== 0){
							$values = $this->getDataFromFile($monthFile, strtotime($monthString.'-01 00:00:00'), strtotime($monthString.'-'.$this->_getNumberOfDaysInMonth($month, $year).' 23:59:59'), 'month');
						}
						else{
							$currentMonthState = $summarizationStates[$monthString];
							$values = true;
						}
						
						if($values !== true){
							if($monthStamp < $monthStart){ //in past
								$currentMonthState = 1;
							}
							else{
								$currentMonthState = 0;
							}
								
							if(empty($values)){ //no values
								$currentMonthState *= -1;
							}
							else{
								$valueCount = array();
								$sums = array();
								foreach($values as $time => $row){
									foreach($row as $id => $value){
										$sums[$id] += $value;
										if(!isset($valueCount[$id])){
											$valueCount[$id] = 0;
										}
										$valueCount[$id]++;
									}
								}
								$averageValues = array();
								foreach($sums as $id => $sum){
									$averageValues[$id] = round($sum / $valueCount[$id], $roundDecimalPlace);
								}
							}
						}
					}
					$this->writeData($averageValues, $monthStamp, 'year', $currentMonthState);
	
					if($currentMonthState === 0){
						$stateZeroExists = true;
					}
					else if($currentMonthState === 1){
						$stateOneExists = true;
					}
				}
				if($stateZeroExists){
					return 0;
				}
				else if($stateOneExists){
					return 1;
				}
				else{
					return -1;
				}
			break;
			case 'all':
				$currentYear = (int) date('Y', $today);
				$yearEnd = strtotime(date('Y-12-31 23:59:59', $today));
				for($year = $currentYear - 10; $year <= $currentYear; $year++){
					$yearString = (string) $year;
					$yearStamp = strtotime($yearString);
					$yearFile = $this->getIOFile($yearStamp, 'year');
					
					$currentYearState = $this->getSummarizationState($yearStamp, 'year', $today);
					if($currentYearState === 0){
						$currentYearState = $this->createSummary($yearStamp, 'year', $today);
					}
					
					if(!file_exists($monthFile)){
						if($yearStamp < $yearEnd){
							$currentYearState = -1;
						}
					}
					else{
						if(!isset($summarizationStates[$yearString]) || $summarizationStates[$yearString] !== 0){
							$values = $this->getDataFromFile($monthFile, strtotime($yearString.'-01-01 00:00:00'), strtotime($yearString.'-12-31 23:59:59'), 'year');
						}
						else{
							$currentYearState = $summarizationStates[$yearString];
							$values = true;
						}
						
						if($values !== true){
							if($yearStamp < $yearEnd){ //in past
								$currentYearState = 1;
							}
							else{
								$currentYearState = 0;
							}
								
							if(empty($values)){ //no values
								$currentYearState *= -1;
							}
							else{
								$valueCount = array();
								$sums = array();
								foreach($values as $time => $row){
									foreach($row as $id => $value){
										$sums[$id] += $value;
										if(!isset($valueCount[$id])){
											$valueCount[$id] = 0;
										}
										$valueCount[$id]++;
									}
								}
								$averageValues = array();
								foreach($sums as $id => $sum){
									$averageValues[$id] = $sum / $valueCount[$id];
								}
							}
						}
					}
					$this->writeData($averageValues, $monthStamp, 'all', $currentYearState);
				}
				return 0;
			break;
			default:
				trigger_error('Unknown timeSpanType "'.$timeSpanType.'".', E_USER_NOTICE);
				return -1;
			break;
		}
	}
	
	/* _getNumberOfDaysInMonth
	 *	As the name says, it gets the number of days in a month.
	 *	It cares about leap years in february.
	 *	It's same as "date('t', strtotime($year.'-'.$month))" but between 6 and 8 times faster.
	**/
	private function _getNumberOfDaysInMonth($month, $year){
		switch($month){
			case 1: case 3: case 5: case 7: case 8: case 10: case 12:
				return 31;
			break;
			case 4: case 6: case 9: case 11:
				return 30;
			break;
			case 2:
				if(($year % 400) == 0 || (($year % 4) == 0 && ($year % 100) != 0)){ //is leap year
					return 29;
				}
				else{
					return 28;
				}
			break;
			default:
				return 0;
			break;
		}
	}
	
	/* getSummarizationState()
	 *	Helper of createSummary.
	 *	Checks if there are any summary files to be created / updated.
	 *	A summary is to be updated, if a lower level summarization / log file is newer then the summarization-file.
	 *	A summary can be finished, when $timeStamp * $timeSpanType = past.
	 *	State -1 = No data given and no new data expected
	 *	State 0 = There might be some data, but no information about conclusion can be given right now.
	 *	State 1 = Summary is complete
	 *	Returns summarization state for given time stamp and level (time span type)
	 *	@ param1: The time stamp for the log file to check
	 *	@ param2: The time span type / log file type to check
	 *	@ param3: The time stamp to calculate weather new files are expected or not (optional, default is current time)
	 *	@ param4: The top-level logfile to check (very optional - only given for the possibility to improove efficiency)
	**/
	function getSummarizationState($timeStamp, $timeSpanType, $today = false, $file = false){
		if(is_bool($timeStamp)){
			$timeStamp = false;
		}
		if(!is_string($file)){
			$file = $this->getIOFile($timeStamp, $timeSpanType);
		}
		switch($timeSpanType){
			case 'day':
				if(strcmp(date('Y-m-d', $timeStamp), date('Y-m-d', $today)) < 0){ //timeStamp is in past
					//--- Check for flowFiles ---//
					include_once($this->fileManagerPath);
					if(!class_exists('fileManager')){
						throw new Exception('Unable to include "fileManager".');
						return;
					}
					if(!file_exists($file)){
						return -1;
					}
					if($this->maxFlowFiles){
						$firstFlowFile = fileManager::preExtendFile($file, '#'.'1');
						if(!file_exists($firstFlowFile)){ //no flow file found
							return 1; //no futher flow files expected
						}
						else{
							return 0;
						}
					}
					else{
						return 1;
					}
				}
				else{
					return 0;
				}
			break;
			case 'month':
				if(strcmp(date('Y-m', $timeStamp), date('Y-m', $today)) < 0){ //timeStamp is in past
					if(file_exists($file)){
						$summarizationStates = $this->getSummarizationStatesFromFile($file);
						if($summarizationStates === false){
							throw new Exception('Unable to get summarization states from file "'.$file.'".');
							return;
						}
					}
					$completeLogExists = false;
					$daysInMonth = date('t', $timeStamp);
					$monthString = date('Y-m-', $timeStamp);
					for($day = 1; $day < $daysInMonth; $day++){
						$dayString = ($day < 10 ? '0' : '').$day; //attach leading zero
						$dateString = $monthString.$dayString;
						if(!file_exists($file)){
							$dateFile = $this->getIOFile(strtotime($dateString, 'day'));
							if(file_exists($dateFile)){
								return 0;
							}
						}
						else{
							if(!isset($summarizationStates[$dateString]) || $summarizationStates[$dateString] == 0){
								return 0;
							}
							else if($summarizationStates[$dateString] == 1){
								$completeLogExists = true;
							}
						}
					}
					if($completeLogExists){
						return 1;
					}
					else{
						return -1;
					}
				}
				else{
					return 0;
				}
			break;
			case 'year':
				if(strcmp(date('Y', $timeStamp), date('Y', $today)) < 0){ //timeStamp is in past
					if(file_exists($file)){
						$summarizationStates = $this->getSummarizationStatesFromFile($file);
						if($summarizationStates === false){
							throw new Exception('Unable to get summarization states from file "'.$file.'".');
							return;
						}
					}
					$completeLogExists = false;
					$yearString = date('Y ', $timeStamp);
					for($month = 1; $month <= 12; $month++){
						$monthString = date('M', ($month - 1) * 31 * 24 * 3600);
						$dateString = $yearString.$monthString;
						if(!file_exists($file)){
							$dateFile = $this->getIOFile(strtotime($dateString, 'day'));
							if(file_exists($dateFile)){
								return 0;
							}
						}
						else{
							if(!isset($summarizationStates[$dateString]) || $summarizationStates[$dateString] == 0){
								return 0;
							}
							else if($summarizationStates[$dateString] == 1){
								$completeLogExists = true;
							}
						}
					}
					if($completeLogExists){
						return 1;
					}
					else{
						return -1;
					}
				}
				else{
					return 0;
				}
			break;
			case 'all':
				return 0; //This summary can never be expected as complete.
			break;
		}
	}
	
	/* getSummarizationStatesFromFile()
	 *	Reads log or log summary and returns content of a state column.
	 *	Returns array in format 'date' => 'state' or false on error.
	**/
	function getSummarizationStatesFromFile($file){
		if(file_exists($file)){
			$fileHandle = fopen($file, 'r');
			if(!$fileHandle){
				return false;
			}
		}
		else{
			return array();
		}

		//--- include used methods ---//
		include_once($this->csvManagerPath);
		if(!class_exists('csvManager')){
			throw new Exception('Unable to include "csvManager".');
			return false;
		}
		
		$csvReader = new csvManager();
		$csvReader->delimiter = $this->logDelimiter;;
		
		$states = array();
		$rowCount = 0;
		$missingDateColumnCount = 0;
		$missingStateColumnCount = 0;
		$firstRow = fgetcsv($fileHandle, $csvReader->rowLength, $csvReader->delimiter);
		while ($row = $csvReader->fgetcsv_assoc($fileHandle, $firstRow)){
			$rowCount++;
			if(!isset($row[$this->dateColumnLabel])){
				$missingDateColumnCount++;
				continue;
			}
			else if(!isset($row[$this->stateColumnLabel])){
				$missingStateColumnCount++;
				continue;
			}
			$states[$this->dateColumnLabel] = $row[$this->stateColumnLabel];
		}
		fclose($fileHandle);

		if($missingDateColumnCount){
			$message = $missingDateColumnCount.' of '.$rowCount.' column'.(($rowCount > 1)?'s':'').' without date column found in "'.$file.'"';
			if($rowCount && $missingDateColumnCount / $rowCount > 0.5){
				throw new Exception($message);
			}
			else{
				trigger_error($message, E_USER_WARNING);
			}
		}
		if($missingStateColumnCount){
			$message = $missingStateColumnCount.' of '.$rowCount.' column'.(($rowCount > 1)?'s':'').' without date state found in "'.$file.'"';
			if($rowCount && $missingStateColumnCount / $rowCount > 0.5){
				throw new Exception($message);
			}
			else{
				trigger_error($message, E_USER_WARNING);
			}
		}
		return $states;
	}
}
?>