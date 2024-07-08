<?php


//function do_log($level, $message) {
//	echo "LOGGER: [".strtoupper($level)."] {$message}\n";
//	// write message to file .......
//}

class http_simple {
	protected $log_func = '';
	protected $tcp_connect_timeout = 3; // sec. Timeout for establishing a TCP connection
	protected $user_agent = 'php_http_simple/0.1a';

	//protected $req_headers = array();
	protected $sock_scheme = '';
	protected $hostname = '';
	protected $port = 0;
	protected $uri = '';

	protected $debug_level = 0;

	protected $resp_header_limit_read_bytes = 4096; // 4 KiB //+tested OK
	protected $resp_body_limit_read2mem_bytes = 10000; // 10 Kbytes //+tested OK
	protected $resp_body_limit_read2file_bytes = 5000000; // 5 Mbytes //+tested OK


	function __construct($url='', $log_func='') {
		if ( $url != '' ) {
			$this->set_url($url);
		}

		if ( $log_func != '' ) {
			$this->set_log_callback($log_func);
		}
	}

	function set_resp_header_limit_read_bytes($bytes) {
		$bytes = ceil($bytes);
		if ( $bytes < 100 ) {
			$this->dolog('error', "set_resp_header_limit_read_bytes() min value is 100 bytes!");
			return false;
		}

		$this->resp_header_limit_read_bytes = $bytes;
		return true;
	}

	function set_resp_body_limit_read2mem_bytes($bytes) {
		$bytes = ceil($bytes);
		if ( $bytes < 100 ) {
			$this->dolog('error', "resp_body_limit_read2mem_bytes() min value is 100 bytes!");
			return false;
		}

		$this->resp_body_limit_read2mem_bytes = $bytes;
		return true;
	}

	function set_resp_body_limit_read2file_bytes($bytes) {
		$bytes = ceil($bytes);
		if ( $bytes < 1000000 ) {
			$this->dolog('error', "set_resp_body_limit_read2file_bytes() min value is 1000000 bytes!");
			return false;
		}

		$this->resp_body_limit_read2file_bytes = $bytes;
		return true;
	}

	function set_log_callback($log_func) {
		if ( !is_callable($log_func) ) {
			echo "ERROR: HTTP_SIMPLE->set_log_callback(): Callback logging function '{$log_func}' isn't callable!\n";
			exit;
		}
		$this->log_func = $log_func;
		return true;
	}

	private function dolog($level, $message) {
		$message = "HTTP_SIMPLE ".$message;
		if ( $this->log_func == '' ) {
			echo strtoupper($level).": {$message}\n";
		} else {
			//call_user_func($this->log_func, $level, $message);
			call_user_func($this->log_func, $message, $level);
		}
	}


	function set_url($url) {
		$this->sock_scheme = '';
		$this->hostname = '';
		$this->port = 0;
		$this->uri = '';

		$u = parse_url($url);

		if ( !isset($u['scheme']) ) {
			$this->dolog('error', "Bad URL scheme. URL is '{$url}'");
			return false;
		}

		$u['scheme'] = strtolower($u['scheme']);

		if ( !in_array($u['scheme'], ['http','https']) ) {
			$this->dolog('error', "Bad URL scheme. Only HTTP supported! URL is '{$url}'");
			return false;
		}


		if ( !isset($u['host']) ) {
			$this->dolog('error', "Bad URL host. URL is '{$url}'");
			return false;
		}
		$this->hostname = $u['host'];

		if ( isset($u['port']) ) {
			$this->port = $u['port'];

		// if not isset $u['port']:
		} else if ( $u['scheme'] == 'http' ) {
			$this->port = 80;
		} else if ( $u['scheme'] == 'https' ) {
			$this->port = 443;
		} else {
			$this->dolog('error', "Bad URL port detection. URL is '{$url}'");
			return false;
		}
		
		if ( $u['scheme'] == 'https' ) { $this->sock_scheme = 'ssl://'; }
		$this->uri = isset( $u['path'] ) ? $u['path'] : '/';
		if ( isset($u['query']) ) { $this->uri .= '?'.$u['query']; }

		//var_dump($u);

		if ($this->debug_level) $this->dolog('debug', "parse URL > sock_scheme: {$this->sock_scheme}, host: {$this->hostname}, port: {$this->port}, uri: {$this->uri}");

		//if ( is_array($headers) ) {
		//	echo "is array\n";
		//} else {
		//	echo "not an array\n";
		//}
		return true;
	}

