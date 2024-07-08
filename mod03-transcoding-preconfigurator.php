<?php


// $transcoder_preconfig_arr = transcoder_preconfig($ffstats_arr);
// $transcoder_preconfig_json = json_encode($transcoder_preconfig_arr, JSON_PRETTY_PRINT);

function calc_fps($frate) {
	$tmp = explode('/', $frate);
	if ( count($tmp) != 2 ) return 0;
	$tmp[0] = ceil($tmp[0]);
	$tmp[1] = ceil($tmp[1]);
	return ( $tmp[1] > 0 ) ? sprintf("%.06F", $tmp[0]/$tmp[1]) : 0;
}

function gcd($a,$b) {
	return ($a % $b) ? gcd($b,$a % $b) : $b;
}

function calc_dar($w, $h) {
	$gcd = gcd($w, $h);
	return sprintf("%d:%d", $w/$gcd, $h/$gcd);
}

function transcoder_preconfig($src_ffstats_arr) {
	//$error_text = NULL;

	if ( !is_array($src_ffstats_arr) ) {
		return array('ret' => 1, 'error_text' => "> argument \$src_ffstats is not an array!");
	}

	$select_video_es = -1;
	$select_audio_es = -1;

	if ( count($src_ffstats_arr) == 0 ) {
		return array('ret' => 1, 'error_text' => "> There is no valid media information in the file. BAD file!");
	}

	if ( !isset($src_ffstats_arr['format']) ) {
		return array('ret' => 1, 'error_text' => "> There is no valid information about CONTAINER!");
	}

	if ( !isset($src_ffstats_arr['streams']) ) {
		return array('ret' => 1, 'error_text' => "> There is no valid information about STREAMS!");
	}

	$videos_es = array();
	$audios_es = array();

	// streams section
	foreach ( array_values($src_ffstats_arr['streams']) as $es_data ) {
		//print_r($es_data);
		//norm_exit();
		//if ( isset($es_data['index']) ) 
		if ( isset($es_data['codec_type']) && isset($es_data['codec_name']) ) {

			if ( $es_data['codec_type'] == 'video' ) {
				$videos_es[$es_data['index']] = array(
					'codec_name' => ( isset($es_data['codec_name']) ) ? $es_data['codec_name'] : '',
					'codec_long_name' => ( isset($es_data['codec_long_name']) ) ? $es_data['codec_long_name'] : '',
					'default' => ( isset($es_data['disposition']) && isset($es_data['disposition']['default']) && $es_data['disposition']['default'] == 1 ) ? 1 : 0,
					/* 'language' => ( isset($es_data['tags']) && isset($es_data['tags']['language']) ) ? strtolower($es_data['tags']['language']) : '', */
					'width' => ( isset($es_data['width']) ) ? ceil($es_data['width']) : 0,
					'height' => ( isset($es_data['height']) ) ? ceil($es_data['height']) : 0,
					'sar' => ( isset($es_data['sample_aspect_ratio']) ) ? $es_data['sample_aspect_ratio'] : '',
					'dar' => ( isset($es_data['display_aspect_ratio']) ) ? $es_data['display_aspect_ratio'] : '',
					'dar_calc' => ( isset($es_data['width']) && isset($es_data['height']) ) ? calc_dar($es_data['width'],$es_data['height']) : '',
					'fps' => ( isset($es_data['avg_frame_rate']) ) ? calc_fps($es_data['avg_frame_rate']) : 0, // Average framerate
					'r_fps' => ( isset($es_data['r_frame_rate']) ) ? calc_fps($es_data['r_frame_rate']) : 0, // Real base framerate of the stream
					'pix_fmt' => ( isset($es_data['pix_fmt']) ) ? $es_data['pix_fmt'] : '',
					'bit_rate' => ( isset($es_data['bit_rate']) ) ? ceil($es_data['bit_rate']) : NULL,
					'preconfig_selected' => 0,
					'user_selected' => 0,
					'reset_sar' => 0,
					'reset_dar' => 0
				);

				// check SAR
				if ( isset($es_data['sample_aspect_ratio']) && $es_data['sample_aspect_ratio'] != '1:1' ) {
					// a weird SAR - not a square pixel.
					$videos_es[$es_data['index']]['reset_sar'] = 1;
				}

				// check DAR
				//if ( $videos_es[ $es_data['index'] ]['dar'] != $videos_es[ $es_data['index'] ]['dar_calc'] ) {
				if ( isset($es_data['display_aspect_ratio']) && $es_data['display_aspect_ratio'] != $videos_es[$es_data['index']]['dar_calc'] ) {
					// a weird DAR
					$videos_es[$es_data['index']]['reset_dar'] = 1;
				}

//				// --- temp fix!!!
				$videos_es[$es_data['index']]['reset_sar'] = 0;
				$videos_es[$es_data['index']]['reset_dar'] = 0;

			}

			if ( $es_data['codec_type'] == 'audio' ) {
				$audios_es[$es_data['index']] = array(
					'codec_name' => ( isset($es_data['codec_name']) ) ? $es_data['codec_name'] : '',
					'default' => ( isset($es_data['disposition']) && isset($es_data['disposition']['default']) && $es_data['disposition']['default'] == 1 ) ? 1 : 0,
					'language' => ( isset($es_data['tags']) && isset($es_data['tags']['language']) ) ? strtolower($es_data['tags']['language']) : '',
					'channel_layout' => ( isset($es_data['channel_layout']) ) ? $es_data['channel_layout'] : '',
					'channels' => ( isset($es_data['channels']) ) ? ceil($es_data['channels']) : 0,
					'title' => ( isset($es_data['tags']) && isset($es_data['tags']['title']) ) ? $es_data['tags']['title'] : '',
					'max_volume' => NULL,
					'bit_rate' => ( isset($es_data['bit_rate']) ) ? ceil($es_data['bit_rate']) : NULL,
					'preconfig_selected' => 0,
					'user_selected' => 0
				);
			}
		}
	}

	//print_r($videos_es);
	//print_r($audios_es);

	// Default - automatic selection of the first video track (with the lowest index)
	reset($videos_es); // reset the array pointer
	$select_video_es = key($videos_es);
	$videos_es[$select_video_es]['preconfig_selected'] = 1;
	//echo "The video track ID is automatically selected: $select_video_es\n";

	//print_r($videos_es);

	// Automatically search for an audio track
	$select_audio_es = -1; // safety

	reset($audios_es); // reset the array pointer
	foreach ($audios_es as $es_id => $data) { // Search with Eng language and marking default=1
		if ( $data['language'] == 'eng' && $data['default'] == 1 ) {
			$select_audio_es = $es_id;
			do_log_debug("An Eng language track with default=1 was found (method 1) - es_id: {$select_audio_es}");
			break;
		}
	}

	if ( $select_audio_es == -1 ) {
		reset($audios_es); // reset the array pointer
		foreach ($audios_es as $es_id => $data) { // Search with Eng language 
			if ( strtolower($data['language']) == 'eng' ) {
				$select_audio_es = $es_id;
				do_log_debug("An Eng language track was found (method 2) - es_id: {$select_audio_es}");
				break;
			}
		}
	}

	if ( $select_audio_es == -1 ) {
		reset($audios_es); // reset the array pointer
		//do_log_debug(print_r($audios_es,true));
		foreach ($audios_es as $es_id => $data) { // Otherwise, look for the default=1 track
			if ( $data['default'] == 1 ) {
				$select_audio_es = $es_id;
				do_log_debug("Found an default=1 audio track (method 3) - es_id: {$select_audio_es}");
				break;
			}
		}
	}

	if ( $select_audio_es == -1 ) {
		reset($audios_es); // reset the array pointer
		$select_audio_es = key($audios_es); // At the worst, we take a track with the first lowest ID
		do_log_debug("The track with the lowest ID was selected (method 4) - es_id: {$select_audio_es}");
	}
	$audios_es[$select_audio_es]['preconfig_selected'] = 1;


	//print_r($videos_es);
	//print_r($audios_es);
	//return NULL; //test
	$bit_rate_sum = ( isset($src_ffstats_arr['format']['bit_rate']) ) ? ceil($src_ffstats_arr['format']['bit_rate']) : NULL;
	return array('ret' => 0, 'config' => array('video' => $videos_es, 'audio' => $audios_es, 'bit_rate_sum' => $bit_rate_sum) );
}

?>