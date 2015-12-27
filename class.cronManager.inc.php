<?php
/* About the file
 *	Name:			class.cronManager.inc.php
 *	Description:	Offers functionality to read, create, edit and delete cronjobs,
 *					in windows and linux.
**/

class cronManager{
	private $isWin;
	private $tempCronTablePath;	//temporary file path required for linux (just a free path-name-combination for usage)
	private $fileManagerPath;	//path of file with class "fileManager"
	private $csvManagerPath;	//path of file with class "csvManager"
	private $csvManager;		//object of class "csvManager"
	private $cronTableDelimiter;//delimiter used in "csvManager" for this object
	private $winLoginString;	//login information required for windows (computer, user(+domain), password)
	
	
	//========== PUBLIC FUNCTIONS ==========//
	
	/* Constructor
	 *	Determines weather to run windows scheduled tasks or linux cronjobs.
	 *	If it is a windows system: domain, computer name, username and password must be given.
	 *	If it is _not_ a windows system, the path to a cron table file must be given.
	**/
	function __construct($tempCronTablePath_or_password = '', $computerName = '', $userName = ''){
		$this->isWin = strpos(PHP_OS, 'WIN') === 0; //0, not false!

		if($this->isWin){
			$this->_winConstruct($computerName, $userName, $tempCronTablePath_or_password);
		}
		else{
			$this->fileManagerPath = 'class.fileManager.inc.php';
			$this->csvManagerPath = 'class.csvManager.inc.php';
			$this->_linConstruct($tempCronTablePath_or_password);
		}
	}
	
	/* create()
	 *	Creates or edits cronjob or scheduled task.
	**/
	function write($identifier, $executionLine, $recurenceAmount, $recurenceType = 'min'){
		if(empty($identifier) || !(is_string($identifier) || is_numeric($identifier)) || strpos($identifier, ' ') !== false){
			throw new Exception('Invalid parameter 1 (identifier) given: String without spaces expected.');
		}
		if(!is_string($executionLine) && !$this->_evilCommand($executionLine)){
			throw new Exception('Invalid parameter 2 (execution line) given: String expected.');
		}
		if(!is_integer($recurenceAmount) || $recurenceAmount < 1 || $recurenceAmount > 10000){
			throw new Exception('Invalid parameter 3 (recurence amount) given: Integer between 1 and 10000 expected.');
		}
		if($this->isWin){
			return $this->_winWrite($identifier, $executionLine, $recurenceAmount, $recurenceType);
		}
		else{
			return $this->_linWrite($identifier, $executionLine, $recurenceAmount, $recurenceType);
		}
	}
	
	/* delete()
	 *	Deletes cronjob or scheduled task.
	 *	Returns true on success, false on error.
	**/
	function delete($identifier){
		if(!is_string($identifier) && !is_numeric($identifier)){
			throw new Exception ('Invalid parameter 1: Identifier must be string or numeric. '.gettype($identifier).' given.');
			return false;
		}
		if($this->isWin){
			return $this->_winDelete($identifier);
		}
		else{
			return $this->_linDelete($identifier);
		}
	}
	
	//========== PRIVATE FUNCTIONS ==========//
	/* _evilCommand()
	 *	This function tries to catch some evil commands.
	 *	Returns false if everything is ok, otherwise true.
	**/
	function _evilCommand($command){
		$command = trim($command);
		
		$evilFunctions = array(
			'format',
			'rm ',
			'mkfs',
			'wget',
			'unmount'
		);
		foreach($evilFunctions as $evil){
			if(substr($command, 0, strlen($evil)) === $evil){
				return true;
			}
		}
		
		$evilCommandParts = array(
			'/dev/sda',
		);
		foreach($evilCommandParts as $evil){
			if(stripos($command, $evil) !== false){
				return true;
			}
		}
		
		return false;
	}
	
	//---------- WINDOWS FUNCTIONS ----------//	
	
	/* _winConstruct()
	 *	The constructor for windows OS.
	**/
	private function _winConstruct($computerName, $userName, $password, $domainName = ''){
		$this->winLoginString = '';
		if(!empty($computerName) && !empty($userName) && !empty($password)){
			$this->winLoginString = '/s '.$computerName.' /ru '.$userName.' /rp '.$password;
		}
	}
	
	/* _winWrite()
	 *	Creates windows scheduled task or edits it if it already exists.
	**/
	private function _winWrite($identifier, $executionLine, $recurenceAmount, $recurenceType = 'min'){
		$recurenceString = '/mo '.$recurenceAmount.' /sc ';
		switch(strtolower(substr($recurenceType, 0, 2))){
			case 'mi':
				$recurenceString .= 'MINUTE';
			break;
			case 'ho':
				$recurenceString .= 'HOURLY';
			break;
			case 'da':
				$recurenceString .= 'DAILY';
			break;
			case 'mo':
				$recurenceString .= 'MONTHLY';
			break;
			case 'we':
				$recurenceString .= 'WEEKLY';
			break;
			default:
				throw new Exception('Invalid recurence type '.(is_string($recurenceType) ? '"'.$recurenceType.'"' : '('.gettype($recurenceType).')') );
			break;
		}

		if($this->_winExists($identifier)){
			if(!$this->_winDelete($identifier)){
				return false;
			}
		}
		exec('schtasks /create /tn '.$identifier.' /tr "'.$executionLine.'" '.$this->winLoginString.' '.$recurenceString);
		return $this->_winExists($identifier);
	}
	
