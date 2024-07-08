<?php

function delete_from_storage($db) {
	// -- Searching for jobs to delete files from storage

	//do_log_info("Searching for jobs to delete files from storage.");
	
	$result = $db->query("SELECT
				storages.proto_scheme,
				storages.hostname,
				storages.port,
				storages.id AS st_id,
				storage_files.movie_id,
				storage_files.id,
				storage_files.folder,
				storage_files.filename,
				storage_files.id AS file_id
			FROM
				storages, storage_files
			WHERE
				storages.id = storage_files.storage_id
				AND storages.write_enable = 1
				AND storage_files.deleted = 'in_queue'
			LIMIT 10;
	");

	if ( !$result ) {
		//do_log_error('delete_from_storage: Bad SQL query 1. Exiting.');
		return array('ret' => 1, 'error_text' => 'Bad SQL query 1. Exiting.');
	}

	$http = NULL;

	while ( $row = $result->fetch_object() ) {
		if ( $GLOBALS['abort_new_job'] == TRUE ) norm_exit();

		$file_id = $row->id;
		$movie_id = $row->movie_id;
		$st_host = "{$row->proto_scheme}://{$row->hostname}:{$row->port}";
		$st_id = $row->st_id;
		$file_uri = "/upload/s{$row->st_id}/{$row->folder}/{$row->filename}";

		do_log_info("I will delete the storage_files.ID file: '{$file_id}', WebDAV URL: '{$st_host}{$file_uri}'.");

		// WebDAV HTTP init
		if ( !$http ) {
			$http = new http_simple();
			$http->set_log_callback('do_log');
		}


		// -- StorageTest
		$http->set_url($st_host."/upload/s{$st_id}/.storage_read_test_file");
		if ( false !== $session = $http->req_head() ) {
			do_log_info("> StorageTest: HTTP DAV server response code: {$session->resp_http_code}");
			if ( $session->resp_http_code == 200 ) {
				do_log_info("> StorageTest: file '.storage_read_test_file' exists and is accessible. OK");
			} elseif ( $session->resp_http_code == 404 ) {
				return array('ret' => 1, 'error_text' => "> StorageTest: test file '.storage_read_test_file' is not present on the server. BAD");
			} else {
				return array('ret' => 1, 'error_text' => "> StorageTest: unknown server response code!");
			}
		} else {
			return array('ret' => 1, 'error_text' => "> StorageTest: connection to HTTP DAV server to perform HEAD failed!");
		}


		// -- DeleteFile
		$http->set_url($st_host.$file_uri);
		if ( false !== $session = $http->req_delete() ) {
			do_log_info("> DeleteFile: HTTP DAV server response code: {$session->resp_http_code}");
			if ( in_array($session->resp_http_code, [202,204,200]) ) {
				do_log_info("> DeleteFile: the file has been successfully deleted or it did not exist.");
				$db->query("UPDATE storage_files SET deleted='yes', delete_error='' WHERE id={$file_id} LIMIT 1;");
			} else {
				$delete_error = "> DeleteFile: file delete error. HTTP DAV server response code: '{$session->resp_http_code}'.";
				$db->query("UPDATE storage_files SET deleted='error', delete_error='delete_from_storage: ".$db->escape_string($delete_error)."' WHERE id={$file_id} LIMIT 1;");
				return array('ret' => 1, 'error_text' => $delete_error);
			}
		} else {
			return array('ret' => 1, 'error_text' => "> DeleteFile: connection to HTTP DAV server to perform DELETE failed!");
		}

		// -- recalculate storage space
		$db->query("UPDATE storages
				SET storages.size_used_bytes=(SELECT SUM(size_bytes) FROM storage_files WHERE storage_files.storage_id={$st_id} AND storage_files.deleted IN ('no','in_queue','error') )
				WHERE storages.id = {$st_id}
				LIMIT 1;
		");


	}

	$result->free();
	if ( !$http ) {
		do_log_info("> There are no jobs to delete files from storage.",true,false);
	}
	return array('ret' => 0);
}

?>