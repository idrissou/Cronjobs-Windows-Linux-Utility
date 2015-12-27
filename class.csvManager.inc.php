<?php
/* About the file
 *	Name:			class.csvManager.inc.php
 *	Description:	This class handles files with comma-separated values (CSV).
 *					It extends the simple functionallity of fputcsv() with
 *					associative columns and unique rows.
**/
class csvManager{
	public $delimiter;
	public $rowLength;
	public $maxRowsInMemory;
	public $tmpFile;
	public $tmpFileExt;
	public $emptyContent;
	public $forceAssoc;
	
	public $lastHead;
	
	private $fileManagerPath;
	private $writingBuffer;
	private $bufferCount;
	
	/* Constructor
	 *	Initializes all attributes.
	**/
	function __construct($delimiter = ';', $enclosure = '"', $escape = '\\', $rowLength = 1024, $maxRows = 1024){
		$this->delimiter = $delimiter;
		$this->enclosure = $enclosure;
		$this->escape = $escape;
		$this->rowLength = $rowLength;
		$this->maxRowsInMemory = $maxRows;
		$this->tmpFile = false;
		$this->tmpFileExt = '~tmp';
		$this->emptyContent = '';
		$this->forceAssoc = false;
		
		$this->fileManagerPath = './class.fileManager.inc.php';
		$this->writingBuffer = array();
		$this->bufferCount = 0;
	}
	
