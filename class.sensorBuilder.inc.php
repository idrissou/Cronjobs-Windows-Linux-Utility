<?php
/* About the file
 *	Name:			class.sensorBuilder.inc.php
 *	Description:	---
 *					---
**/
if(!@include_once('./class.sensor.inc.php')){
	throw new Exception('Unable to include sensor for sensorBuilder.');
}

class sensorBuilder extends sensor{
	//copys all sensor-files (but *) and creating a zip-file
	//* only index.php and settings.php OR index.html
	
	//download files: ...(header)...
	//zipping files: zipArchive (siehe Beispiel)
}

?>