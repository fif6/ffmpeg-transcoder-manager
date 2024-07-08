<?php


define('POLLER_PATH', realpath(dirname(__FILE__)) );
chdir( POLLER_PATH ); // Set the current working directory

if ( count($argv) < 2 ) {
	echo("Specify the handler mode when running the script: script.php fmanager|transcoder\n");
	exit(1);
}

if ( $argv[1] == "fmanager" ) {
	define('RUN_MODE', 0);
	define('PID_FILE', '/var/run/video_poller-fmanager.pid' );
	define('LLOG_FILE', '../../log/video_poller-fmanager.log' );

} else if ( $argv[1] == "transcoder" ) {
	define('RUN_MODE', 1);
	define('PID_FILE', '/var/run/video_poller-transcoder.pid' );
	define('LLOG_FILE', '../../log/video_poller-transcoder.log' );

} else {
	echo("Unknown startup mode, parameter: {$argv[1]}\n");
	exit(1);
}



define('FFMPEG_BIN', realpath('./ffmpeg_4.2.2') );
define('FFPROBE_BIN', realpath('./ffprobe_4.2.2') );

// moved to config.php
//define('CACHE_IN_DIR', '../../pool/Cache-IN');
//define('CACHE_OUT_DIR', '../../pool/Cache-OUT');

define('LOG_STDOUT', true);

include "../config.php";
define('SQL_HOST', $config['mysql_host']);
define('SQL_USER', $config['mysql_user']);
define('SQL_PASSWORD', $config['mysql_passwd']);
define('SQL_DBNAME', $config['mysql_dbname']);



include "../classes/helpers/functions.php";
#include_once("./ffstats-media-info.php");
include "./lib01-media_report_info.php";
include "./lib02-class_http_simple.php";

$db = mysqli_init();
$db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 5);
@$db->real_connect(SQL_HOST, SQL_USER, SQL_PASSWORD, SQL_DBNAME);
if ( $db->connect_errno ) {
	echo "Can't connect to DB: ". $db->connect_error ."\n";
	exit(254);
}


//if ( !$result = $db->query("SELECT UNIX_TIMESTAMP() AS test;") ) {
//	echo "Bad SQL query\n";
//}

//while ( $row = $result->fetch_object() ) {
//	echo $row->test ."\n";
//}




echo "\n";

echo "POLLER_PATH: ".POLLER_PATH."\n";
echo "PID_FILE: ".PID_FILE."\n";
echo "LLOG_FILE: ".LLOG_FILE."\n";
echo "FFMPEG_BIN: ".FFMPEG_BIN."\n";
echo "FFPROBE_BIN: ".FFPROBE_BIN."\n";
echo "CACHE_IN_DIR: ".CACHE_IN_DIR."\n";
echo "CACHE_OUT_DIR: ".CACHE_OUT_DIR."\n";
//echo "STORAGES_DIR: ".STORAGES_DIR."\n";
echo "Script real file path is: ". __FILE__ ."\n";


function do_log($message='', $type='info', $log_stdout=TRUE, $log_tofile=TRUE) {
	$message = "[".date("Y.m.d H:i:s")."] ".strtoupper($type) .": ". $message;

	if ( $log_stdout ) {
		if ( $type == 'info' ) echo "\033[37m";
		if ( $type == 'warn' ) echo "\033[33m";
		if ( $type == 'error' ) echo "\033[31m";
		if ( $type == 'debug' ) echo "\033[97m";
		echo $message ."\n";
		echo "\033[0m";
		flush();
	}

	if ( $log_tofile ) {
		$loghdl = @fopen(LLOG_FILE, 'a');
		if ( !$loghdl ) {
			echo "WARN: Can't open log file path: ". LLOG_FILE ."\n";
		}
		fwrite($loghdl, $message."\n");
		fclose($loghdl);
	}
}

function do_log_info($message, $log_stdout=TRUE, $log_tofile=TRUE) { do_log($message, 'info', $log_stdout, $log_tofile); }
function do_log_warn($message, $log_stdout=TRUE, $log_tofile=TRUE) { do_log($message, 'warn', $log_stdout, $log_tofile); }
function do_log_error($message, $log_stdout=TRUE, $log_tofile=TRUE) { do_log($message, 'error', $log_stdout, $log_tofile); }
function do_log_debug($message, $log_stdout=TRUE, $log_tofile=TRUE) { do_log($message, 'debug', $log_stdout, $log_tofile); }

