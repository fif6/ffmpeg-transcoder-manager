<?php

function transcoder($db, $job_id, $coder_cmd, $job_stored_filename, $duration_sec) {

	$job_dst_filename = ceil(microtime(TRUE)*10000).".mp4";

	if ( !is_readable(CACHE_IN_DIR."/pool/".$job_stored_filename) ) {
		$error_text = "> Unable to open for reading stored_filename: '".CACHE_IN_DIR."/pool/".$job_stored_filename."'.";
		//do_log_error($error_text);
		$db->query("UPDATE video_pool SET transcoding_status='error', error_text=CONCAT(error_text,'transcoder: ".$db->escape_string($error_text)."\n') WHERE video_pool.id = {$job_id} LIMIT 1;");
		return array('ret' => 1, 'error_text' => $error_text);
	}


	$src_duration_us = $duration_sec * 1000000;
	do_log_info("> src_duration_us is: {$src_duration_us}");


	$db->query("UPDATE video_pool SET dst_filename='".$db->escape_string($job_dst_filename)."' WHERE video_pool.id = {$job_id} LIMIT 1;");
	do_log_info("> Initiate (create) output file '".CACHE_OUT_DIR."/".$job_dst_filename."' and change it' owner to www-data:www-data");
	touch(CACHE_OUT_DIR."/".$job_dst_filename);
	chown(CACHE_OUT_DIR."/".$job_dst_filename, 'www-data');
	chgrp(CACHE_OUT_DIR."/".$job_dst_filename, 'www-data');

	do_log_info("> Launch FFmpeg daughter");
	//$cmd = "/usr/bin/nice -n 10 ".FFMPEG_BIN." -y -hide_banner -loglevel error -progress /dev/stdout -i ".CACHE_IN_DIR."/pool/".$job_stored_filename." -y -map_metadata -1 -codec:v libx264 -pix_fmt yuv420p -filter:v \"yadif=deint=interlaced,scale=w='min(1280,iw)':h='min(720,ih)':force_original_aspect_ratio=decrease\" -codec:a aac -ar 44100 -ab 128K -ac 2 -sample_fmt fltp ".CACHE_OUT_DIR."/".$job_dst_filename;
	$cmd = "/usr/bin/nice -n 10 ".FFMPEG_BIN." -i ".CACHE_IN_DIR."/pool/".$job_stored_filename." {$coder_cmd} ".CACHE_OUT_DIR."/".$job_dst_filename;
	do_log_debug("doing proc_open: {$cmd}");

	$chld_pipes = array();
	$chld_descriptors = array(
		0 => array('file', '/dev/null', 'r'), // stdin is the channel that the child will read from
		1 => array('pipe', 'w'), // stdout is the channel to which FFmpeg will record the progress of the process for the parsing format (when the '-progress /dev/stdout' option is checked).
		2 => array('pipe', 'w') // stderr is the channel to which FFmpeg will write the text of the overall output mixed with errors.
		//2 => array('file', 'ffmpeg_stderr.log', 'a')
	);
	
	$time_start = time();
	$ffproc = proc_open($cmd, $chld_descriptors, $chld_pipes);
	//$transcoder_in_run = TRUE; ?
	$GLOBALS['transcoder_in_run'] = TRUE;


	if ( !is_resource($ffproc) ) {
		do_log_error("> \$ffproc isn't a resource!");
		norm_exit();
	}

	// renice ffmpeg priority to low
	/*
	$status = proc_get_status($ffproc);
	if ( $status['running'] && $status['pid']>0 ) {
		do_log_info("Lower the priority of the valid FFmpeg pid: ".$status['pid']." -> renice 15");
		exec("/usr/bin/renice -n 15 -p ".$status['pid'],$renice_out, $prog_ret);
		do_log_info("renice says: ". implode('; ', $renice_out) );
		unset($renice_out,$prog_ret);
	}
	*/

	//stream_set_blocking($chld_pipes[1], false);

	while (1) {
		//sleep(1);
		//if ( !is_resource($ffproc) ) {
		//	do_log_error("\$ffproc Not resource!");
		//}

		$status = proc_get_status($ffproc);
		if ( !$status['running'] || $GLOBALS['sig_job_abort'] == TRUE ) {
			$GLOBALS['transcoder_in_run'] = FALSE;
			//print_r($status);
			do_log_debug("> proc_get_status => pid: ".$status['pid'].", running: ".$status['running'].", signaled: ".$status['signaled'].", stopped: ".$status['stopped'].", exitcode: ".$status['exitcode'].", termsig: ".$status['termsig'].", stopsig: ".$status['stopsig']);

			if ( $status['exitcode'] > 0 ) { // FFmpeg suddenly died. Otherwise - FFmpeg nailed on signal, code -1; Ended by itself without adventures, code: 0;
				$error_text = "> The FFmpeg child process terminated itself with an error: ". stream_get_contents($chld_pipes[2]);
				do_log_error($error_text);
				$db->query("UPDATE video_pool SET transcoding_status='error', error_text=CONCAT(error_text,'transcoder: ".$db->escape_string($error_text)."\n') WHERE video_pool.id = {$job_id} LIMIT 1;");
			}

			do_log_debug("> Close \$chld_pipes[1].");
			if ( is_resource($chld_pipes[1]) ) fclose($chld_pipes[1]);
			do_log_debug("> Close \$chld_pipes[2].");
			if ( is_resource($chld_pipes[1]) ) fclose($chld_pipes[1]);

			if ( $status['running'] ) {
				do_log_debug("> FFmpeg childs are still running. Kill all them NOW!");
				$ppid = $status['pid'];
				$pids = preg_split('/\s+/', `/bin/ps -o pid --no-heading --ppid $ppid`);
				foreach( $pids as $ffpid ) {
					if( is_numeric($ffpid) ) {
						do_log_debug("> Killing FFmpeg PID $ffpid by SIGTERM.");
						posix_kill($ffpid, SIGTERM); //15 is the SIGTERM signal
					}
				}
				sleep(2);
			}

			do_log_debug("> Closing \$ffproc handle.");
			$return_value = proc_close($ffproc);
			do_log_debug("> proc_close: returned $return_value.");

			unset($ffproc);
			unset($chld_pipes);
			unset($chld_descriptors);
			sleep(1);

			$dst_size_bytes = filesize(CACHE_OUT_DIR."/".$job_dst_filename);
			$db->query("UPDATE video_pool SET transcoding_call_stop=0, dst_size_bytes={$dst_size_bytes},dst_ffstats_json='{ }' WHERE video_pool.id = {$job_id} LIMIT 1;");
			//unset($dst_size_bytes);

			if ( $dst_size_bytes > 128 ) {
//			if ( $status['exitcode'] == 0 ) { // FFmpeg was alive. So it makes sense to test the exhaust
				exec(FFPROBE_BIN." -v quiet -print_format json -show_format -show_streams ".CACHE_OUT_DIR."/".$job_dst_filename." 2>&1", $ffprobe_out, $prog_ret);
				if ( $prog_ret ) {
					$error_text = "> FFprobe crashed with return code '{$prog_ret}' when testing the target file. Before leaving, it said: '". implode('; ',$ffprobe_out)."'";
					//do_log_error($error_text);
					$db->query("UPDATE video_pool SET transcoding_status='error', error_text=CONCAT(error_text,'transcoder: ".$db->escape_string($error_text)."\n') WHERE video_pool.id = {$job_id} LIMIT 1;");
					return array('ret' => 1, 'error_text' => $error_text);
				} else {
					//print_r($ffprobe_out);
					//print_r($prog_ret);
					//$dst_media_info = '';
					$dst_ffstats_json = implode("\n", $ffprobe_out);
					$dst_ffstats_arr = @json_decode($dst_ffstats_json, true);
					//list ($dst_media_info) = get_ffstats_media_info($dst_ffstats_arr);
					$media_report = media_report_info($dst_ffstats_arr);
					if ( $media_report['ret'] == 0 ) {
						$dst_media_info = implode("\n", $media_report['report']['info']);
						$dst_media_warning = implode("\n", $media_report['report']['warn']);
						$db->query("UPDATE video_pool SET dst_ffstats_json='".$db->escape_string($dst_ffstats_json)."', dst_media_info='".$db->escape_string($dst_media_info)."', dst_media_warning='".$db->escape_string($dst_media_warning)."' WHERE video_pool.id = {$job_id} LIMIT 1;");
					} else {
						//$error_text = "> Bad contents of FFprobe JSON response. Data dst_ffstats_json and dst_media_info are not written to the DB.";
						$error_text = implode("\n", $media_report['report']['error']);
						//do_log_error($error_text);
						$db->query("UPDATE video_pool SET transcoding_status='error', error_text=CONCAT(error_text,'transcoder: ".$db->escape_string($error_text)."\n') WHERE video_pool.id = {$job_id} LIMIT 1;");
						return array('ret' => 1, 'error_text' => $error_text);
					}
				}
			}

			unset($dst_ffstats_json, $dst_ffstats_arr, $dst_media_info, $dst_media_warning); // doesn't always work?
			unset($ffprobe_out);
			unset($prog_ret);
			unset($dst_size_bytes);

//			break;
			return array('ret' => 0); // end of transcoding. All is OK
		}




		do_log_debug("FFmpeg process works ...");
		//echo stream_get_contents($pipes[2]);
		// some manupulations with "/tmp/atest.log"
		$line = '';

		$frame = 0;
		$speed = '';
		$out_time_us = 0;
		$bytes_out = 0;
		$bitrate = '';
		$dup_f = 0;
		$drop_f = 0;

		//fseek($pipes[1], -1, SEEK_END);
		//echo "pipes[1] strlen: ". strlen($pipes[1]) ."\n";
		//while ( !feof($pipes[1]) ) {
		//	fread($pipes[1], 8192);
		//}
		//stream_get_contents($pipes[1]);
		//$pipes[1] = '';
		//if ( !feof($pipes[1]) ) fread($pipes[1], 102400);

		while ( is_resource($chld_pipes[1]) && $line = stream_get_line($chld_pipes[1], 128, "\n") ) {
			//do_log_debug("FFmpeg out line is: {$line}");
			list($par_name, $par_value) = explode('=', $line, 2);
			//echo 'strlen: '.strlen($line)." -> ".$line."\n";
			if ($par_name == 'bitrate') $bitrate = $par_value;
			if ($par_name == 'total_size') $bytes_out = ceil($par_value);
			if ($par_name == 'out_time_us') $out_time_us = ceil($par_value);
			if ($par_name == 'dup_frames') $dup_f = ceil($par_value);
			if ($par_name == 'drop_frames') $drop_f = ceil($par_value);
			if ($par_name == 'speed') $speed = $par_value;
			if ($par_name == 'frame') $frame = ceil($par_value);
			if ($par_name == 'progress') {
				$progress = round( ($out_time_us/$src_duration_us)*100000, 0);
				if ( $progress > 100000 ) $progress = 100000; // It happens that $src_duration_us is less than the length of the picture on the output $out_time_us. Then we limit it to 100.000% max
				do_log_info(sprintf(
					"frame: %d, speed: %.1F, elapsed: %s, out_time: %s, %dMiB, brate: %s, dup_f: %d, drop_f: %d, progress: %.3F%%",
					$frame, $speed, gmdate("H:i:s",time()-$time_start), gmdate("H:i:s",($out_time_us/1000000)), ($bytes_out/1048576), $bitrate, $dup_f, $drop_f, ($progress/1000)
				), true, false); // писать в stdout, НЕ писать в логфайл

				$db->query("UPDATE video_pool SET video_pool.transcoding_status='in_progress', video_pool.transcoding_progress={$progress} WHERE video_pool.id = {$job_id} LIMIT 1;");

				if ( $GLOBALS['sig_job_abort'] == TRUE ) { // this flag is expected from the previous iteration ...
					$db->query("UPDATE video_pool SET video_pool.transcoding_status='aborted' WHERE video_pool.id = {$job_id} LIMIT 1;");
					do_log_warn("\$sig_job_abort = TRUE -> breaking from pipe reading cycle..");
					break;
				}

				if ($par_value == 'continue') {
					//$transcoder_in_run = TRUE;

					//++do_log_info("Poll the database for a command to force a task to stop.");
					if ( !$result = $db->query("SELECT video_pool.transcoding_call_stop FROM video_pool WHERE video_pool.id = {$job_id} LIMIT 1;") ) {
						do_log_error('Bad SQL query 6. Exiting');
						norm_exit();
					}

					//if ( !$db_says_stop = $result->fetch_object()->transcoding_call_stop ) {
					if ( $result->fetch_object()->transcoding_call_stop ) {
					//if ( $db_says_stop == TRUE ) {
						do_log_info("The database tells to stop the current job ID {$job_id}.");
						$GLOBALS['sig_job_abort'] = TRUE;
					}

					/*
					$status = proc_get_status($ffproc);
					if ( $status['running'] && $sig_job_abort == TRUE ) { // Force stop during transcoding
					// if ( $status['running'] && 0 )
						do_log_warn("Transcoding aborted by user signal. Closing proc pipes. Killing FFmpeg.");
						$transcoder_in_run = FALSE;
						if ( is_resource($chld_pipes[1]) ) fclose($chld_pipes[1]);

						print_r($status);
						$ppid = $status['pid'];
						$pids = preg_split('/\s+/', `/bin/ps -o pid --no-heading --ppid $ppid`);
						foreach( $pids as $ffpid ) {
							if( is_numeric($ffpid) ) {
								do_log_warn("Killing FFmpeg PID $ffpid by SIGTERM.");
								posix_kill($ffpid, SIGTERM); //15 is the SIGTERM signal
							}
						}
						sleep(2);
						$status = proc_get_status($ffproc);
						print_r($status);
					}
					*/

				} else { // The transcoding completed naturally
					$GLOBALS['transcoder_in_run'] = FALSE;
					$db->query("UPDATE video_pool SET video_pool.transcoding_status='done', video_pool.transcoding_progress=100000 WHERE video_pool.id = {$job_id} LIMIT 1;");
					do_log_info("Transcoding DONE for job ID $job_id.");
					break;
				}
				//usleep(100000); // 100ms
				//$status = proc_get_status($ffproc);
				//break;
			}
		}
		//do_log_info("Before sleep");
		//sleep(1);
		//do_log_debug("After sleep");
	}
}




?>