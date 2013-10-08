<?php

/** Options from Wordpress (stored in database) **/

// You should normally not change any of these values. Use the Wordpress backend instead.

$rewritebase = repress_make_rewrite_base();
$remoteservers = json_decode( get_option('repress_remoteservers'), TRUE );
$agentstring = "RePress plugin/" . plugin_get_version() . " (PHP " . phpversion() . " streamer) - please read https://all4xs.net";
if (get_option('repress_push_post') == 'yes') {
	$doPosts = true;
} else {
	$doPosts = false;
}
if (get_option('repress_push_cookie') == 'yes') {
	$doCookies = true;
} else {
	$doCookies = false;
}
if (get_option('repress_secret') !== '') {
	$secret = get_option('repress_secret');	
} else {
	$secret = 'repress';		// make something up if you want to support hashed cookies
}
if (get_option('repress_use_hashes') == 'yes') {
	$useHashes = true;
} else {
	$useHashes = false;
}
$acceptCookies = array ( '' );

/** Debugging opions **/

$GLOBALS['proxydebug'] = false;		// debugging? will dump all output to flat file.
$GLOBALS['proxydebugfile'] = '/tmp/repress.module.debug.log';
error_reporting(0);

/** Detect WordPress permalink structure **/
if ( get_option('permalink_structure') == '' ) {

	// There is no permalink structure. We will do nothing.

} else {

/*** Proxy code begins here. ***/

// Include dependencies

// proxyhelp requirement
require_once('proxyhelp/proxify_html_resource.php');
// domain functions
require_once('domains.php');

/** Attempt to destroy cookies above RePress base **/

require_once("emptycookieredirect.php");

/** Process request **/

if (!isset($_SERVER['REQUEST_URI']) || $_SERVER['REQUEST_URI'] == '') {
	logline("proxy, called without request. exiting");
	exit;
}

logline("proxy, intercepted request: ", $_SERVER['REQUEST_URI']);

$hit = false; $extra = ''; $strippedRepress = ''; $remoteserver = ''; $abbreviation = ''; $text = false;

$len = strlen($rewritebase);
if (strncmp($_SERVER['REQUEST_URI'], $rewritebase, $len) == 0) {

	logline("proxy, our rewritebase / in url. Seems promising at first sight.");

	// strip repress/ part

	if (preg_match("/#\//", $rewritebase)) { continue; }
	$strippedRepress = preg_replace("#^" . $rewritebase . "[\/]?#", "", $_SERVER['REQUEST_URI']);

	// strip everything after first slash

	$stripped = preg_replace("/\/.*$/", "", $strippedRepress);

	// domain name should be left over, this could be a hash (obfuscated domain name)

	if ($useHashes) {
		logline("proxy, trying to decode the domain name from its hashed value");
		$stripped = rawurldecode($stripped);		// now we have Base64 encoded hash
		logline("proxy, rawurldecode() returns ", $stripped);
		$stripped = repress_get_host_from_hash($stripped, $secret);
		$stripped = rtrim($stripped);
		logline("proxy, decode output = ", $stripped);
	}

	/** Possible hit. Is the domain one we proxy? **/

	if (repress_recognize_host($stripped)) {

		$hit = true; $remoteserver = $stripped;

	}

	if ($hit == false) {
		header('HTTP/1.0 404 Not Found');

		exit;		// close, but no cigar.
	}
}

if ($hit) {

	/// Store the 'abbreviation' of this host, ie. either the hostname or its hash

	if ($useHashes) {
		$abbreviation = repress_obfuscate($remoteserver, $secret);
	} else {
		$abbreviation = $remoteserver;
	}

	logline("proxy, looking for something extra, a path, or query.");

	// now strippedRepress looks something like: wikileaks.org[/][....]

	// if no slashes, no extra
	if (strpos($strippedRepress,'/') == FALSE) {
		$extra = '';
	} else {
		// extra is everything after the first slash
		$extra = preg_replace("/^.*?\//", "", $strippedRepress);

		// and may be obfuscated ..
		// but to make it even more complex. part of this url string can contain GET parameters that were added by the browser
		// as part of a form field for instance, and these will _not_ be obfuscated because we do not rewrite form field IDs in pages

		if ($useHashes && $extra !== '') {

			logline("proxy, we got an extra and we are going to de-obfuscate it, extra = ", $extra);

			logline("proxy, first looking for GET parameters in this string");
			if (preg_match("/^(.*)\?(.*)$/", $extra, $matches)) {
				logline("proxy, it does have 'em, rip apart");
				$extra = rawurldecode($matches[1]);
				logline("proxy, raw decode: ", $extra);
				$extra = repress_get_host_from_hash($extra, $secret);
				logline("proxy, hash decode: ", $extra);
				$extra = rtrim($extra);
				$extra .= '?' . $matches[2];

			} else {
				logline("proxy, nope. Totally obfuscated.");
				$extra = rawurldecode($extra);
				$extra = repress_get_host_from_hash($extra, $secret);
				$extra = rtrim($extra);
			}
			logline("proxy, now it has become ", $extra);
		}
	}

	// try to protect against XSS attacks on Wordpress sessions if running as plugin
	if (function_exists('repress_logout_wordpress_user')) {
		repress_logout_wordpress_user();
	}

	logline("proxy, interpreted a hit as follows:");
	logline("proxy, remoteserver = ", $remoteserver);
	logline("proxy, abbreviation = ", $abbreviation);
	logline("proxy, extra = ", $extra);

	// do we do a POST?

	if ($doPosts && is_array($_POST) && count($_POST) > 0) {
		$requestType = 'POST';
	} else {
		$requestType = 'GET';
	}

	// do we do cookies?

	$cookieHeaders = '';

	if ($doCookies && is_array($_COOKIE) && count($_COOKIE) > 0) {
		foreach ($_COOKIE as $name => $val) {
			$encodeName = rawurlencode($name);
			$encodeVal = rawurlencode($val);
			$cookieHeaders .= "Cookie: $encodeName=$encodeVal\r\n";
		}
	}

	/* Establish streaming connection to the remote server */

	if ($requestType == 'GET') {
		$streamOptions = array(
					  'http'=>array(
        	           			  'method'=>"GET",
						  'host'=>$remoteserver,
						  'user_agent'=>$agentstring,
						  'header'=>$cookieHeaders
			         	   )
				      );
	} else {
		$postData = http_build_query($_POST);

		$streamOptions = array(
					  'http'=>array(
       	        	    			  'method'=>"POST",
						  'host'=>$remoteserver,
						  'user_agent'=>$agentstring,
						  'header'=>"Content-type: application/x-www-form-urlencoded\r\n" .
						            "Content-Length: " . strlen($postData) . "\r\n" .
							    $cookieHeaders,
						  'content'=>$postData
			         	   )
				      );
	}

	$context = stream_context_create($streamOptions);

	logline("proxy, calling build_query function");

	$urlOpen = repress_build_query_url("http://$remoteserver/$extra");

	logline("proxy, open to location ", "'http://$remoteserver/$extra' as '$urlOpen'");

	$handle = fopen($urlOpen, "rb", false, $context);
	if ($handle == FALSE) {
		if (isset($GLOBALS['proxydebug']) && $GLOBALS['proxydebug']) {
			echo("Proxy error to: http://$remoteserver/$extra");
		}
		exit;
	}

	/* Start working on headers */

	$one_byte = fread($handle, 1); // this is used to stop the "bug" reported here https://bugs.php.net/bug.php?id=46896 where the responce headers are missing
	$meta = stream_get_meta_data($handle);
	$headerData = $meta['wrapper_data'];

	if(is_array($headerData) && is_array(@$headerData['headers'])) {
		$headerData = $headerData['headers'];	// this is added to get to the actual response headers into $headerData when the curl wrapper is used
	}

	if (preg_match("/HTTP.... 30./", $headerData[0])) $redirect = true;
	else {
		$redirect = false;
	}

	$i = 0;
	foreach ($headerData as $line) {
		$i++;

		if ($i > 1 && preg_match("/^HTTP\/1../", $line)) {
			// no multiple HTTP headers will be replied
			exit;
		}

		logline("proxy, got header: ", $line);

		// make 302 redirects work
		if ($redirect && !preg_match("#$rewritebase#", $line)) {

			// a redirect to root is a redirect to main page here
			$line = preg_replace("#^Location: /$#i", "Location: $rewritebase/$abbreviation/", $line);

			// a relative redirect
			if (preg_match("#^Location: /(.+)#i", $line, $matches)) {

				if ($useHashes) {

					// will have to be rewritten to obfuscated path if hashes are used

					$relativePath = repress_obfuscate($matches[1], $secret);
					rtrim($relativePath);
					$line = "Location: $rewritebase/$abbreviation/$relativePath";

				} else {

					$line = preg_replace("#^Location: /(.+)#i", "Location: $rewritebase/$abbreviation/$1", $line);
					
				}

			}

			// we localize redirects to all known hosts, and subdomains of those hosts
			foreach ($remoteservers as $knownhost) {
				if ($useHashes) {
					$knownhostAbbr = repress_obfuscate($knownhost, $secret);
				} else {
					$knownhostAbbr = $knownhost;
				}

				// todo: Use something similar to repress_recognize_host() mechanism here!

				// an absolute redirect to a main page
				$line = preg_replace("#^Location: http://$knownhost$#", "Location: $rewritebase/$knownhostAbbr", $line);
				$line = preg_replace("#^Location: http://$knownhost/$#", "Location: $rewritebase/$knownhostAbbr", $line);
				// an absolute redirect to a lower page, which may be an obfuscated path
				if ($useHashes) {
					if (preg_match("#^Location: http://$knownhost/(.*)$#", $line, $matches)) {
						$obfsPath = repress_obfuscate($matches[1], $secret);
						$obfsPath = rtrim($obfsPath);
						$line = preg_replace("#^Location: http://$knownhost/(.*)$#", "Location: $rewritebase/$knownhostAbbr/$obfsPath", $line);
					}
				} else {
					$line = preg_replace("#^Location: http://$knownhost/(.*)$#", "Location: $rewritebase/$knownhostAbbr/$1", $line);
				}

				// Support subdomains

				if ($useHashes) {

					// an absolute redirect to a main page
					if (preg_match("#^Location: http://([a-zA-Z0-9\.\-]*?)\.$knownhost$#", $line, $matches)) {
						$abbrSub = repress_obfuscate($matches[1] . '.' . $knownhost, $secret);
						$abbrSub = rtrim($abbrSub);
						$line = preg_replace("#^Location: http://([a-zA-Z0-9\.\-]*?)\.$knownhost#", "Location: $rewritebase/$abbrSub", $line);
					}
					if (preg_match("#^Location: http://([a-zA-Z0-9\.\-]*?)\.$knownhost/$#", $line, $matches)) {
						$abbrSub = repress_obfuscate($matches[1] . '.' . $knownhost, $secret);
						$abbrSub = rtrim($abbrSub);
						$line = preg_replace("#^Location: http://([a-zA-Z0-9\.\-]*?)\.$knownhost/#", "Location: $rewritebase/$abbrSub", $line);
					}
					// an absolute redirect to a lower page, an obfuscated path
					if (preg_match("#^Location: http://([a-zA-Z0-9\.\-]*?)\.$knownhost/(.*)$#", $line, $matches)) {
						$abbrSub = repress_obfuscate($matches[1] . '.' . $knownhost, $secret);
						$abbrSub = rtrim($abbrSub);
						$obfsPath = repress_obfuscate($matches[2], $secret);
						$obfsPath = rtrim($obfsPath);
						$line = preg_replace("#^Location: http://([a-zA-Z0-9\.\-]*?)\.$knownhost/(.*)$#", "Location: $rewritebase/$abbrSub/$obfsPath", $line);
					}

				} else {
					// an absolute redirect to a main page
					$line = preg_replace("#^Location: http://([a-zA-Z0-9\.\-]*?)\.$knownhost$#", "Location: $rewritebase/$1.$knownhostAbbr", $line);
					$line = preg_replace("#^Location: http://([a-zA-Z0-9\.\-]*?)\.$knownhost/$#", "Location: $rewritebase/$1.$knownhostAbbr", $line);
					// an absolute redirect to a lower page, not an obfuscated path
					$line = preg_replace("#^Location: http://([a-zA-Z0-9\.\-]*?)\.$knownhost/(.*)$#", "Location: $rewritebase/$1.$knownhostAbbr/$2", $line);
				}
			}
		} 

		// cookie handling
		if (preg_match("/^Set-Cookie: /i", $line)) {

			if ($doCookies == false) {

				continue;

			}

			logline("proxy, found cookie header ", $line);

			// rewrite cookie paths
			
			if (preg_match("#; path=([^;]*)#", $line, $matches)) {

				$pathNow = $matches[1];

				logline("proxy, cookie path found ", $pathNow);

				if (preg_match("/^/", $pathNow)) {
	
					$pathNew = "$rewritebase/$abbreviation" . $pathNow;
					$line = preg_replace("#; path=([^;]*)#", "; path=$pathNew", $line);

					logline("proxy, insecure path. setting to ", $pathNew);
				}

			}


			// set all cookies to be httponly

			if (!preg_match("/HttpOnly/i", $line)) {
				logline("proxy, setting this cookie to httponly");

				$line .= "; httpOnly";
			}

		}

		// get content type
		if (preg_match("/^Content-Type: text\/.*$/i", $line)) {
			$text = true;
		}

		// ignore robots header. we have our own

		if (preg_match("/^X-Robots-Tag: /i", $line)) {
			continue;
		}

		// pass through headers
	
		logline("proxy, give header: ", $line);
		header($line);

	}

	// After a 30x relocation response, exit immediatly
	if ($redirect) {
		logline("proxy, exit because relocate");
		exit;
	}

	// add no robots header
	header("X-Robots-Tag: noindex, nofollow", true);

	if ($text) {

		/* Read text page and close handle */
		$contents = $one_byte . stream_get_contents($handle); // add the one byte read already to $contents
		fclose($handle);

	} else {
	
		/* Pass through binary content or non-parsed content */

		logline("proxy, starting passthru() of binary data from ", "http://$remoteserver/$extra");
		echo $one_byte; // add the one byte read already to the output stream
		$size = fpassthru($handle);
		logline("proxy, binary passthru() finished. closing handle");
		fclose($handle);
		logline("proxy, total binary tranfer size = ", $size);
		if ($size > 0) {
			logline("proxy, registering bandwidth usage of binary content to");
			register_bandwidth_usage($size);
		}
		exit;

	}

	// Proxy rewrite resource

	proxyhelp_init_for_repress($rewritebase, $abbreviation, $remoteservers, $useHashes, $secret);

	$contents = proxify_html_resource($contents);

	/* Push text. */

	$size = strlen($contents);
	if ($size > 0) {
		logline("proxy, registering bandwidth usage of text content");
		register_bandwidth_usage($size);
	}
	echo $contents;

	/* Done. */

	exit;

}

}

