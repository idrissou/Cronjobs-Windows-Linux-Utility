<?php
/* About the file
 *	Name:			class.fileManager.inc.php
 *	Description:	This class stores functions for handling files in general.
 *					It is used as a kind of namespace, not as class to create an object from.
**/
class fileManager{
	/* validPath()
	 *	Checks if a string is a well-formated file path or directory.
	**/
	public static function validPath($path, $directory = true, $allowedSpecialChars = true, $allowWindowsPath = NULL){
		if(!is_string($path) || $path == ''){
			return false;
		}
		if(!is_bool($allowWindowsPath)){
			$allowWindowsPath = strpos(PHP_OS, 'WIN') === 0; //0, not false!
		}
		if(is_array($allowedSpecialChars)){
			$inputArray = $allowedSpecialChars;
			$allowedSpecialChars = '';
			foreach($inputArray as $content){
				$allowedSpecialChars .= $content;
			}
		}
		else if(is_bool($allowedSpecialChars)){
			if($allowedSpecialChars){
				if($allowWindowsPath){
					$allowedSpecialChars = '\s\~!*&$%§()=';
				}
				else{
					$allowedSpecialChars = '~!*$';
				}
			}
			else{
				$allowedSpecialChars = '';
			}
		}
		else if(!is_string($allowedSpecialChars)){
			throw new Exception('Invalid 4th parameter for function validPath().');
		}
		
		if(!preg_match('"[^\w\\\\\/\.\:\-\+'.($allowedSpecialChars?:'').']"', $path)){
			$pathTypeGeneral = true;
			$pathPartsGeneral = explode('/', $path);
			$partCountGeneral = count($pathPartsGeneral);
			for($i = 0; $i < $partCountGeneral; $i++){
				if(		$i != 0
						&& empty($pathPartsGeneral[$i])
						&& (!$directory || $i < $partCountGeneral - 1)
					||	preg_match('/[\\\\\:]/', $path)
					||	$directory
						&& $i == $partCountGeneral - 1
						&& !empty($pathPartsGeneral[$i])
					||	preg_match('/^[\.]{'.($i?'1':'3').',}$/', $pathPartsGeneral[$i])
					||	$pathPartsGeneral[$i] != trim($pathPartsGeneral[$i])

				){
					$pathTypeGeneral = false;
					break;
				}
			}

			if($pathTypeGeneral || !$allowWindowsPath || $partCountGeneral !== 1){
				return $pathTypeGeneral;
			}
			else{
				$pathTypeWindows = true;
				$pathPartsWindows = explode('\\', $path);
				$partCountWindows = count($pathPartsWindows);
				if($partCountWindows == 1){
					return false;
				}
				else{
					for($i = 0; $i < $partCountWindows; $i++){
						if(		$i != 0 && strpos($pathPartsWindows[$i], ':') !== false
							||	empty($pathPartsWindows[$i]) && (!$directory || $i < $partCountWindows - 1)
							||	$i == 0 && !preg_match('/^[A-Za-z]{1,2}[:]$/', $pathPartsWindows[0]) !== false
							||	$directory && $i == $partCountWindows - 1
								&& !empty($pathPartsWindows[$i])
							||	preg_match('/^[\.]{'.($directory?'3':'1').',}$/', $pathPartsWindows[$i])
							||	$pathPartsWindows[$i] != trim($pathPartsWindows[$i])
						){
							$pathTypeWindows = false;
							break;
						}
					}
					return $pathTypeWindows;
				}
			}
		}
		else{
			return false;
		}
	}

	/* merge()
	 *	Merges second file into first.
	**/
	public static function merge($primaryFile, $secondFile, $mergeExceptionChar = '#'){
		$secondHandle = fopen($secondFile, 'a+');
		if(!$secondHandle){
			return false;
		}
		$message = $mergeExceptionChar.'trying to merge file...'."\n";
		$flowFileEndPos = ftell($secondHandle);
		$written = fwrite($secondHandle, $message);
		if(!$written){
			fclose($secondHandle);
			return false;
		}
		rewind($secondHandle); //seek to begin of file
		$primaryHandle = fopen($primaryFile, 'a');
		if(!$primaryHandle){
			fclose($secondHandle);
			return false;
		}
		else{
			$line = 0;
			$error = false;
			$success = false;
			while($row = fread($secondFile, 1000) && !$error){
				if(ftell($secondHandle) > $flowFileEndPos){
					$success = true;
				}
				else if($row[0] != $mergeExceptionChar){
					if(!fwrite($primaryHandle, $row)){
						$success = false;
						break;
					}
				}
				$line++;
			}
			fclose($primaryHandle);
			if($line == 0){ //nothing copied
				return false;
			}
			if(!$success){ //partly copied
				//In this version there's no clean up functionallity for partly copied files.
				// - an administrator have to clean up partly copied files manually.
				fseek($secondHandle, $flowFileEndPos, SEEK_SET);
				$message = $mergeExceptionChar.';'.$line.';Error while merging file into '.$primaryFile.' after '.$line.' lines.'."\n";
				fwrite($secondHandle, $message);
				fclose($secondHandle);
				$try = '';
				$t = 0;
				while(file_exists($secondFile.'#error'.$try) && $t >= 0){
					$try = ++$t; //$try = (string) $t + 1; $t++;
					if($t >= $this->maxFlowFiles){
						$t = -1;
						break;
					}
				}
				if($t >= 0){
					rename($secondFile, $secondFile.'#error'.$try);
				}
			}
			else{
				fclose($secondHandle);
				unlink($secondFile);
			}
		}	
	}

	
	/* preExtendFile()
	 *	Extends file name of a path in front of the extension by using pathinfo().
	**/
	public static function preExtendFile($file, $preExtension){
		$path = pathinfo($file);
		if(empty($path['extension']) || empty($path['filename'])){
			return $path['filename'].$preExtension;
		}
		else{
			$dirSep = (strpos($file, '\\') !== false)?'\\':'/';
			return $path['dirname'].$dirSep.$path['filename'].$preExtension.'.'.$path['extension'];
		}
	}
}