	/* _winExists()
	 *	Checks weather scheduled task already exists or not.
	 *	Returns true if the task was found, otherwise false.
	**/
	private function _winExists($identifier){
		exec('schtasks /query', $result);
		$rowCount = count($result);
		if($rowCount < 2){
			trigger_error('No list given.', E_USER_NOTICE);
			return false;
		}
		$identifierColumnWidth = 0;
		foreach(str_split(trim($result[2])) as $pos => $char){
			if($char != '='){
				$identifierColumnWidth = $pos;
				break;
			}
		}
		if($identifierColumnWidth < 1){
			trigger_error('Invalid list format.', E_USER_NOTICE);
			return false;
		}
		for($i = 3; $i < $rowCount; $i++){
			$idFromList = trim(substr($result[$i], 0, $identifierColumnWidth));
			if($idFromList === $identifier){
				return true;
			}
		}
		return false;
	}
	
	/* _winDelete()
	 *	Deletes scheduled tasks in windows.
	 *	Returns true on success, false on error.
	**/
	private function _winDelete($identifier){
		exec('schtasks /delete /tn '.$identifier.' /f'); // /tn = taskname, /f = don't prompt confirmation
		return !$this->_winExists($identifier);
	}
	
	//---------- LINUX FUNCTIONS ----------//
	
	/* _linConstruct()
	 *	The constructor for linux OS.
	**/
	private function _linConstruct($tempCronTablePath){
		if(empty($tempCronTablePath)){
			throw new Exception('This is no windows server, parameter 1 must be a path for a valid file.');
		}
		
		if(!include($this->csvManagerPath) /* || !class_exists('csvManager')*/){
			throw new Exception('Unable to include csvManager. csvManager is required because it\'s no windows server.');
		}
		if(@include($this->fileManagerPath)){
			if(class_exists('fileManager')){
				if(!fileManager::validPath($tempCronTablePath, false, false)){ //if its no valid file path
					if(!fileManager::validPath($tempCronTablePath, true, false)){ //and no valid directory
						throw new Exception('This is no windows server, parameter 1 must a valid path of the cron table.');
					}
					else{
						$tempCronTablePath .= 'cronTemp.txt';
						if(!fileManager::validPath($tempCronTablePath, false, false)){ //if still no valid file path
							throw new Exception('This is no windows server, parameter 1 must a valid path. Found directory path but an error occured.');
						}
					}
				}
			}
		}
		$this->tempCronTablePath = $tempCronTablePath;
		
		$this->csvManager = new csvManager('#', chr(219));
		$this->cronTableDelimiter = '~~~cronId:';
	}
	/* _linCreate()
	 *	Creates linux cronjob or edits it, if it already exists.
	**/
	private function _linWrite($identifier, $executionLine, $recurenceAmount, $recurenceType = 'min'){
		switch(strtolower(substr($recurenceType, 0, 2))){
			case 'mi':
				$recurrenceString = '*/'.$recurenceAmount.' * * * *';
			break;
			case 'ho':
				$recurrenceString = '0 */'.$recurenceAmount.' * * *';
			break;
			case 'da':
				$recurrenceString = '0 0 */'.$recurenceAmount.' * *';
			break;
			case 'mo':
				$recurrenceString = '0 0 0 */'.$recurenceAmount.' *';
			break;
			case 'we':
				$recurrenceString = '0 0 0 0 */'.$recurenceAmount.'';
			break;
			default:
				throw new Exception('Invalid recurence type '.(is_string($recurenceType) ? '"'.$recurenceType.'"' : '('.gettype($recurenceType).')') );
			break;
		}

		$data = array(
			0 => $recurrenceString.' '.$executionLine,
			1 => $this->cronTableDelimiter.$identifier
		);
		return $this->_linWriteToCrontab($data);
	}
	
	/* _linDelete()
	 *	Deletes cronjobs in linux.
	 *	This simply deletes the line ending with '#'.$this->cronTableDelemiter.$identifier by using the csvManager.
	 *	Sharp is used as csvDelemiter, the content behind the first sharp (column 1) as key column and because there
	 *	are no other values but key column given, the line will be deleted.
	  *	Returns true on success, false on error.
	**/
	private function _linDelete($indentifier){
		return $this->_linWriteToCrontab(array(1 => $this->cronTableDelimiter.$identifier));
	}
	
	/* _linWriteToCrontab()
	 *	This function "simply" writes contents into the crontab file by using the csvManager and a temporary file.
	*/
	private function _linWriteToCrontab($row){
	    $result = '';
		if(file_exists($this->tempCronTablePath)){
			unlink($this->tempCronTablePath); //delete old temporary file
		}
		$crontabContents = shell_exec('crontab -l');
		file_put_contents($this->tempCronTablePath, $crontabContents); //copy system crontab file to temporary file
		$writeToTempSuccess = $this->csvManager->write($this->tempCronTablePath, $row, 1); //edit temporary file
		if($writeToTempSuccess){
			$result = exec('crontab '.$this->tempCronTablePath); //parse temporary file into system file
		}
		//unlink($this->tempCronTablePath); //delete temporary file
		
		if($result === ''){
			return true;
		}
		else{
			$errorMessage = 'Unable to edit chrontab';
			if(is_string($result)){
				$errorMessage .= ': '.$result;
			}
			else{
				$errorMessage .= '. ';
			}
			trigger_error($errorMessage, E_USER_NOTICE);
			return false;
		}
	}
}