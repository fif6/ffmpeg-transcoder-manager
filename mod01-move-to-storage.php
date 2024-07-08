<?php

function move_to_storage($db) {
	// -- Searching for jobs to move files to storage
	// SELECT movie_id FROM movie_files WHERE video_pool_id={$move_id} LIMIT 1;
	
	while (1) {
		$result = $db->query("SELECT
					video_pool.id,
					video_pool.stored_filename,
					video_pool.dst_filename,
					video_pool.dst_size_bytes,
					video_pool.src_media_duration,
					movie_files.movie_id
				FROM video_pool, movie_files
				WHERE
					video_pool.transcoding_status = 'done'
					AND video_pool.dst_storage_copied = 'in_queue'
					AND movie_files.video_pool_id = video_pool.id
				ORDER BY video_pool.id
				LIMIT 1;
		");

		if ( !$result ) {
			return array('ret' => 1, 'error_text' => "Bad SQL query 1. Exiting.");
		}

		if ( $GLOBALS['abort_new_job'] == TRUE ) norm_exit();

		if ( !$row = $result->fetch_object() ) {
			$result->free();
			do_log_info("> There are no jobs to move files to a storage.",true,false);
			return array('ret' => 0);
		}


		//$MOOVE_ERROR = TRUE;
		$move_id = ceil($row->id);
		$src_filename = $row->stored_filename;
		$dst_filename = $row->dst_filename; // move_filename
		$move_size_bytes = ceil($row->dst_size_bytes);
		$src_media_duration = ceil($row->src_media_duration);
		$movie_id = $row->movie_id;
		$result->free();

		//do_log_info("SRC: ".CACHE_IN_DIR."/$src_filename, DST: ".CACHE_OUT_DIR."/$dst_filename");
		do_log_info("We will move the video_pool.ID file: {$move_id}, NAME: {$dst_filename}, SIZE: ".size_human2($move_size_bytes)." to storage.");
		$db->query("UPDATE video_pool SET dst_storage_copied='in_progress' WHERE id={$move_id};");


		// Read access test of a relocated (transcoded) file
		if ( !is_file(CACHE_OUT_DIR.'/'.$dst_filename) && !is_readable(CACHE_OUT_DIR.'/'.$dst_filename) ) {
			$error_text = "Unable to open the relocated (transcoded) file '".CACHE_OUT_DIR."/{$dst_filename}' for reading.";
			$db->query("UPDATE video_pool SET dst_storage_copied='error', error_text=CONCAT(error_text,'move_to_storage: ".$db->escape_string($error_text)."\n')  WHERE id={$move_id};");
			return array('ret' => 1, 'error_text' => $error_text);
			//continue; // terminate work with this file. Skip iteration while(1)
		}

		$result = $db->query("SELECT MAX(id) AS max_id FROM storage_files;");
		if ( !$result ) {
			return array('ret' => 1, 'error_text' => 'move_to_storage: Bad SQL query 2.');
			//do_log_error('move_to_storage: Bad SQL query 2. Exiting.');
			//norm_exit();
		}
		$new_id = ceil($result->fetch_object()->max_id) + 1;
		$result->free();
		do_log_debug("New ID for storage_files row is: {$new_id}");



		$result = $db->query("SELECT
				storages.id,
				# storages.local_mount_path,
				storages.proto_scheme,
				storages.hostname,
				storages.port,
				storages.size_bytes,
				storages.size_used_bytes,
				(storages.size_bytes - storages.size_used_bytes) AS size_avail_bytes,
				storages.comment
			FROM storages
			WHERE storages.write_enable=1
			# ORDER BY size_avail_bytes DESC
			ORDER BY size_used_bytes;
		");

		if ( !$result ) {
			return array('ret' => 1, 'error_text' => 'move_to_storage: Bad SQL query 3.');
			//do_log_error('move_to_storage: Bad SQL query 3.');
			//norm_exit();
		}

		while ( $row = $result->fetch_object() ) { // Run down the list of available disks. The first one has the least used space.
			$st_id = $row->id;
			$st_host = $row->proto_scheme ."://". $row->hostname .":". $row->port; //$st_local_mnt = $row->local_mount_path;
			$st_size_bytes = $row->size_bytes;
			$st_size_used_bytes = $row->size_used_bytes;
			$st_size_avail_bytes = $row->size_avail_bytes;
			$st_comment = $row->comment;

			$st_used_perc = sprintf("%.3f", ($st_size_used_bytes/$st_size_bytes)*100 );
			//$st_full_path = STORAGES_DIR."/".escape_fname($st_local_mnt);

			//do_log_info("Selecting the storage with ID: {$st_id}, LOCAL_MNT: {$st_full_path}, SIZE: ".size_human2($st_size_bytes).", USED: ".size_human2($st_size_used_bytes)."/{$st_used_perc}%");
			do_log_info("Selecting the storage with ID: {$st_id}, HOST: '{$st_host}/s{$st_id}/', SIZE: ".size_human2($st_size_bytes).", USED: ".size_human2($st_size_used_bytes)."/{$st_used_perc}%");

			if ( $st_size_avail_bytes < $move_size_bytes ) {
				do_log_warn("There is no free space to put any data on the disk. It may be time to prevent new data from being written to this disk.");
				continue; // abort the loop iteration and look for another storage for this file
			}

			// WebDAV HTTP init
			$http = new http_simple();
			$http->set_log_callback('do_log');
			$http->set_url($st_host."/upload/s".$st_id."/.storage_read_test_file");

			if ( false !== $session = $http->req_head() ) {
				do_log_info("> StorageTest: HTTP DAV server response code: {$session->resp_http_code}");
				if ( $session->resp_http_code == 200 ) {
					do_log_info("> StorageTest: the file '.storage_read_test_file' exists and accesible. OK");
				} elseif ( $session->resp_http_code == 404 ) {
					//do_log_error(">> Test file '.storage_read_test_file' isn't present on the server. BAD");
					//continue;
					//return 1;
					return array('ret' => 1, 'error_text' => "> StorageTest: Test file '.storage_read_test_file' isn't present on the server. BAD");
				} else {
					//do_log_error("-> Unknown server response code!");
					//continue;
					//return 1;
					return array('ret' => 1, 'error_text' => "> StorageTest: Unknown server response code!");
				}
			} else {
				//do_log_error("> StorageTest -> Connection to HTTP DAV server to perform HEAD failed!"); //print_r($session);
				//continue;
				//return 1;
				return array('ret' => 1, 'error_text' => "> StorageTest: Connection to HTTP DAV server to perform HEAD failed!");
			}

			$st_newfile = dbid2filename($new_id).'.'.pathinfo($dst_filename, PATHINFO_EXTENSION);
			//$st_newfile = 'qqq.mp4'; // removing test
			$st_newdir = dbid2foldername($new_id);
			do_log_info("New storage_files.ID will be: {$new_id}, target directory: '{$st_newdir}', target file: '{$st_newfile}'");


			$new_uri = "/upload/s{$st_id}/{$st_newdir}/{$st_newfile}";
			do_log_info("Check the path '{$st_host}{$new_uri}' to place the new file to the storage.");
			$http->set_url("{$st_host}{$new_uri}");
			if ( false !== $session = $http->req_head() ) {
				do_log_info("> PathTest: HTTP DAV server responce code: {$session->resp_http_code}");
				if ( $session->resp_http_code == 404 ) {
					do_log_info("> PathTest: path '{$st_host}{$new_uri}' to the file is free. GOOD");
				} elseif ( $session->resp_http_code == 200 ) {
					//do_log_error("-> The future file '{$st_host}{$new_uri}' already exists on the server. BAD");
					//return 1;
					$db->query("UPDATE video_pool SET dst_storage_copied='error' WHERE id={$move_id};");
					return array('ret' => 1, 'error_text' => "> PathTest: The future file '{$st_host}{$new_uri}' already exists on the server. BAD");
				} else {
					//do_log_error("-> Server responce code is BAD!");
					//continue;
					//return 1;
					$db->query("UPDATE video_pool SET dst_storage_copied='error' WHERE id={$move_id};");
					return array('ret' => 1, 'error_text' => "> PathTest: Server responce code is BAD!!");
				}
			} else {
				//do_log_error("> StorageTest: Connection to HTTP DAV server to perform HEAD failed!"); //print_r($session);
				//continue;
				//return 1;
				$db->query("UPDATE video_pool SET dst_storage_copied='error' WHERE id={$move_id};");
				return array('ret' => 1, 'error_text' => "> PathTest: Connection to HTTP DAV server to perform HEAD failed!");
			}

			do_log_info("Starting to copy the file '".CACHE_OUT_DIR."/{$dst_filename}' > '{$st_host}{$new_uri}'");
			if ( false !== $session = $http->req_put_file(CACHE_OUT_DIR."/{$dst_filename}") ) {
				do_log_info("> Copy: HTTP DAV server responce code: {$session->resp_http_code}");
				if ( in_array($session->resp_http_code, [201,204,200]) ) {
					do_log_info("> Copy: so file successfully created/updated");
				} else {
					//do_log_error("> FILE UPLOAD ERROR!");
					//return 1;
					$db->query("UPDATE video_pool SET dst_storage_copied='error' WHERE id={$move_id};");
					return array('ret' => 1, 'error_text' => "> Copy: error uploading file to WebDAV server!");
				}
			} else {
				//do_log_error("> Connection to HTTP DAV server to perform PUT failed!"); //print_r($session);
				//return 1;
				$db->query("UPDATE video_pool SET dst_storage_copied='error' WHERE id={$move_id};");
				return array('ret' => 1, 'error_text' => "> Copy: Connection to HTTP DAV server to perform PUT failed!");
			}

			do_log_info("Checking the size of a saved file '{$st_host}{$new_uri}'");
			if ( false !== $session = $http->req_head() ) {
				do_log_info("> CopiedTest: HTTP DAV server responce code: {$session->resp_http_code}");
				if ( $session->resp_http_code == 200 ) {
					//do_log_info("-> The '.storage_read_test_file' file exists and is available. OK");
					$stored_size_bytes = ceil($http->get_headers_option($session->resp_headers, "Content-Length"));
					do_log_info("> CopiedTest: the size of the data on the server: $stored_size_bytes bytes; Local: $move_size_bytes bytes");
					if ( $stored_size_bytes === $move_size_bytes ) {
						do_log_info("> CopiedTest: the data size matches.");
					} else {
						//do_log_error("-> The data size does NOT match.");
						//return 1;
						$db->query("UPDATE video_pool SET dst_storage_copied='error' WHERE id={$move_id};");
						return array('ret' => 1, 'error_text' => "> CopiedTest: The data size does NOT match.");
					}
				} elseif ( $session->resp_http_code == 404 ) {
					//do_log_error("-> The new file is not present on the server. BAD");
					//return 1;
					$db->query("UPDATE video_pool SET dst_storage_copied='error' WHERE id={$move_id};"); 
					return array('ret' => 1, 'error_text' => "> CopiedTest: The new file is not present on the server. BAD");
				} else {
					//do_log_error("-> Unknown response code of the server!");
					//return 1;
					$db->query("UPDATE video_pool SET dst_storage_copied='error' WHERE id={$move_id};"); 
					return array('ret' => 1, 'error_text' => "> CopiedTest: Unknown response code of the server!");
				}
			} else {
				//do_log_error("FileTest: Connection to HTTP DAV server to perform HEAD failed!"); //print_r($session);
				//return 1;
				$db->query("UPDATE video_pool SET dst_storage_copied='error' WHERE id={$move_id};");
				return array('ret' => 1, 'error_text' => "> CopiedTest: Connection to HTTP DAV server to perform HEAD failed!");
			}

			// file has copied successfully .....

//			$db->query("INSERT INTO storage_files SET id={$new_id}, movie_id={$movie_id}, add_date=UNIX_TIMESTAMP(), storage_id={$st_id}, folder='{$st_newdir}', filename='{$st_newfile}', size_bytes={$stored_size_bytes};");
			$db->query("INSERT INTO storage_files SET id={$new_id}, movie_id={$movie_id}, add_date=UNIX_TIMESTAMP(), storage_id={$st_id}, folder='{$st_newdir}', filename='{$st_newfile}', size_bytes={$stored_size_bytes}, media_duration={$src_media_duration};");
			$db->query("UPDATE video_pool SET dst_storage_copied='yes', storage_file_id={$new_id} WHERE id={$move_id};");

			$db->query("UPDATE movie_files SET published=1, storage_files_id={$new_id} WHERE video_pool_id={$move_id} LIMIT 1;");

			// here it needs to recalculate the free disk space
			$db->query("UPDATE storages
					SET storages.size_used_bytes=(SELECT SUM(size_bytes) FROM storage_files WHERE storage_files.storage_id={$st_id} AND storage_files.deleted IN ('no','in_queue','error') )
					WHERE storages.id = {$st_id}
					LIMIT 1;
			");

			// Delete the source file from CACHE-IN/pool if it exists
			do_log_info("Delete the original (raw) SRC_FILENAME file: ".CACHE_IN_DIR."/pool/{$src_filename}");
			if ( file_exists(CACHE_IN_DIR.'/pool/'.$src_filename) ) {

				unlink(CACHE_IN_DIR.'/pool/'.$src_filename);
				unlink(CACHE_IN_DIR.'/pool/'.$src_filename.'.ffstats');

				if ( !file_exists(CACHE_IN_DIR.'/pool/'.$src_filename) ) {
					// The file is gone. Deleted. OK.
					do_log_info("> Deleted. OK");
					$db->query("UPDATE video_pool SET src_deleted='yes' WHERE id={$move_id} LIMIT 1;");

					// recalculate free space in Cache-IN/incoming
					exec("rm -f ".CACHE_IN_DIR."/incoming/free_space_left*");
					$SPACE_LEFT = exec("df -h ".CACHE_IN_DIR." | tail -1 | awk '{print $4}'");
					exec("touch ".CACHE_IN_DIR."/incoming/free_space_left_{$SPACE_LEFT}iB");
					exec("chown www-data:www-data ".CACHE_IN_DIR."/incoming/free_space_left*");
					unset($SPACE_LEFT);
				} else {
					$error_text = "> Unable to delete the source file '".CACHE_IN_DIR."/pool/{$src_filename}'";
					do_log_warn($error_text);
					$db->query("UPDATE video_pool SET src_deleted='error', error_text=CONCAT(error_text,'".$db->escape_string($error_text)."\n') WHERE id={$move_id} LIMIT 1;");
				}
			} else {
				$error_text = "> There is no file to remove SRC_FILENAME: '".CACHE_IN_DIR."/pool/{$src_filename}'.";
				do_log_warn($error_text);
				$db->query("UPDATE video_pool SET src_deleted='error', error_text=CONCAT(error_text,'".$db->escape_string($error_text)."\n') WHERE id={$move_id} LIMIT 1;");
			}

			// Remove the transcoded file from CACHE-OUT if it exists
			do_log_info("Delete the transcoded file DST_FILENAME:  '".CACHE_OUT_DIR."/{$dst_filename}'");
			if ( file_exists(CACHE_OUT_DIR.'/'.$dst_filename) ) {

				unlink(CACHE_OUT_DIR.'/'.$dst_filename);

				if ( !file_exists(CACHE_OUT_DIR.'/'.$dst_filename) ) {
					// The file is gone. Deleted. OK.
					do_log_info("> Deleted. OK");
					$db->query("UPDATE video_pool SET dst_deleted='yes' WHERE id={$move_id} LIMIT 1;");
				} else {
					$error_text = "> Unable to delete the transcoded DST_FILENAME file: '".CACHE_IN_DIR."/{$dst_filename}'.";
					do_log_warn($error_text);
					$db->query("UPDATE video_pool SET dst_deleted='error', error_text=CONCAT(error_text,'".$db->escape_string($error_text)."\n') WHERE id={$move_id} LIMIT 1;");
				}
			} else {
				$error_text = "> There is no file to remove DST_FILENAME: '".CACHE_OUT_DIR."/{$dst_filename}'.";
				do_log_warn($error_text);
				$db->query("UPDATE video_pool SET src_deleted='error', error_text=CONCAT(error_text,'".$db->escape_string($error_text)."\n') WHERE id={$move_id} LIMIT 1;");
			}

			// raise the movie up (update the sorting date)
			do_log_info("Raise the movie to the top of the list (update the sorting date movie.order_date), movie.id is: '{$movie_id}'");
			$db->query("UPDATE movie SET movie.order_date=UNIX_TIMESTAMP() WHERE movie.id={$movie_id} LIMIT 1;");

			//$MOOVE_ERROR = FALSE;
			break;
			//}

			//$MOOVE_ERROR = FALSE;
			do_log_error("It's not there!");
		}

		//if ( $MOOVE_ERROR ) {
		//	do_log_error("Failed to move the file! All remaining move operations will be skipped.");
		//}
	}

	// ran out of assignments. Everything is OK
	return array('ret' => 0);
}

?>