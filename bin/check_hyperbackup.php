#!/usr/bin/php
<?php
/************************
 * 
 * check_hyperbackup.php
 * 
 * Script to check the status of backups on Synology NAS
 * 
 * Existing statuses for status :
 * - none			-> OK
 * - backup			-> OK
 * - detect			-> OK
 * - waiting			-> OK
 * - version_deleting		-> WARNING
 * - preparing_version_delete	-> WARNING
 * 
 * Existing statuses for last_bkp_result :
 * - done			-> OK
 * - backingup			-> OK
 * - version_deleting		-> OK
 * - preparing_version_delete	-> OK
 * 
 ************************/
$debug = false;

set_error_handler(
    function ($severity, $message, $file, $line) {
        throw new ErrorException($message, $severity, $severity, $file, $line);
    }
);

function print_help() {
	global $argv;
	echo $argv[0]." [-h -v] -H hostname [-P port] [-s] -u username -p password\n".
			"\n".
			"List of options\n".
			"    -H : hostname to be checked\n".
			"    -P : port to connect to. Defaut is 5000 or 5001 if -s if set\n".
			"    -u : username to connect to host\n".
			"    -p : password to connect to host\n".
			"    -s : activate https (http by default)\n".
			"    -v : verbose. Activate debug info\n".
			"    -h : print this help.\n";
}

function syno_request($url) {
	
	$arrContextOptions=array(
	    "ssl"=>array(
	        "verify_peer"=>false,
	        "verify_peer_name"=>false,
	    ),
	);
	
	global $debug;
	try  
	{ 
		if(false === ($json = file_get_contents($url, false, stream_context_create($arrContextOptions)))) {
			echo "Error on url $url";
			exit(3);
		}
	}
	catch (Exception $e)
	{
		echo "Error on url $url : ".$e->getCode()." - ".$e->getMessage();
		exit(3);
	}
    $obj = json_decode($json);
    if($debug) {echo "$url\n"; print_r($obj);}
    if($obj->success != "true"){
    	echo "Error while getting $url\n. ".print_r($obj, true);
    	exit(3);
    }
    else
    return $obj;
}

$ssl = "";

// Example from https://www.nas-forum.com/forum/topic/46256-script-web-api-synology/
// Parsing Args
if(isset($options['h'])) { print_help(); exit;}
if(isset($options['v'])) $debug = true;

$options = getopt("shPvH:u:p:");
if($debug) print_r($options);

// Check servername
if(!isset($options['H'])) {echo "Hostname not defined.\n";print_help();exit;} else {
	$port = 5000;
	if(isset($options['s'])) {
		$port = 5001;
		$ssl = 's';
	}
	if(isset($options['P'])) $port = $options['P'];
	$server = "http$ssl://".$options['H'].":$port";
}

// Check username
if(!isset($options['u'])) {echo "Username not defined.\n";print_help();exit;} else $login = $options['u'];