	/* write
	 *	Writes comma seperated values to file.
	 *	Differences to a simple fputcsv():
	 *		- buffered
	 *		- saves device resources (optimized on device efficiency)
	 *		- can write associative values by using first row as key row
	 *		- adds missing columns (according to first row)
	 *		- overwrites rows by key row, if given (3rd parameter)
	 *		- removes line if only value of key column is set
	**/
	function write($file, $values, $keyCol = false){
		if(empty($file)){
			return false;
		}
		if(empty($values)){
			return false;
		}
		
		//--- calculate 3rd parameter ---//
		$assoc = $this->forceAssoc || count(array_filter(array_keys($values), 'is_string'));
		
		//--- get name of temporary copy ---//
		$newFile = '';
		if(is_string($this->tmpFile)){
			include_once($this->fileManagerPath);
			if(class_exists('fileManager')){
				if(fileManager::validPath($this->tmpFile, false)){
					$newFile = $this->tmpFile;
				}
			}
		}
		if(empty($newFile)){
			$newFile = $file.$this->tmpFileExt;
		}
		
		//--- initialize variables ---//
		$anyChanges = false; //In this context file appends are no changes: "$anyChanges" will stay "false".
		$overwrite = 0; //overwrite in this context means not only a line will be overwritten, but the key value matched.
		$error = false;
		
		//--- copy first row ---//
		//... read first row ...//
		if(!file_exists($file)){
			$rowAsIs = array();
			$oldFileHandle = false;
			$newFileHandle = fopen($file, 'w');
		}
		else{
			$oldFileHandle = fopen($file, 'r+');
			
			$newFileHandle = false;
			if($oldFileHandle){
				$rowAsIs = fgetcsv($oldFileHandle, $this->rowLength, $this->delimiter, $this->enclosure, $this->escape);
				if($rowAsIs === false){
					$rowAsIs = array();
				}
			}
			else{
				return false;
			}
		}
		//... calculate and write first row ...//
		$rowAsIsCount = count($rowAsIs);
		if($assoc){ // assoc //
			$columnsToAdd = array();
			foreach(array_keys($values) as $colFromValues){
				if(!in_array($colFromValues, $rowAsIs)){
					$columnsToAdd[] = $colFromValues;
				}
			}
			$anyChanges = (bool) count($columnsToAdd);
			$oldHead = $rowAsIs;
			$columns = array_merge($rowAsIs, $columnsToAdd);
			//copy/write head:
			try{
				$newFileHandle = $this->openHandleOnLastChance($newFileHandle, $newFile);
				$error = !$this->writeBuffered($columns, $newFileHandle);
			}
			catch(Exception $e){
				$error = true;
			}
		}
		else{ // not assoc //
			$valueCount = count($values);
			ksort($values, SORT_NUMERIC);
			if($rowAsIsCount !== 0){
				$anyChanges = $valueCount >= $rowAsIsCount;
				$colCount = max($valueCount, $rowAsIsCount);
				$firstKey = key($values);
				end($values);
				$lastKey = key($values);
				if($firstKey < 0 || $lastKey >= $colCount ){
					$valuesWithInvalidKeys = $values;
					$values = array();
					foreach($valuesWithInvalidKeys as $value){
						$values[] = $value;
					}
					unset($valuesWithInvalidKeys);
				}
				$oldHead = range(0, $rowAsIsCount-1);
				$columns = range(0, $colCount-1);
				$rowToBe = $rowAsIs;
				for($i = 0; $i < $valueCount - $rowAsIsCount; $i++){
					if(!isset($rowToBe[$i])){
						$rowToBe[$i] = $this->emptyContent;
					}
					if($keyCol !== false && $i === $keyCol){
						$overwrite = -1;
						break;
					}
				}
				if($overwrite === -1){
					$rowToBe = $values;
					$lineIsEmpty = true;
					for($i = 0; $i < $valueCount - $rowAsIsCount; $i++){
						if(!isset($rowToBe[$i])){
							$rowToBe[$i] = $this->emptyContent;
						}
						else if($keyCol === false || $i !== $keyCol){
							$lineIsEmpty = false;
						}
					}
				}
				else{
					$lineIsEmpty = false;
				}
				//copy/write first row:
				if(!$lineIsEmpty){
					try{
						$newFileHandle = $this->openHandleOnLastChance($newFileHandle, $newFile);
						$error = !$this->writeBufferedAssoc($rowToBe, $columns, $newFileHandle);
					}
					catch(Exception $e){
						$error = true;
					}
				}
				else{
					$error = false;
				}
				
				if($overwrite === -1){
					if($error){
						$overwrite = 0;
					}
					else{
						$overwrite = 1;
					}
				}
			}
		}
		
		//--- Copy old lines ---//
		$colCount = count($columns);
		while($rowAsIsCount != 0 && !$error){
			//... read next line ...//
			if($assoc){
				$rowAsIs = $this->fgetcsv_assoc($oldFileHandle, $oldHead);
			}
			else{
				$rowAsIs = fgetcsv($oldFileHandle, $this->rowLength, $this->delimiter, $this->enclosure, $this->escape);
			}
			if(!is_array($rowAsIs)){
				$rowAsIs = array();
				$rowAsIsCount = 0;
			}
			else{
				$rowAsIsCount = count($rowAsIs);
			}
			if($rowAsIsCount !== 0){
				//... write (copy) line ...//
				$lineIsEmpty = false;
				$rowToWrite = &$rowAsIs;
				if( $keyCol !== false
					&& $overwrite != 1 //only overwrite first match
					&& isset($rowAsIs[$keyCol])
					&& $rowAsIs[$keyCol] === $values[$keyCol] //key value matches => overwrite that line
				){
					$rowToWrite = $this->overwriteEmpty($values, $rowAsIs);
					$overwrite = -1;
					//Check if this overwrite makes any changes on the file:
					$lineIsEmpty = true;
					foreach($values as $key => &$value){
						if($key != $keyCol && isset($rowAsIs[$key])){
							$lineIsEmpty = false;
						}
						if(!isset($rowAsIs[$key]) || $rowAsIs[$key] != $value){
							$anyChanges = true;
							break;
						}
					}
				}
				if(!$lineIsEmpty){
					try{
						$newFileHandle = $this->openHandleOnLastChance($newFileHandle, $newFile);
						$error = !$this->writeBufferedAssoc($rowToWrite, $columns, $newFileHandle);
					}
					catch(Exception $e){
						$error = true;
					}
				}
				if($overwrite === -1){
					if($error){
						$overwrite = 0;
					}
					else{
						$overwrite = 1;
					}
				}
				$anyChanges = $anyChanges || $colCount != $rowAsIsCount || $lineIsEmpty && $overwrite;
			}
			else{
				break;
			}
		}
		//--- Attach values --- //
		if(!$error){
			if($anyChanges){
				if(!$overwrite){ //Attach line to new file:
					try{
						$newFileHandle = $this->openHandleOnLastChance($newFileHandle, $newFile);
						$error = !$this->writeBufferedAssoc($values, $columns, $newFileHandle);
					}
					catch(Exception $e){
						$error = true;
					}
				}
				if(!$error){ //Write buffer now:
					try{
						$newFileHandle = $this->openHandleOnLastChance($newFileHandle, $newFile, true); //force to open handle
						$error = !$this->writeBuffered(true, $newFileHandle); //force to write
					}
					catch(Exception $e){
						$error = true;
					}
				}
			}
			else if(!$overwrite){ //Now "overwrite" means it is overwritten or in this case: It isn't.
				if(count($values) != (0 + ($keyCol !== false))){ // line is not empty
					//Write buffer now:
					try{
						$newFileHandle = $this->openHandleOnLastChance($newFileHandle, $newFile, true); //force to open handle
						$error = !$this->writeBuffered(true, $newFileHandle); //force to write
					}
					catch(Exception $e){
						$error = true;
					}
					if(!$error){ //Attach new line
						$this->fputcsv_assoc($oldFileHandle?:$newFileHandle, $columns, $values);
					}
				}
			}
		}
		
		//--- close & clean ---//
		if($oldFileHandle){
			fclose($oldFileHandle);
			if($newFileHandle){
				fclose($newFileHandle);
				if($anyChanges && !$error){
					unlink($file);
					rename($newFile, $file);
				}
				else{
					unlink($newFile);
				}
			}
		}
		else if($newFileHandle){
			//newFileHandle points on primary file path
			fclose($newFileHandle);
		}
		return !$error;
	}//end of: function write()
	
