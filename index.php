<?php
require('class.cronManager.inc.php');
// Windows operating System example:

$var = new cronManager('id','servername','email');
// The following cronjob will open notepad++ every 2 minutes on a windows operating system.
$var->write('Taskname', 'C:\Programme\Notepad++\notepad++.exe', 'Minute', 2, 'Computer name', 'Username', 'id');
// The following will modify the opening of Notepad to be executed every 5 minutes instead of 2 ones
$var->modify('idrisstask', 'C:\Programme\Notepad++\notepad++.exe', 'Minute', 5, 'Computer name', 'Username', 'id');
// The following will delete the task named Task 1
$var->delete('idrisstask');

//Linux Operating System example:		
// The following will write Hello Follx in file linuxtest.txt every 2 minuts.

$var->write('tryinglinux', 'echo "Hello Folk" >> linuxtest.txt', 2);
// The following will modify the writing on the file to every 1  minute instead of 2

$var->modify('tryinglinux', 'echo "Hello Folk" >> linuxtest.txt', 'Minute', 1);
// The following will delete the task named tryinglinux 
$var->delete('tryinglinux');
  

?>