	private function resclose(&$res) {
		if ( isset($res) && is_resource($res) ) fclose($res);
	}

	//private function escape_fname($fname) {
	//	return mb_ereg_replace("[^\w\-\.\_]", "\\\\0", $fname);
	//}

	function get_headers_option($headers, $opt_name) {
		if ( !strlen($opt_name) ) return false;
		if ( !is_array($headers) ) $headers = (array) @explode("\r\n", $headers);

		foreach ($headers as $row) {
			if ( 0 === strpos($row, $opt_name.': ') ) return substr($row, strlen($opt_name)+2);
		}
		return false;
	}


	private function http_transfer($req_headers=array(), $send_file='', $send_membuf='', $recv_to_file='') {
		$sock = @fsockopen($this->sock_scheme.$this->hostname, $this->port, $errno, $errstr, $this->tcp_connect_timeout);
		if ($sock === false) {
			//throw new Exception($errstr, $errno);
			if ($this->debug_level) $this->dolog('error', "TCP socket connection to {$this->hostname}:{$this->port} failed! $errstr ($errno)");
			return false;
		}
		if ($this->debug_level) $this->dolog('debug', "TCP socket connection to {$this->hostname}:{$this->port} successfuly!");
		stream_set_blocking($sock, true);

		$data2send_bytes = 0; // 
		$sended_body_bytes = 0; // ??????????

		// ---- HTTP request below

		if ( strlen($send_file) > 0 ) {
			if ($this->debug_level) $this->dolog('debug', "Entering send FILE mode via HTTP request body.");

			if ( file_exists($send_file) == 0 ) {
				$this->dolog('error', "Local file '{$send_file}' doesn't exists. Uploading via HTTP PUT failed!");
				$this->resclose($sock);
				return false;
			}

			if ( is_readable($send_file) == 0 ) {
				$this->dolog('error', "Local file '{$send_file}' not accesible for read. Uploading via HTTP PUT failed!");
				$this->resclose($sock);
				return false;
			}

			//if ( ($send_data_bytes = filesize($send_file)) > 0 ) {
				$data2send_bytes = filesize($send_file); // no check zero size ???????????
				$req_headers[] .= "Content-Length: {$data2send_bytes}";
				fwrite($sock, implode("\r\n",$req_headers)."\r\n\r\n"); // send request headers with ADD
				if ($this->debug_level>1) $this->dolog('debug', "REQ Headers is:\n".implode("\n",$req_headers)."\n");

				if ($this->debug_level) $this->dolog('debug', "Opening '{$send_file}' (size {$data2send_bytes} bytes) for read and sending file via HTTP request body.");

				sleep(0.2);

				$fh = fopen($send_file, 'rb');
				if ( !$fh ) {
					$this->dolog('error', "Can't open file '{$recv_to_file}' for read. Downloading HTTP body aborted!");
					$this->resclose($sock);
					return false;
				}
				
				$sended_body_bytes = 0;
				$t = 0; $zeros=0; $falses=0;
				while ( $chunk = fread($fh, 1000000) ) {
					do {
						$t = @fwrite($sock, $chunk);
						if ($t === 0) { // some PHP bugs
							$zeros += 1;
							usleep(200000); // 0.2sec
							if ($zeros >= 10) { // prevent PHP bug - infinite loop
								$this->dolog('error', 'Count Zeros returns limit on fwrite() reached. Writting to TCP SOCKET failed. Uploading body crashed. Remote server closed connection or PHP bug!?');
								$this->resclose($fh);
								//$this->resclose($sock);
								//return false;
								break 2;
							}
						} elseif ($t === false) {
							$falses += 1;
							usleep(200000); // 0.2sec
							if ($falses >= 10) { // prevent PHP bug - infinite loop
								$this->dolog('error', 'Count Falses returns limit on fwrite() reached. Writting to TCP SOCKET failed. Uploading body crashed. Remote server closed connection or PHP bug!?');
								$this->resclose($fh);
								//$this->resclose($sock);
								//return false;
								break 2;
							}
						} else $sended_body_bytes += $t;
					} while ( $t == 0 ); // NOT strict! $t may returns '0' or 'false' on fails
				}
				//fclose($fh); // closing source file
				$this->resclose($fh); // closing source file
				$this->dolog('debug', "fwrite() returns: zeros $zeros; falses $falses; writted $sended_body_bytes bytes to socket;"); // ??????????????????
				unset($t, $zeros, $falses);
			//} else {
			//	$this->dolog('warn', "Source data file '{$send_file}' empty (0 bytes). Skipping it!");
			//	fwrite($sock, implode("\r\n",$req_headers)."\r\n\r\n"); // send request headers with ADD
			//}

		} else {
			$data2send_bytes = strlen($send_membuf);
			$sended_body_bytes = 0;

			//if ( $data2send_bytes > 0 ) {
			if ( $send_membuf !== false ) {
				if ($this->debug_level) $this->dolog('debug', "Entering send MEMBUF mode via HTTP request body.");
				$req_headers[] .= "Content-Length: {$data2send_bytes}";

				fwrite($sock, implode("\r\n",$req_headers)."\r\n\r\n"); // send request headers
				if ($this->debug_level>1) $this->dolog('debug', "REQ Headers is:\n".implode("\n",$req_headers)."\n");
				$sended_body_bytes += ceil( @fwrite($sock, $send_membuf) ); // no write errors analyzing ??

			} else { // Send only HTTP header (without body data)
				if ($this->debug_level) $this->dolog('debug', "Entering send ONLY HEADERS mode via HTTP. NO request body!");

				if ( substr($req_headers[0],0,3) === "PUT" ) {
					$this->dolog('error', "No HTTP body data for PUT method! Send MEMBUF contains FALSE. Uploading aborted!");
					$this->resclose($sock);
					return false;
				}
				fwrite($sock, implode("\r\n",$req_headers)."\r\n\r\n"); // send request headers
				if ($this->debug_level>1) $this->dolog('debug', "REQ Headers is:\n".implode("\n",$req_headers)."\n");
			}
		}

		// ---- HTTP response below


		$resp_header_buf = '';
		$resp_header_data = '';
		$resp_header_bytes = 0;

		while ( !feof($sock) ) {
			if ( $resp_header_bytes >= $this->resp_header_limit_read_bytes ) {
				$this->dolog('error', "HTTP reply headers read buffer limit ({$this->resp_header_limit_read_bytes} bytes) exceeded! Reading socket data aborted!");
				$this->resclose($sock);
				return false;
			}

			$resp_header_buf .= fread($sock, 1); // add by one byte
			$resp_header_bytes++;

			if ( $resp_header_bytes > 3 && substr($resp_header_buf, -4, 4) == "\r\n\r\n" ) {
				$resp_header_data = substr($resp_header_buf, 0, -4); // Clear from garbage response raw headers
				$resp_header_bytes -= 4;
				if ($this->debug_level) $this->dolog('debug', "Response HTTP Headers ending found after $resp_header_bytes byte!");
				break; // reading header done. 'while' exiting
			}
		}

		//$resp_header_bytes = strlen($resp_header_data);
		unset($resp_header_buf);

		if ($this->debug_level) $this->dolog('debug', "RESP Raw Header bytes is: $resp_header_bytes");
		if ($this->debug_level>1) $this->dolog('debug', "RESP Raw Header data is:\n$resp_header_data\n"); // само тело ответа!!!!!!
		//file_put_contents('recv_header_data.dump',$header_data);

		// Check recved HTTP headers
		if ( $resp_header_bytes == 0 ) {
			$this->dolog('error', "Bad server response headers body. Headers body length is 0 byte!");
			$this->resclose($sock);
			return false;
		}

		if (1 !== preg_match("/^HTTP\/[0-9]\.[0-9] ([0-9]{3}) ([^\r\n]*)/", $resp_header_data, $matches)) {
			// Invalid HTTP reply
			$this->dolog('error', "Bad server HTTP response headers format!");
			$this->resclose($sock);
			//if ($sock) echo "BAD!\n";
			return false;
		}

		$resp_http_code = (int) $matches[1];
		unset($matches);

		$resp_headers = explode("\r\n", $resp_header_data);
		// Headers OK above


		// receiving body data
		$resp_body_data = '';
		$resp_body_bytes = 0;

		//if ( $resp_http_code == 200 ) { // Downloading body allowed
			if ( strlen($recv_to_file) > 0 ) { // download body to file
				// file testing ?????????????????????????

				// recv limit ?????????????????????
				$fh = fopen($recv_to_file, 'wb');
				if ( !$fh ) {
					$this->dolog('error', "Can't open file '{$recv_to_file}' for write. Downloading HTTP body aborted!");
					$this->resclose($sock);
					return false;
				}

				while ( !feof($sock) ) {
					if ( $resp_body_bytes >= $this->resp_body_limit_read2file_bytes ) {
						$this->dolog('warn', "HTTP body read to file (resp_body_limit_read2file_bytes={$this->resp_body_limit_read2file_bytes} bytes) limit exceeded! Reading HTTP reply body aborted!");
						break;
					}
					$resp_body_bytes += fwrite($fh, fread($sock, 1000000));
					//echo "\$resp_body_bytes $resp_body_bytes\n";
				}
				fclose($fh);
				//$resp_body_bytes = filesize($recv_to_file);

			} else { // download body to memory
				while ( !feof($sock) ) {
					if ( $resp_body_bytes >= $this->resp_body_limit_read2mem_bytes ) {
						$this->dolog('warn', "HTTP body read to memory (resp_body_limit_read2mem_bytes={$this->resp_body_limit_read2mem_bytes} bytes) limit exceeded! Reading HTTP reply body aborted!");
						break;
					}
					$resp_body_data .= fread($sock, $this->resp_body_limit_read2mem_bytes);
					$resp_body_bytes = strlen($resp_body_data);
				}
			}
		//}

		$this->resclose($sock);
		unset($sock);

		if ($this->debug_level) $this->dolog('debug', "RECV Body bytes is: $resp_body_bytes");
		if ($this->debug_level>2) $this->dolog('debug', "RECV Body data is:\n $body_data"); // it's the body of response!!!!!!
		//file_put_contents('recv_body_data.dump',$body_data);

		return (object) array( 'req_headers' => $req_headers, 'resp_headers' => $resp_headers, 'resp_header_bytes' => $resp_header_bytes, 'resp_http_code' => $resp_http_code, 'resp_body_bytes' => $resp_body_bytes, 'resp_body_data' => $resp_body_data );
	}