	/* overwriteEmpty()
	 *	Overwrites only empty or missing values in "master" array with contents from "slave" array.
	**/
	private function overwriteEmpty($master, $slave){
		$newArray = $master;
		//overwrite empty values:
		foreach($newArray as $key => $value){
			if(empty($value) && isset($slave[$key])){
				$newArray[$key] = $slave[$key];
			}
		}
		//add missing keys with values:
		return $newArray + array_diff_key($slave, $newArray);
	}
	
	/* openHandleOnLastChance
	 *	Opens file handle of file to write in only if it has to be opened.
	 *	This function helps using writeBuffered() in most efficient way.
	**/
	private function openHandleOnLastChance($handle, $filePath, $openNow = false){
		if(is_resource($handle)){
			return $handle;
		}
		else{
			if($openNow === true || $this->bufferCount >= $this->maxRowsInMemory){
				if(file_exists($filePath)){
					unlink($filePath);
				}
				$newHandle = fopen($filePath, 'w');
				if(!$newHandle){
					throw new Exception('Unable to create file "'.$filePath.'".');
				}
				else{
					return $newHandle;
				}
			}
			else{
				return false;
			}
		}
	}
	
	/* writeBuffered
	 *	Helper of function write(), only writes contents to file if it's forced to do that. 
	 *		First parameter:
	 *			- false clears buffer
	 *			- true forces to write buffer and clears it after writing
	 *			- array value will write array into buffer
	 *		Second parameter:
	 *			- valid file handle
	 *			- can be false if no output is expected
	 *		Third parameter:
	 *			- Output: Number of rows in buffer
	**/
	private function writeBuffered($row, $handle = false){
		if($row === false){
			//--- clear buffer / free memory ---//
			$this->writingBuffer = array();
			$this->bufferCount = 0;
			return true;
		}
		else if($row === true || $this->bufferCount = count($this->writingBuffer) >= $this->maxRowsInMemory){
			//--- write buffer to file ---//
			if(!$handle){
				return false;
			}
			foreach($this->writingBuffer as $i => &$bufferRow){
				if(fputcsv($handle, $bufferRow, $this->delimiter, $this->enclosure)){
					unset($bufferRow);
					$this->bufferCount--;
				}
				else{
					return false;
				}
			}
			if($row !== true){
				//--- write into buffer ---//
				$this->writingBuffer[] = $row;
				$this->bufferCount = count($this->writingBuffer);
			}
			return true;
		}
		else{
			//--- write into buffer ---//
			$this->writingBuffer[] = $row;
			$this->bufferCount = count($this->writingBuffer);
			return true;
		}
	}
	/* writeBufferedAssoc
	 *	Helper of function write(), only writes contents to file if it's forced to do that.
	 *	Writes associative contents. Uses writeBuffered().
	 *		First parameter:
	 *			- false clears buffer
	 *			- true forces to write buffer and clears it after writing
	 *			- array value will write array into buffer
	 *		Second parameter:
	 *			- head row (keys)
	 *		Third parameter:
	 *			- valid file handle
	 *			- can be false if no output is expected
	 *		Fourth parameter:
	 *			- Output: Number of rows in buffer
	**/
	private function writeBufferedAssoc($assocRow, $head, $handle = false){
		$row = array();
		foreach($head as $colId){
			if(isset($assocRow[$colId])){
				$row[] = $assocRow[$colId];
			}
			else{
				$row[] = $this->emptyContent;
			}
		}
		return $this->writeBuffered($row, $handle);
	}
	
	/* fgetcsv_assoc()
	 *	Improved version of fgetcsv, but can return an associative array by using the heading row (parameter $head)
	**/
	function fgetcsv_assoc($fileHandle, $head){
		$row = $this->fgetcsv($fileHandle);
		if(!$row) return $row;
		$newRow = array();
		foreach($row as $nr => $col){
			$newRow[$head[$nr]] = $col;
		}
		return $newRow;
	}
	
	/* fgetcsv()
	 *	Same as normal fgetcsv() but using attributes of this class as parameters.
	**/
	function fgetcsv($handle){
		fgetcsv($handle, $this->rowLength, $this->delimiter, $this->enclosure, $this->escape);
	}
	
	/* fputcsv_assoc()
	 *	Improved version of fputcsv, but can write an associative array by using the heading row (parameter $head)
	**/
	function fputcsv_assoc($fileHandle, $head, $assocRow){
		$row = array();
		foreach($head as $colId){
			if(isset($assocRow[$colId])){
				$row[] = $assocRow[$colId];
			}
			else{
				$row[] = $this->emptyContent;
			}
		}
		return fputcsv($fileHandle, $row, $this->delimiter, $this->enclosure);
	}
}
?>