// This function takes a plain hostname and determines if RePress is authorized to proxy it, and obliged to rewrite it
// returns either TRUE or FALSE
function repress_recognize_host($stripped) {

	logline("repress_recognize_host, host = ", $stripped);

	$remoteservers = json_decode( get_option('repress_remoteservers'), TRUE );

	/** First check if this hostname is legal **/

	// split the domain into subdomains
	$subdomains = explode('.', $stripped);

	foreach ($subdomains as $subdom) {
		if (!is_legal_domain_part($subdom)) {
			logline("repress_recognize_host, illegal domain part ", $subdom); 
			return FALSE;		// will not work with this object
		}
	}

	/** Now search to find if this hostname is one of the (sub)domains we proxy **/

	foreach ($remoteservers as $hostname) {

		logline("repress_recognize_host, match checking on ", $hostname);

		// split the proxied domain into subdomains

		$proxiedSubdomains = explode('.', $hostname);
		$numParts = count($proxiedSubdomains);

		if (!($numParts > count($subdomains))) {

			$okMatch = true;

			for ($i = $numParts - 1, $b = count($subdomains) - 1; $i >= 0; $i--,$b--) {
				logline("repress_recognize_host, proxied domain subpart is ", $proxiedSubdomains[$i]);
				if ($proxiedSubdomains[$i] !== $subdomains[$b]) {
					logline("repress_recognize_host, proxied domain subpart does not match ", $subdomains[$b]);
					$okMatch = false;
					break;
				} else {
					logline("repress_recognize_host, proxied domain subpart matches ", $subdomains[$b]);
				}
			}

			if ($okMatch) {
				return TRUE;
			}

		}

	}

	return FALSE;		// no match was found

}