//Check password
if(!isset($options['p'])) {echo "Password not defined.\n";print_help();exit;} else $pass = $options['p'];


    /* API VERSIONS */
    //SYNO.API.Auth
    $vAuth = 6;
    
	$api = "SYNO.Backup.Task";
    //$api = "SYNO.DownloadStation.Task";
    
    $sessiontype="HyperBackup";
    //$sessiontype="downloadStation";
    

    //Get SYNO.API.Auth Path (recommended by Synology for further update)
    $obj = syno_request($server.'/webapi/query.cgi?api=SYNO.API.Info&method=Query&version=1&query=SYNO.API.Auth');
    $path = $obj->data->{'SYNO.API.Auth'}->path;

    //Login and creating SID
    $obj = syno_request($server.'/webapi/'.$path.'?api=SYNO.API.Auth&method=Login&version='.$vAuth.'&account='.$login.'&passwd='.$pass.'&session='.$sessiontype.'=&format=sid');
    
	//authentification successful
	$sid = $obj->data->sid;
	if($debug) echo "SId : $sid\n";
    
    
    //Get SYNOSYNO.Backup.Statistics (recommended by Synology for further update)
    $obj = syno_request($server.'/webapi/query.cgi?api=SYNO.API.Info&method=Query&version=1&query='.$api);
    $path = $obj->data->{$api}->path;
	
	//list of known tasks
	$obj = syno_request($server.'/webapi/'.$path.'?api='.$api.'&version=1&method=list&_sid='.$sid);
	
	$status_n = 0; // Service OK
	
	$nagios_status = array (
		0 => "OK",
		1 => "WARNING",
		2 => "CRITICAL",
		3 => "UNKNOWN",
		);
	
	foreach($obj->data->task_list as $task) {
    	if($debug) print_r(task);	
		$obj = syno_request($server.'/webapi/'.$path.'?api='.$api.'&version=1&method=status&blOnline=false&additional=%5B%22last_bkp_time%22%2C%22next_bkp_time%22%2C%22last_bkp_result%22%2C%22is_modified%22%2C%22last_bkp_progress%22%5D&task_id='.$task->task_id.'&_sid='.$sid);
		if($debug) print_r($obj);
		$last_bkp_status = $obj->data->last_bkp_result;
		$task_status = $obj->data->status;
		
		$s = 0;
		// check last_bkp_result
		if($last_bkp_status === "done" )
			$status_n = max(0, $status_n);
		elseif($last_bkp_status === "none" )
			$status_n = max(1, $status_n);
		elseif($last_bkp_status === "backingup") 
			$status_n = max(0, $status_n);
                elseif($last_bkp_status === "version_deleting")
                        $status_n = max(0, $status_n);
                elseif($last_bkp_status === "preparing_version_delete")
                        $status_n = max(0, $status_n);
		else
			$status_n = max(2, $status_n);
			
		// Check task_status
		if($task_status === "none") { // Normal situation : no end going backup and last backup was success
			$status_n = max(0, $status_n);
		}
		elseif($task_status === "detect") { // Ongoing backup
			$status_n = max(0, $status_n);
		}
		elseif($task_status === "waiting") { // Waiting for backup
			$status_n = max(0, $status_n);
		}
		elseif($task_status === "backup") { // Ongoing backup
			$status_n = max(0, $status_n);
		}
		elseif($task_status === "version_deleting" || $task_status === "preparing_version_delete") { //Ongoing/praparation of deletion of old version
                        $status_n = max(1, $status_n);
			$s = 1;
                }
		elseif($last_bkp_status === "resuming" && $task_status === "backup") { // Resuming backup
			$status_n = max(0, $status_n);
		}
		elseif($last_bkp_status === "suspended") { // Backup suspended
			$status_n = max(1, $status_n);
		}
		else { // Default value for unknow situation
			$status_n = max(2, $status_n);
			$s = 2;
		}
		echo "Backup ".$task->name." status is $task_status. Last backup result: $last_bkp_status. Status is ".$nagios_status[$s]."\n";
	}
	
	echo "\nOverall backup status is ".$nagios_status[$status_n]."\n";
	

	//Get SYNO.API.Auth Path (recommended by Synology for further update)
    $obj = syno_request($server.'/webapi/query.cgi?api=SYNO.API.Info&method=Query&version=1&query=SYNO.API.Auth');
    $path = $obj->data->{'SYNO.API.Auth'}->path;
    
    //Logout and destroying SID
    $obj = syno_request($server.'/webapi/'.$path.'?api=SYNO.API.Auth&method=Logout&version='.$vAuth.'&session=HyperBackup&_sid='.$sid);
    
    /*
		Nagios understands the following exit codes:
		
		0 - Service is OK.
		1 - Service has a WARNING.
		2 - Service is in a CRITICAL status.
		3 - Service status is UNKNOWN.
	*/
    exit ($status_n);
    
?>
