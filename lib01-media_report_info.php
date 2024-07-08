<?php

function map_format2exten($format) {
	//$exten = 'unkn';

	$map_exten = array(
		'mp4' => 'mp4',
		'avi' => 'avi',
		'mpegts' => 'ts',
		'matroska' => 'mkv',
		'webm' => 'webm',
		'wmv' => 'wmv',
		'mpeg' => 'mpg',
		'flv' => 'flv',
		'asf' => 'asf',
		'ogg' => 'ogg'
	);

	$format_arr = explode(',', $format);
	foreach ( $map_exten as $what => $to ) {
		if ( in_array($what, $format_arr) ) {
			//echo "$what -> $to\n";
			return $to;
			//break;
		}
	}
	return 'unkn';
}

//function get_ffstats_media_info($ffstats_arr = array()) {
function media_report_info($ffstats_arr) {
	$src_test_failed = 0;

	$error_text = '';
	$media['info'] = array();
	$media['warn'] = array();
	$media['error'] = array();

	$format_name = '';
	$duration_sec = 0; // float
	$video_es_count = 0;
	$audio_es_count = 0;
	$video_width = 0;
	$video_height = 0;
	$video_fps = 0;
	$fps_max_per_es = 0;
	$video_sar = 'NA';
	$video_dar = 'NA';
	$html5_play = 0;

	$vcodec_name = array(); // need this ?
	$acodec_name = array(); // need this ?

	if ( !is_array($ffstats_arr) || count($ffstats_arr) == 0 ) {
		$src_test_failed = 1;
		$media['error'][] = "No valid media data information of FFstats format argument (not an array, array empty, bad data in JSON...).";
		return array('ret' => $src_test_failed, 'error_text' => implode("; ",$media['error']), 'report' => $media);
	}

	// format section
	if ( isset($ffstats_arr['format']) ) {
		if ( isset($ffstats_arr['format']['format_name']) ) $format_name = $ffstats_arr['format']['format_name'];
		if ( isset($ffstats_arr['format']['format_long_name']) ) $media['info'][] = "Container Format: ".$ffstats_arr['format']['format_long_name']." :: {$format_name}";
		if ( isset($ffstats_arr['format']['nb_streams']) ) $media['info'][] = "Total Elementary Streams (ES): ".$ffstats_arr['format']['nb_streams'];
		if ( isset($ffstats_arr['format']['bit_rate']) ) $media['info'][] = sprintf("Total stream rate: %d Kbps", ($ffstats_arr['format']['bit_rate']/1000) );
		if ( isset($ffstats_arr['format']['duration']) ) {
			$duration_sec = $ffstats_arr['format']['duration'];
			//$media['info'][] = "Duration: ". sprintf("%02d:%02d", floor(ceil($duration_sec)/60), ceil($duration_sec)%60) ." = ". $duration_sec ." sec.";
			$media['info'][] = sprintf("Duration: %02dm:%02ds = %.02F sec.", floor(ceil($duration_sec)/60), ceil($duration_sec)%60, $duration_sec);
			$duration_sec = sprintf("%.06F",$duration_sec); // to write to the DB
			$media['info'][] = '';
		}
		if ( isset($ffstats_arr['format']['tags']) && is_array($ffstats_arr['format']['tags']) ) {
			foreach ( $ffstats_arr['format']['tags'] as $key => $val ) {
				$media['info'][] = "Тэг '$key': ". $val ." (". mb_detect_encoding($val) .")";
			}
			$media['info'][] = '';
		}
		//$media_info .= "\n";
	}

	// streams section
	if ( isset($ffstats_arr['streams']) ) {
		foreach ( array_values($ffstats_arr['streams']) as $es_data ) {
			//print_r($es_data);
			$line = '';
			if ( isset($es_data['disposition']) && isset($es_data['disposition']['default']) && $es_data['disposition']['default'] == 1 ) $line .= "[*] "; else $line .= "-- ";
			if ( isset($es_data['index']) ) $line .= sprintf("%02d: ", $es_data['index']);
			if ( isset($es_data['codec_type']) ) {
				if ( $es_data['codec_type'] == 'video' ) {
					$video_width = 0; $video_height = 0; $video_fps = 0; // crap! there can be more than one video track. FIX IT!
					$video_es_count++;
					if ( isset($es_data['width']) ) $video_width = $es_data['width'];
					if ( isset($es_data['height']) ) $video_height = $es_data['height'];
					if ( isset($es_data['avg_frame_rate']) ) {
						$tmp = explode('/', $es_data['avg_frame_rate']);
						$tmp[0] = ceil($tmp[0]);
						$tmp[1] = ceil($tmp[1]);
						$video_fps = ( $tmp[1] > 0 ) ? sprintf("%.02F", $tmp[0]/$tmp[1]) : 0;
						if ( $video_fps > 0 && $video_fps > $fps_max_per_es ) $fps_max_per_es = $video_fps;
					}
					if ( isset($es_data['sample_aspect_ratio']) ) $video_sar = $es_data['sample_aspect_ratio'];
					if ( isset($es_data['display_aspect_ratio']) ) $video_dar = $es_data['display_aspect_ratio'];
				}
				if ( $es_data['codec_type'] == 'audio' ) {
				//if ( isset($es_data['tags']) && isset($es_data['tags']['language']) ) $media_info .= "(". $es_data['tags']['language'] .")\n";
					$audio_es_count++;
				}
				$line .= ucfirst($es_data['codec_type'])." - ";
			}
			if ( isset($es_data['codec_name']) ) {
					$atmp = array();
					$line .= strtoupper($es_data['codec_name']);
					if ( isset($es_data['profile']) ) $atmp[] = strtolower($es_data['profile']);
					if ( isset($es_data['level']) ) $atmp[] = sprintf("%1.1f", $es_data['level']/10);
					if ( isset($es_data['pix_fmt']) ) $atmp[] = strtolower($es_data['pix_fmt']);
					if ( !empty($atmp) ) $line .= " [". implode(',', $atmp)."]";
					unset($atmp);
					//рабочее// if ( isset($es_data['codec_long_name']) ) $line .= " (".strtolower($es_data['codec_long_name']).")";
			}

			if ( isset($es_data['codec_name']) && isset($es_data['codec_type']) && $es_data['codec_type'] == 'video' ) $vcodec_name[] = $es_data['codec_name'];
			if ( isset($es_data['codec_name']) && isset($es_data['codec_type']) && $es_data['codec_type'] == 'audio' ) $acodec_name[] = $es_data['codec_name'];

			if ( isset($es_data['channel_layout']) ) $line .= ", ". $es_data['channel_layout'];

			//if ( isset($es_data['']) ) $line .= ": ". $es_data['']."\n";
			if ( isset($es_data['codec_type']) && $es_data['codec_type'] == 'video' ) $line .= "; {$video_width}x{$video_height} [SAR {$video_sar}, DAR {$video_dar}]; FPS {$video_fps}";
			if ( isset($es_data['tags']) && isset($es_data['tags']['language']) ) $line .= "; Language: ". strtoupper($es_data['tags']['language']);
			if ( isset($es_data['tags']) && isset($es_data['tags']['title']) ) $line .= "; Info: \"". $es_data['tags']['title'] ."\"";
			if ( isset($es_data['bit_rate']) ) $line .= sprintf("; Stream: %d Kbps", ($es_data['bit_rate']/1000));

			$media['info'][] = $line;
		}

		$media['info'][] = '';
	}

	$media['info'][] = "Streams total = video: {$video_es_count} pcs.; audio: {$audio_es_count} pcs.";

	if ( $duration_sec < 5 ) {
		$media['warn'][] = sprintf("Short duration: %.02F sec. (<5).", $duration_sec);
	}

	if ( $video_width != 0 && $video_width < 1280 ) $media['warn'][] = "Low graphic resolution: {$video_width}x{$video_height}.";
	if ( $video_fps != 0 && $video_fps < 23 ) $media['warn'][] = sprintf("Low video frame rate: %.02F fps.", $video_fps);
	if ( $video_fps != 0 && $video_fps > 25 ) $media['warn'][] = sprintf("High frame rate video: %.02F fps.", $video_fps);
	if ( $video_es_count > 1 ) $media['warn'][] = "Multiple VIDEO tracks.";
	if ( $audio_es_count > 1 ) $media['warn'][] = "Multiple AUDIO tracks.";

	// does it play in html5 player ?
	( strlen($format_name) > 0 ) ? $format_name_arr = explode(',', $format_name) : $format_name_arr = array();
	if ( in_array('mp4',$format_name_arr) && isset($vcodec_name[0]) && isset($vcodec_name[0]) && $vcodec_name[0] == 'h264' && $acodec_name[0] == 'aac' ) $html5_play = 1;

	if ( $video_es_count > 0 && $fps_max_per_es == 0 ) {
		$media['error'][] = "Frame rate 0!";
		$src_test_failed = 1; // fatal error
	}

	if ( $video_es_count == 0 ) {
		$media['error'][] = "There are no video tracks.";
		$src_test_failed = 1; // fatal error
	}

	if ( $audio_es_count == 0 ) {
		$media['error'][] = "No audio tracks.";
		$src_test_failed = 1; // fatal error
	}

	//return array('ret' => 0, $media_info, $duration_sec, $src_test_failed);
	//return array('ret' => $src_test_failed, 'error_text' => $error_text, 'report' => $media );
	return array(
		'ret' => $src_test_failed,
		'error_text' => implode("; ",$media['error']),
		'report' => $media,
		'duration_sec' => $duration_sec,
		'format_name' => $format_name,
		'map_to_exten' => map_format2exten($format_name),
		'html5_play' => $html5_play
	);
}



?>