function test_log_file() {
	$loghdl = @fopen(LLOG_FILE, 'a');
	if ( !$loghdl ) {
		echo "ERROR: Can't open log file path: ".LLOG_FILE."\n";
		exit(254);
	}
	fclose($loghdl);
	
	chmod(LLOG_FILE, 0666);
}

function test_ffmpeg_bin() {
	if ( !is_executable(FFMPEG_BIN) ) {
		do_log_error("FFmpeg binary file isn't executable on path: '".FFMPEG_BIN."'");
		exit(254);
	}
}

function escape_fname($fname) {
	return mb_ereg_replace("[^\w\-\.\_]", "\\\\0", $fname);
}


echo "\n";
test_log_file();
test_ffmpeg_bin();
do_log_info("The media library job scheduler is up and running!");
//exit(0);


// ----------------------------------------------------------------------------------

if( !defined('PID_FILE') ){
	do_log_error("Constant 'PID_FILE' is not defined!");
	exit(254);
}

if ( strtoupper(PHP_OS) != 'LINUX' ) {
	do_log_error("This script must run under OS Linux only.");
	exit(254);
}
	
/* do NOT run this script through a web browser */
if ( !isset($_SERVER["argv"][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR']) ) {
	echo ("<br><strong>This script is only meant to run at the command line.</strong>");
	exit(254);
}

// --- Check if the PID file exists. If it exists, retrieve the process ID and check its existence in the system.
$pid = 0;
if ( is_readable(PID_FILE) ) {
	do_log_warn("PID file '". PID_FILE ."' exists.");
	if ( ! $PIDF = fopen(PID_FILE, 'r') ) {
		do_log_error("Can't open PID_FILE '". PID_FILE ."' forread. Exiting."); // opening for read only
		exit(254);
	}

	$pid = (int) fread($PIDF, 6);
	fclose($PIDF);
}

if ( $pid && is_readable("/proc/{$pid}/stat") ) { // Is proccess number already running by OS KERNEL
	do_log_error("A copy of my process exists on the system (PID $pid). Exiting.");
	//Log::write("PID {$pid} exists.");
	exit(254);
}
// ---


// --- Trying to create a lock file and write the current PID to it
$pid = getmypid();
if ( file_put_contents(PID_FILE, $pid, LOCK_EX) ) {
	do_log_info("PID_FILE '". PID_FILE ."' created and PID ($pid) wrote.");
} else {
	do_log_error("Unable to create and write PID_FILE '". PID_FILE ."'. Exiting.");
	exit(254);
}
// ---

function pid_remove() {
	if ( unlink(PID_FILE) ) {
		do_log_info("PID_FILE has deleted: '". PID_FILE ."'");
		return TRUE;
	} else {
		do_log_error("Can't delete PID_FILE: '". PID_FILE ."'");
		return FALSE;
	}
	//echo "Exit point.".PHP_EOL;
	//exit(0);
}




/* tick use required as of PHP 4.3.0 to accomodate signal handling */
$GLOBALS['sig_job_abort'] = FALSE; // global var
$GLOBALS['transcoder_in_run'] = FALSE; // global var
$GLOBALS['abort_new_job'] = FALSE; // global var
declare(ticks = 1);

function sig_handler($signo) {
	do_log_debug("Signal has caught, the code is {$signo}");
	switch ($signo) {
		//case SIGTERM:
		case SIGINT: // Ctrl-C received
			do_log_warn("SIGINT has got (Ctrl-C). Shutdown the script with the SIGINT system command.");
			if ( $GLOBALS['transcoder_in_run'] == FALSE && $GLOBALS['sig_job_abort'] == FALSE ) norm_exit();
			$GLOBALS['sig_job_abort'] = TRUE;
		break;

		case SIGCHLD: // child proccess (FFmpeg) dies
			if ( $GLOBALS['transcoder_in_run'] == FALSE ) {
				do_log_info("Child FFmpeg/FFprobe proccess has died - NORMAL");
			} else {
				do_log_error("Child FFmpeg/FFprobe proccess has died - ERROR");
				//$GLOBALS['ffmpeg_error']
			}
			$GLOBALS['transcoder_in_run'] = FALSE;
		break;

		case SIGTSTP: // Ctrl-Z received
			do_log_warn("Got SIGTSTP (Ctrl-Z). The script will terminate when the current task is finished.");
			$GLOBALS['abort_new_job'] = TRUE; // global var
		break;

		default:
			/* ignore all other signals */
	}
}

function norm_exit() {
	if ( $GLOBALS['db'] ) {
		do_log_info("Disconnecting from DB.");
		$GLOBALS['db']->close();
	}
	pid_remove();
	do_log_info("Process (pid ".getmypid().") safely stopped.");
	echo "\n";
	exit(0);
}

/* install signal handlers for UNIX only */
pcntl_signal(SIGINT, "sig_handler");
pcntl_signal(SIGCHLD, "sig_handler");
pcntl_signal(SIGTSTP, "sig_handler");



// ---------------------------------------------------------------------------------------------------------------------------------------------------

do_log_info("The beginning of the basic calculations ...");


include "./mod01-move-to-storage.php";
include "./mod02-delete-from-storage.php";
include "./mod03-transcoding-preconfigurator.php";
include "./mod04-audio-analyzer.php";
include "./mod05-transcoder-cmd-builder.php";
include "./mod06-transcoder.php";

while ( 1 && RUN_MODE == 0 && $GLOBALS['sig_job_abort'] == FALSE ) { // file manager mode
	// -- Searching for jobs to move files to storage
	do_log_info("Search for jobs PERMANENTLY moving files to storage...",true,false);
	$ret = move_to_storage($db);
	if ( $ret['ret'] == 0 ) {
		do_log_debug("move_to_storage returns: OK",true,false);
	} else {
		do_log_error("move_to_storage returns: '".$ret['error_text']."'");
		//$db->query("UPDATE video_pool SET dst_storage_copied='error', error_text=CONCAT(error_text,'".$db->escape_string($ret['error_text'])."\n') WHERE video_pool.id = {$job_id} LIMIT 1;");
		// bad, but not critical. We can also work with the transcoder
		norm_exit(); // will no longer work any further. The transcoder lives in a separate process
	}

	// -- Deleting files from storage
	do_log_info("Search for jobs to REMOVE files from storage...",true,false);
	$ret = delete_from_storage($db);
	if ( $ret['ret'] == 0 ) {
		do_log_debug("delete_from_storage returns: OK",true,false);
	} else {
		do_log_error("delete_from_storage returns: '".$ret['error_text']."'");
		// bad, but not critical. We can also work with the transcoder
		norm_exit(); // will no longer work any further. The transcoder lives in a separate process
	}

	sleep(60);

	if ( $GLOBALS['abort_new_job'] == TRUE ) norm_exit();
}

	//do_log_warn("BREAKING WHILE(1) #1!");
	//norm_exit();

if ( RUN_MODE == 1 ) { // clean up accidentally completed transcoder jobs - for example, the power suddenly failed, and the process of transcoding file.... was in progress.
	// once when the script is run for the first time in transcoder mode
	$db->query("UPDATE video_pool SET transcoding_status='aborted' WHERE transcoding_status='in_progress' LIMIT 1;");
}

while ( 1 && RUN_MODE == 1 && $GLOBALS['sig_job_abort'] == FALSE ) { // transcoder mode
	// -- Searching for transcoder jobs
	do_log_info("Search for TRANSCODER jobs...", true, false);
//	while (1) { // #2
		if ( $GLOBALS['abort_new_job'] == TRUE ) norm_exit(); // abort new tasks

		if ( !$result = $db->query("SELECT
					video_pool.id,
					video_pool.upload_filename,
					video_pool.stored_filename,
					video_pool.src_ffstats_json,
					video_pool.dst_filename,
					video_pool.src_audio_analyzed,
					video_pool.transcoder_config_json
					# video_pool.transcoding_call_stop,
					# video_pool.transcoding_status
				FROM video_pool
				WHERE
					video_pool.transcoding_status = 'in_queue'
				ORDER BY video_pool.transcoding_queue_prio ASC, video_pool.id ASC
				LIMIT 1;
		") ) {
			do_log_error('Bad SQL query 5. Exiting.');
			norm_exit();
		}

		if ( !$row = $result->fetch_object() ) {
			$result->free();
			do_log_info('There are no active jobs to the transcoder.', true, false);
			do_log_warn("BREAKING WHILE(1) #2", true, false);
			sleep(30); // we go to sleep until we find the next task
			continue;
		}

		$job_id = ceil($row->id); // foolproofing
		$job_upload_filename = $row->upload_filename;
		$job_stored_filename = $row->stored_filename;
		$job_src_ffstats_json = $row->src_ffstats_json;
//		$job_src_audio_analyzed = $row->src_audio_analyzed;
		$job_transcoder_config_json = $row->transcoder_config_json;
		$old_job_dst_filename = $row->dst_filename;
		$job_dst_filename = ceil(microtime(TRUE)*10000).".mp4";

		$result->free();
		//do_log_info("Found the task! video_pool.id: {$job_id}, video_pool.upload_filename: ".$row->upload_filename.", video_pool.stored_filename: {$job_stored_filename}; The output file will be: {$job_dst_filename}");
		do_log_info("TRANSCODER - Found the task! video_pool.id: {$job_id}, video_pool.upload_filename: '{$job_upload_filename}', video_pool.stored_filename: '{$job_stored_filename}'");

//		// clean up previous errors
//		//$db->query("UPDATE video_pool SET error_text='' WHERE video_pool.id = {$job_id} LIMIT 1;");
		// remove previous transcoding results
		if ( strlen($old_job_dst_filename) > 1 && is_readable(CACHE_OUT_DIR."/".$old_job_dst_filename) ) {
			do_log_info("Delete the old output file '".CACHE_OUT_DIR."/".$old_job_dst_filename."'");
			unlink(CACHE_OUT_DIR."/".$old_job_dst_filename);
		}
		// Reset previous error text and earlier transcoding results
		$db->query("UPDATE video_pool SET error_text='', transcoding_progress=0, dst_filename='', dst_size_bytes=0, dst_media_info='', dst_media_warning='' WHERE video_pool.id = {$job_id} LIMIT 1;");


		//-- Transcoding Configurator

		do_log_info("Prepare the configuration for the transcoder.");
//		$job_src_ffstats = json_decode($job_src_ffstats_json, true);
		$job_src_ffstats = @json_decode($job_src_ffstats_json, true);
		$src_media_report = media_report_info($job_src_ffstats);
		if ( $src_media_report['ret'] != 0 ) {
			$error_text = "Bad JSON data about the source video (DB field 'video_pool.src_ffstats_json').";
			do_log_error($error_text);
			$db->query("UPDATE video_pool SET transcoding_status='error', error_text=CONCAT(error_text,'".$db->escape_string($error_text)."\n') WHERE video_pool.id = {$job_id} LIMIT 1;");
			unset($job_src_ffstats_json);
			unset($job_src_ffstats);
			unset($src_media_report);
			continue; // skip the task
		}
		$duration_sec = $src_media_report['duration_sec']; // needs later for transcoder

		do_log_info("Check if there is an existing transcoder configuration in the DB.");
		$tconfig = @json_decode($job_transcoder_config_json, true);
		if ( is_array($tconfig) && isset($tconfig['video']) && isset($tconfig['audio']) ) {
			do_log_info("The configuration already exists - we will not generate a new one.");
			//do_log_debug("\$tconfig is: ".print_r($tconfig,true));
		} else {
			do_log_info("There is no ready configuration - we generate a new one via transcoder_preconfig().");
			$ret = transcoder_preconfig($job_src_ffstats);
			if ( $ret['ret'] == 0 ) {
				do_log_debug("transcoder_preconfig: ret OK");
			} else {
				do_log_error($ret['error_text']);
				$db->query("UPDATE video_pool SET transcoding_status='error', error_text='transcoder_preconfig: ".$db->escape_string($ret['error_text'])."\n' WHERE video_pool.id = {$job_id} LIMIT 1;");
				continue; // skip the task
			}
			$tconfig = $ret['config'];
		}

		//do_log_debug(print_r($tconfig,true));

		//-- Audio analyzer
		
		//-- first look for the track selected MANUALLY by the user. This is a higher priority 
		reset($tconfig['audio']); // reset the array pointer
		foreach ( $tconfig['audio'] as $audio_es_id => $tags ) {
			if ( $tags['user_selected'] == 1 ) {
				do_log_debug("Selected AUDIO_ES_ID: $audio_es_id - specified by user (by configuration)");
				break; // foreach exit
			} else $audio_es_id = -1;
		}

		//-- If there was no track selected by the user, we pick up the selected track AUTOMATICALLY
		if ( $audio_es_id == -1 ) {
			reset($tconfig['audio']); // reset the array pointer
			foreach ( $tconfig['audio'] as $audio_es_id => $tags ) {
				if ( $tags['preconfig_selected'] == 1 ) {
					do_log_debug("Selected AUDIO_ES_ID: $audio_es_id - specified automatically (in the configuration)");
					break; // found.
				} else $audio_es_id = -1;
			}
		}

		do_log_info("Calculate the maximum volume of audio track AUDIO_ES_ID: {$audio_es_id}, CODEC: {$tags['codec_name']}/{$tags['channel_layout']} ");
		$ret = audio_volume_analyze($db, $job_id, CACHE_IN_DIR."/pool/$job_stored_filename", $audio_es_id);
		//$ret = array('ret' => 0, 'max_volume' => 0); // for debug
		if ( $ret['ret'] == 0 ) {
			do_log_debug("audio_volume_analyze: ret OK");
		} else {
			do_log_error($ret['error_text']);
			$db->query("UPDATE video_pool SET transcoding_status='error', error_text='audio_volume_analyze: ".$db->escape_string($ret['error_text'])."\n' WHERE video_pool.id = {$job_id} LIMIT 1;");
			continue; // skip the task
		}
		$max_volume = $ret['max_volume'];
		do_log_info("> Max volume level is: {$max_volume} dB.");
		$tconfig['audio'][$audio_es_id]['max_volume'] = $max_volume;

		// save new transcoder_json_config
		$db->query("UPDATE video_pool SET transcoder_config_json='".$db->escape_string(json_encode($tconfig, JSON_PRETTY_PRINT|JSON_FORCE_OBJECT))."' WHERE video_pool.id = {$job_id} LIMIT 1;");


		//-- FFmpeg shell CMD configurator
		$ret = transcoder_cmd_builder($tconfig);
		if ( $ret['ret'] == 0 ) {
			do_log_debug("transcoder_cmd_builder: ret OK");
		} else {
			do_log_error($ret['error_text']);
			$db->query("UPDATE video_pool SET transcoding_status='error', error_text='transcoder_cmd_builder: ".$db->escape_string($ret['error_text'])."\n' WHERE video_pool.id = {$job_id} LIMIT 1;");
			continue; // skip the task
		}
		$coder_cmd = $ret['tcmd'];
		do_log_debug($coder_cmd);
		//norm_exit();

		//-- Транскодер
		do_log_info("Starting transcoder...");
		$ret = transcoder($db, $job_id, $coder_cmd, $job_stored_filename, $duration_sec);
		if ( $ret['ret'] == 0 ) {
			do_log_debug("transcoder: ret OK");
		} else {
			do_log_error("transcoder: ret BAD");
		}

		// restore default statuses before the next task
		$GLOBALS['transcoder_in_run'] = FALSE;
		//$GLOBALS['sig_job_abort'] = FALSE;

		// task is complete. Let's move on to the next one
		//norm_exit(); // tmp
		do_log_info("-- MOD TRANSCODER ITERATION END --");
		do_log_info("");
//		sleep(3);
//		break; // go to the top
//	}

	//norm_exit(); //debug
	sleep(3); // just in case (an infinite loop can quickly inflate the logs)
}


norm_exit();

?>