// Build the url string with GET query if neccessary, todo force HTTPS if neccesary */
function repress_build_query_url($url) {

	logline("repress_build_query_url, url = ", $url);

        $parsed = parse_url($url);

        if (isset($parsed['query'])) {

                return $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'] . '?' . repress_rawqueryencode($parsed['query']);

        } elseif (isset($parsed['path'])) {

                return $parsed['scheme'] . '://' . $parsed['host'] . $parsed['path'];

        } else {

                return $parsed['scheme'] . '://' . $parsed['host'];

        }

}

function repress_rawqueryencode($urlPart) {

	logline("repress_rawqueryencode, urlpart = ", $urlPart);

	$queryString = '';

	$assignments = explode('&', $urlPart);

	$first = true;

	foreach ($assignments as $assignment) {

		if ($first) {
			$first = false;
		} else {
			$queryString .= '&';
		}

		// raw url encode of value

		if (!preg_match("/\=/", $assignment)) {
			logline("repress_rawqueryencode, this does not look like an assignment: ",  $assignment);
			logline("repress_rawqueryencode, adding to query string as literal");
			$queryString .= $assignment;
		} else {
			$dec = explode("=", $assignment);
			$queryString .= $dec[0] .= '=';
			if (isset($dec[1])) {
				$queryString .= rawurlencode($dec[1]);
			}
		}

	}

	return $queryString;
}