	function req_head() {
		$req_headers = array();
		$req_headers[] = "HEAD {$this->uri} HTTP/1.1";
		$req_headers[] = "User-Agent: {$this->user_agent}";
		$req_headers[] = "Host: {$this->hostname}";
		$req_headers[] = "Authorization: Basic ".base64_encode("kino-poller:");
		$req_headers[] = "Connection: close";

		return $this->http_transfer($req_headers, false, false, false);
	}

	function req_get() {
		$req_headers = array();
		$req_headers[] = "GET {$this->uri} HTTP/1.1";
		$req_headers[] = "User-Agent: {$this->user_agent}";
		$req_headers[] = "Host: {$this->hostname}";
		$req_headers[] = "Authorization: Basic ".base64_encode("kino-poller:");
		$req_headers[] = "Connection: close";

		return $this->http_transfer($req_headers, false, false, false);
	}

	function req_get_file($download_file='') {
		$req_headers = array();
		$req_headers[] = "GET {$this->uri} HTTP/1.1";
		$req_headers[] = "User-Agent: {$this->user_agent}";
		$req_headers[] = "Host: {$this->hostname}";
		$req_headers[] = "Authorization: Basic ".base64_encode("kino-poller:");
		$req_headers[] = "Connection: close";

		return $this->http_transfer($req_headers, false, false, $download_file);
	}

