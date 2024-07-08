<?php


function audio_volume_analyze($db, $job_id, $src_vfile, $audio_es_id) {
	$db->query("UPDATE video_pool SET src_audio_analyzed='in_progress' WHERE video_pool.id = {$job_id} LIMIT 1;");
	$max_volume = 0;

	exec(FFMPEG_BIN." -y -hide_banner -loglevel info -i {$src_vfile} -vn -sn -dn -map 0:$audio_es_id -filter:a \"volumedetect\" -f null /dev/null 2>&1", $output, $prog_ret);
	if ( $prog_ret ) {
		$db->query("UPDATE video_pool SET src_audio_analyzed='error' WHERE video_pool.id = {$job_id} LIMIT 1;");
		return array('ret' => 1, 'error_text' => "> FFmpeg crashed with return code '{$prog_ret}'. Before leaving, it said: ". implode(' ',$output) );
	}
	//print_r($output);
	//print_r($prog_ret);

	foreach ( $output as $line ) {
		//if ( $offset = mb_strpos($line, 'max_volume:') ) {
		if ( preg_match('/max_volume:\s+-?(\d+.\d+)/', $line, $res) ) {
			//echo "Found! Offset is $offset\n";
			//echo "substr: ". substr($line, $offset) ."\n";
			//++echo "Match: ".print_r($res)."\n";
			$max_volume = round(-$res[1],1)*1;
			//++echo "max_volume is: {$max_volume}\n";
			break;
		}
	}

	$db->query("UPDATE video_pool SET src_audio_analyzed='yes' WHERE video_pool.id = {$job_id} LIMIT 1;");

	return array('ret' => 0, 'max_volume' => $max_volume); // in dB
}

?>