<?php

// del
//function gcd($a,$b) {
//	return ($a % $b) ? gcd($b,$a % $b) : $b;
//}


function transcoder_cmd_builder($coder_config_arr) {
	//if ( !strlen($src_vfile) ) {
	//	return array('ret' => 1, 'error_text' => "cmd_duilder: Bad SRC filename");
	//}

	if ( !is_array($coder_config_arr) ) {
		return array('ret' => 1, 'error_text' => "cmd_builder: \$coder_config_arr not array");
	}

	//if ( !strlen($dst_vfile) ) { echo "Bad DST filename in func transcoder_cmd_builder()!\n"; return; }

	$cmd_arr = array();

	$max_width = 1280; // px
	$max_height = 720; // px
	$max_fps = 25;
	$max_video_bitrate = 3000000; // 3.0Mbit/sec
	//$max_volume = 0;

	$audio_es_id = -1;
	$video_es_id = -1;


	// Looking for a custom VIDEO track
	foreach ( $coder_config_arr['audio'] as $es_index => $es_config ) {
		if ( $es_config['user_selected'] == 1 ) {
			$audio_es_id = $es_index;
			break;
		}
	}

	// If there is no custom AUDIO track, look for an automatic track.
	if ( $audio_es_id == -1 ) {
		foreach ( $coder_config_arr['audio'] as $es_index => $es_config ) {
			if ( $es_config['preconfig_selected'] == 1 ) {
				$audio_es_id = $es_index;
				break;
			}
		}
	}


	// Looking for a custom VIDEO track
	foreach ( $coder_config_arr['video'] as $es_index => $es_config ) {
		if ( $es_config['user_selected'] == 1 ) {
			$video_es_id = $es_index;
			break;
		}
	}

	// If there is no custom VIDEO track, look for an automatic one
	if ( $video_es_id == -1 ) {
		foreach ( $coder_config_arr['video'] as $es_index => $es_config ) {
			if ( $es_config['preconfig_selected'] == 1 ) {
				$video_es_id = $es_index;
				break;
			}
		}
	}


	// Calculate by direct and bypass paths the speed of the selected VIDEO track
	$src_video_bitrate = 0;
	if ( $coder_config_arr['video'][$video_es_id]['bit_rate'] != NULL ) {
		$src_video_bitrate = $coder_config_arr['video'][$video_es_id]['bit_rate'];

	} elseif ( $coder_config_arr['bit_rate_sum'] != NULL ) {
		$src_audio_bitrate_sum = 0;
//		$other_video_bitrate_sum = 0;

		foreach ( $coder_config_arr['audio'] as $es_index => $es_config ) {
			$src_audio_bitrate_sum += ceil($es_config['bit_rate']);
		}
		$src_video_bitrate = $coder_config_arr['bit_rate_sum'] - $src_audio_bitrate_sum; // without taking  bitrate of neighboring video tracks.
	} else {
		return array('ret' => 1, 'error_text' => "cmd_builder: Can't calculate bitrate of selected video track (ES_ID {$video_es_id}).");
	}



	do_log_info("We'll work with flows: VIDEO_ES_ID={$video_es_id}; AUDIO_ES_ID={$audio_es_id}.");
	do_log_debug("Flow rate of the source video track is determined = ".round($src_video_bitrate/1000)." Kbps" );

	// You can see all supported codecs with the command "ffmpeg -codecs"
	// $cmd = FFMPEG_BIN." -y -hide_banner -loglevel error -progress /dev/stdout -i {$src_vfile} {$dst_vfile}";

	// $FFMPEG_PATH \
	// -i $IN_PATH/$IN_FILENAME -preset fast -vcodec libx264 -vf yadif=1,scale='min(1280,iw)':-1 \
	// -acodec mp3 -start_number 10000 -hls_time 30 -hls_list_size 0 -f hls /mnt/Cache-OUT/$IN_FILENAME/index.m3u8 #1> /tmp/ffmpeg-current.log 2>&1 &

	// volume analyze: ./ffmpeg_4.1.3 -y -hide_banner -loglevel error -progress /dev/stdout -i 1000let -af "volumedetect" -vn -sn -dn -f null /dev/null
	// ./ffmpeg_4.1.3 -y -hide_banner -loglevel info -i 10000let.mp4 -af "volumedetect" -vn -sn -dn -f null /dev/null 2>&1 | /bin/grep "max_volume"


	//echo "";




	//$cmd_arr[] = "-i {$src_vfile}"; // Input file
	$cmd_arr[] = "-y"; // Overwrite DST file without confirmation
	$cmd_arr[] = "-threads 4"; // Number of process threads
	$cmd_arr[] = "-hide_banner"; // Do not output the FFmpeg build banner to the STDERR thread
	$cmd_arr[] = "-loglevel error"; // Output only errors and nothing else to the STDERR stream
	$cmd_arr[] = "-progress /dev/stdout"; // Output real-time operation statistics to the STDOUT stream
	$cmd_arr[] = "-preset medium";
	//$cmd_arr[] = "-sameq"; // same quality
	//$cmd_arr[] = "-qscale 0";

	//$cmd_arr[] = "-vsync 2 -r {$max_fps}"; // limit out video FPS ???? It doesn't work on interlaced video (double FPS at output)

	//$cmd_arr[] = "-ss 00:00:10 -t 600"; // read some track time; grab input from 0 to 30sec
	//$cmd_arr[] = "-fflags +bitexact -flags:v +bitexact -flags:a +bitexact"; // remove all metadata - DOESN'T WORK
	$cmd_arr[] = "-map_metadata -1"; // remove all metadata v2 - OK
	//$cmd_arr[] = "-metadata creation_time=1999-09-17T21:30:00"; // set fake timestamp
	$cmd_arr[] = "-sn"; // disable subtitles

	$cmd_arr[] = "-map 0:$video_es_id"; // choose audio ES id and map to index 0
	$cmd_arr[] = "-map 0:$audio_es_id"; // choose video ES id and map to index 1

	if ( $coder_config_arr['video'][$video_es_id]['codec_name'] == 'h264'
		&& $coder_config_arr['video'][$video_es_id]['pix_fmt'] == 'yuv420p'
		&& $coder_config_arr['video'][$video_es_id]['width'] <= $max_width
		&& $coder_config_arr['video'][$video_es_id]['height'] <= $max_height
		&& $coder_config_arr['video'][$video_es_id]['fps'] <= $max_fps
		&& $coder_config_arr['video'][$video_es_id]['reset_sar'] == 0
		//&& $coder_config_arr['video'][$video_es_id]['reset_dar'] == 0
		&& $src_video_bitrate <= ceil($max_video_bitrate*1.05) // tolerance +5%
										) {

		//do_log_info("The video track is H.264 compliant:yuv420p:W<={$max_width}:H<={$max_height}:FPS<={$max_fps}:vBITRATE<={$max_video_bitrate}:RESET_SAR=0:RESET_DAR=0 and will simply be copied (-codec:v copy).");
		do_log_info("The video track is H.264 compliant:yuv420p:W<={$max_width}:H<={$max_height}:FPS<={$max_fps}:vBITRATE<={$max_video_bitrate}:RESET_SAR=0 and will simply be copied (-codec:v copy).");
		$cmd_arr[] = "-codec:v copy";

	} else {
		do_log_info("The video track will be re-encoded in H.264.");
		$sar = ( $coder_config_arr['video'][$video_es_id]['reset_sar'] == 0 ) ? str_replace(':','/',$coder_config_arr['video'][$video_es_id]['sar']) : "1/1";
		//$sar = ( $coder_config_arr['video'][$video_es_id]['reset_sar'] == 0 ) ? $coder_config_arr['video'][$video_es_id]['sar'] : "1:1";
		//$set_sar = "setsar=1/1";

		$dar = ( $coder_config_arr['video'][$video_es_id]['reset_dar'] == 0 ) ? str_replace(':','/',$coder_config_arr['video'][$video_es_id]['dar']) : str_replace(':','/',$coder_config_arr['video'][$video_es_id]['dar_calc']);
		//$dar = ( $coder_config_arr['video'][$video_es_id]['reset_dar'] == 0 ) ? $coder_config_arr['video'][$video_es_id]['dar'] : $coder_config_arr['video'][$video_es_id]['dar_calc'];
		//$set_dar = ",setdar=9/5";

		// -----------------------------------------------------------------
		$inp_w = $coder_config_arr['video'][$video_es_id]['width'];
		$inp_h = $coder_config_arr['video'][$video_es_id]['height'];

		$max_w = $max_width;
		$max_h = $max_height;

		$calc_w = 0;
		$calc_h = 0;


		if ( $inp_w > $max_w || $inp_h > $max_h ) {
			do_log_debug("## Need resize");
			$scale = $inp_w/$inp_h;
			do_log_debug("## Scale is: $scale");

			$calc_w = $max_w;
			$calc_h = ceil( ($max_w/$scale)/2 )*2;

			if ( $calc_h <= $max_h ) {
				do_log_debug("## by W");
			} else {
				$calc_w = ceil( ($max_h*$scale)/2 )*2;
				$calc_h = $max_h;
				do_log_debug("## by H");
			}

			do_log_debug("## SizeCalculated: $calc_w x $calc_h");
		} elseif ( $inp_w & 1 || $inp_h & 1 ) {
			do_log_debug("## No need resize, but ... ");
			$calc_w = ceil($inp_w/2)*2;
			$calc_h = ceil($inp_h/2)*2;
		}
		// -----------------------------------------------------------------


		//$cmd_arr[] = "-codec:v libx264 -pix_fmt yuv420p -filter:v \"fps=55,yadif=deint=interlaced,scale='min(720,iw)':-1\"";
		//$cmd_arr[] = "-codec:v libx264 -pix_fmt yuv420p -filter:v \"yadif=deint=interlaced,scale=w='min({$max_width},iw)':h='min({$max_height},ih)':force_original_aspect_ratio=decrease,setsar=1:1,setdar={$set_dar}\"";
//		$cmd_arr[] = "-codec:v libx264 -crf 16 -pix_fmt yuv420p -filter:v \"yadif=deint=interlaced,scale=w='min({$max_width},iw)':h='min({$max_height},ih)':force_original_aspect_ratio=decrease,setsar={$sar},setdar={$dar}\"";

//		$cmd_arr[] = "-s {$calc_w}x{$calc_h}";
//		$cmd_arr[] = "-aspect {$calc_w}:{$calc_h}"; // SAR (pixel aspect)
//		$cmd_arr[] = "-vf scale=1230:720"; // DAR ?
		if ( $coder_config_arr['video'][$video_es_id]['fps'] > $max_fps || $coder_config_arr['video'][$video_es_id]['r_fps'] > $max_fps ) {
			$cmd_arr[] = "-r {$max_fps}"; // slow down FPS
		}
		$cmd_arr[] = "-codec:v libx264 -profile:v high -level 3.1 -pix_fmt yuv420p";
//		$cmd_arr[] = "-crf 18"; // constant rate factor - quality ?
		$cmd_arr[] = "-bf 0"; // disable B-frames
		//$cmd_arr[] = "-vf \"pad='ceil(iw/2)*2':'ceil(ih/2)*2'\"";

//		$cmd_arr[] = "-filter:v \"yadif=deint=interlaced,scale=w='min({$max_width},ceil(iw/2)*2)':h='min({$max_height},ceil(ih/2)*2)':force_original_aspect_ratio=decrease,pad='min({$max_width},ceil(iw/2)*2)':'min({$max_height},ceil(ih/2)*2)':(ow-iw)/2:(oh-ih)/2,setsar={$sar},setdar={$dar}\"";
//		$cmd_arr[] = "-vf \"pad='ceil(iw/2)*2':'ceil(ih/2)*2'\"";
/*
		if ( $calc_h <= $max_h ) { // by Width
		 $cmd_arr[] = "-filter:v yadif=deint=interlaced,scale={$calc_w}:-2,setsar={$sar},setdar={$dar}";
		} else {
		 $cmd_arr[] = "-filter:v yadif=deint=interlaced,scale=-2:{$calc_h}:force_original_aspect_ratio=decrease,setsar={$sar},setdar={$dar}";
		}
*/

// fps check here !!
//		$cmd_arr[] = "-filter:v \"yadif=deint=interlaced,scale={$calc_w}:{$calc_h}:force_original_aspect_ratio=decrease,pad={$calc_w}:{$calc_h},setsar={$sar},fps=fps=25\"";
		$cmd_arr[] = "-filter:v \"yadif=deint=interlaced,scale={$calc_w}:{$calc_h}:force_original_aspect_ratio=decrease,pad={$calc_w}:{$calc_h},setsar={$sar}\"";
//		$cmd_arr[] = "-filter:v \"yadif=deint=interlaced,setdar=20:10\"";

//		$cmd_arr[] = "-codec:v libx264 -pix_fmt yuv420p";

		if ( $src_video_bitrate > $max_video_bitrate ) {
			//$cmd_arr[] = "-b:v {$max_video_bitrate} -minrate ".ceil($max_video_bitrate*0.4)." -maxrate ".ceil($max_video_bitrate*1.2)." -bufsize {$max_video_bitrate}"; // downgrade video bitrate
			$cmd_arr[] = "-b:v {$max_video_bitrate} -maxrate {$max_video_bitrate} -bufsize {$max_video_bitrate}"; // downgrade video bitrate
		}
		//$cmd_arr[] = "-codec:v libx264 -filter:v \"scale=w=800:h=600:force_original_aspect_ratio=decrease\"";
	}


	$up_volume = $coder_config_arr['audio'][$audio_es_id]['max_volume'] * -1;
	if ( $up_volume > 0 ) {
		do_log_info("The volume of the audio track will be increased by {$up_volume}dB (-filter:a \"volume={$up_volume}dB\").");
		$cmd_arr[] = "-codec:a aac -ar 44100 -ab 192K -ac 2 -sample_fmt fltp -filter:a \"volume={$up_volume}dB\""; // downmix to 44100, stereo, 32 bit (list: ffmpeg -sample_fmts)
		//$cmd_arr[] = "-codec:a aac -ar 44100 -filter:a \"volume=1.0,pan=stereo|FL=0.5*FC+0.707*FL+0.707*BL+0.5*LFE|FR=0.5*FC+0.707*FR+0.707*BR+0.5*LFE\"";
		//$cmd_arr[] = "-codec:a aac"; // aac
	} else {
		do_log_info("The volume of the audio track is normal.");
		$cmd_arr[] = "-codec:a aac -ar 44100 -ab 192K -ac 2 -sample_fmt fltp"; // downmix to 44100, stereo, 32 bit (list: ffmpeg -sample_fmts)
	}
	
	$cmd_arr[] = "-max_muxing_queue_size 4096"; // solve error FFmpeg: Too many packets buffered for output stream 0:1


	//$cmd_arr[] = "-format mp4"; // Input format
	//$cmd_arr[] = "{$dst_vfile}"; // Output file

	return array('ret' => 0, 'tcmd' => implode(' ', $cmd_arr) );
}


?>