	function req_put($put_data='') {
		$req_headers = array();
		$req_headers[] = "PUT {$this->uri} HTTP/1.1";
		$req_headers[] = "User-Agent: {$this->user_agent}";
		$req_headers[] = "Host: {$this->hostname}";
		$req_headers[] = "Authorization: Basic ".base64_encode("kino-poller:");
		$req_headers[] = "Connection: close";

		return $this->http_transfer($req_headers, false, $put_data, false);
	}

	function req_put_file($put_file) {
		$req_headers = array();
		$req_headers[] = "PUT {$this->uri} HTTP/1.1";
		$req_headers[] = "User-Agent: {$this->user_agent}";
		$req_headers[] = "Host: {$this->hostname}";
		$req_headers[] = "Authorization: Basic ".base64_encode("kino-poller:");
		$req_headers[] = "Connection: close";

		return $this->http_transfer($req_headers, $put_file, false, false);
		//return $this->http_transfer($req_headers, false, false, false);
	}

	function req_delete() {
		$req_headers = array();
		$req_headers[] = "DELETE {$this->uri} HTTP/1.1";
		$req_headers[] = "User-Agent: {$this->user_agent}";
		$req_headers[] = "Host: {$this->hostname}";
		$req_headers[] = "Authorization: Basic ".base64_encode("kino-poller:");
		$req_headers[] = "Connection: close";

		return $this->http_transfer($req_headers, false, false, false);
	}

}


?>