function register_bandwidth_usage($size) {

	logline("register_bandwidth_usage, size = ", $size);

	if ( function_exists('repress_register_bandwidth_usage') ) {
		repress_register_bandwidth_usage($size);
	}

}

/** Log and debug functions **/

// dump a log line to debug file
function logline($line, $data = '') {
	if (!$GLOBALS['proxydebug'] || !$GLOBALS['proxydebugfile']) { return; }

	if (!is_link($GLOBALS['proxydebugfile'])) {

		// prepare printing of data

		$printData = $data;

		if (is_nonprintable_string($data)) {
			$printData = '[nonprintable. hex dump] ' . hexdump_with_spaces( bin2hex($data) );
		}

		$printLine = $line . $printData;

		if (strlen($printLine > 2048)) {
			// truncate
			$printLine = substr($printLine, 0, 2048);
		}

		// write log line to file

		$now = date("Ymd H:i:s");
		$f = fopen($GLOBALS['proxydebugfile'], "a");

		if (flock($f, LOCK_EX)) {
			fputs($f, '[' . $now . '] ' . $printLine . "\n");
			fflush($f);
			flock($f, LOCK_UN); // release the lock
		}

		fclose($f);
	}
}

// this function determines if string is easy printable asci, like hostnames, otherwise we hex dump the data string
// utf8 not supported
function is_nonprintable_string($str) {

	for ($i = 0; $i < strlen($str); $i++) {
		$ascii = ord($str[$i]);
		if ($ascii < 32 || $ascii > 126) {
			return TRUE;
		}
	}

	return FALSE;

}

// format hex output with spaces
function hexdump_with_spaces($hexstr) {
	$r = '';
	for ($i = 0; $i < strlen($hexstr); $i++) {
		if ($i > 0 && $i % 2 == 0) {
			$r .= ' ';
		}
		$r .= $hexstr[$i];
	}
	return $r;
}
