<?php
/* About the file
 *	Name:			class.sensor.inc.php
 *	Description:	---
 *					---
**/

abstract class sensor{
	protected $typeFlags;
	protected $identifier;
	protected $sourcePath;
	protected $targetPath;
	protected $username;
	protected $password;
	protected $subpath;
	protected $version;
	
	function __construct($id, $type, $source, $target, $user, $pass, $sub){
		if(!is_scalar($id) && !is_bool($id)){
			throw new Exception('Invalid parameter #1: Identifier must be a string or number.');
		}
		else if(is_string($id)){
			$idLength = strlen($id);
			if($idLength < 1 ||  $idLength > 1024){
				throw new Exception('Invalid parameter #1: Invalid identifier length. Id must be between one and 1024 chars.');
			}
		}
		$this->typeFlags = new sensorFlag($type);
		
		if(is_string($source)){
			$this->sourcePath = $source;
		}
		else{
			$this->sourcePath = '';
		}
		if(is_string($target)){
			$this->targetPath = $target;
		}
		else{
			$this->targetPath = '';
		}
		if(is_string($user)){
			$this->username = $user;
		}
		else{
			$this->username = '';
		}
		if(is_string($pass)){
			$this->password = $pass;
		}
		else{
			$this->password = '';
		}
		if(is_string($sub)){
			$this->subpath = $sub;
		}
		else{
			$this->subpath = '';
		}
	}
}

class sensorFlag extends bitwiseFlag{

	private $flagNames = array(
		'bandWidth',	//not response time
		'sensorPage',	//use an external sensor
		'sql',			//check SQL server
		'socket',		//open socket only
		'ping'			//use ping
	);
	
	
	function __construct($startValue = 0){
		$this->setValues($startValue);
	}
	
	function setFlagByName($name, $value){
		$key = array_search($name, $this->flagNames, true);
		if($key !== false){
			$this->setFlag(1 >> $key, $value); 
		}
		return $key;
	}
	
	function getFlagByName($name){
		if(is_integer($name)){
			$key = $name;
		}
		else{
			$key = array_search($name, $this->flagNames, true);
		}
		if($key !== false){
			return $this->getFlag(1 >> $key); 
		}
		else{
			throw new Exception('Invalid flag name used: "'.$name.'". Valid keys: '.print_r($name, true));
		}
	}
	
	function setValues($values){
		if(is_numeric($values) || is_integer($values)){
			$this->values = $values;
		}
		else if(is_string($values) && in_array($values, $this->flagNames)){
			$this->setFlagByName($values, true);
		}
		else if(is_array($values)){
			foreach($values as $key => $value){
				if(is_bool($value) && is_string($key)){
					$this->setFlagByName($key, $value);
				}
				else if(is_string($value) && in_array($value, $this->flagNames)){
					$this->setFlagByName($value, true);
				}
			}
		}
	}
	
	function getAllFlags(){
		$allFlags = array();
		foreach($this->flagNames as $key => $name){
			$allFlags[$name] = $this->getFlag(1 >> $key);
		}
		return $allFlags;
	}
	
	function getActiveFlags(){
		$activeFlags = array();
		foreach($this->flagNames as $key => $name){
			if($this->getFlag(1 >> $key)){
				$activeFlags[$key] = $name;
			}
		}
		return $activeFlags;
	}
}

abstract class bitwiseFlag{
	protected $values = 0;

	public function getValues(){
		return $this->values;
	}
  
	protected function getFlag($flag){
		return (bool) ($this->values & $flag);
	}

	protected function setFlag($flag, $value){
		if($value){
			$this->values |= $flag;
		}
		else{
			$this->values &= ~$flag;
		}
	}	
}
?>