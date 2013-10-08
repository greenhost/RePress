<?php

/*
 * This code will force to empty all site cookies ABOVE the proxy base of RePress.
 * At least on first landing.
 */

$ip = $_SERVER['REMOTE_ADDR'];			// can also be proxy ip maybe, but that is okay
$emptyCookieName = sha1($ip . $secret);		// obfuscate empty cookie name


/*
 * Url: /empty empties all cookies above RePress
 */

$len = strlen($rewritebase . '/empty');
if (strncmp($_SERVER['REQUEST_URI'], $rewritebase . '/empty', $len) == 0) {

	logline("emptycookieredirect, we are at empty");

	setcookie($emptyCookieName, '1', time()+3600, "/");

	// Unset all cookies available at this level
	foreach ($_COOKIE as $parm => $val) {

		if ($parm == $emptyCookieName) { continue; }

		if (in_array($parm, $acceptCookies)) {
			logline("emptycookieredirect, Not unsetting cookie ", $parm);
		} else {
			logline("emptycookieredirect, Unsetting cookie with parm ", $parm);
			setcookie($parm, '', time() - 3600);
		}

	}

	if (isset($_GET['destination'])) {

		logline("emptycookieredirect, heading to final destination ", $_GET['destination']);
		header("Location: " . $_GET['destination']);

	}

	exit;

} else {
	// try to protect against XSS attacks on main domain by deleting all cookies under RePress
	unset_cookies($rewritebase, $acceptCookies, $emptyCookieName);
}

function unset_cookies($rewritebase, $acceptCookies, $emptyCookieName) {

	logline("unset_cookies, checking to unset cookies.");

	if (!isset($_COOKIE) || count($_COOKIE) == 0) {
		logline("unset_cookies, no cookies. No unset. Proxying domain.");
		return;
	}

	if (isset($_COOKIE[$emptyCookieName])) {
		logline("unset_cookies, emptied cookie found. No new unset. Proxying domain.");
		return;
	}

	$emptyCookies = false;
	foreach ($_COOKIE as $p => $v) {
		logline("unset_cookies, checking on cookie ", $p);
		if ($p == $emptyCookieName && $v == 1) {
			logline("unset_cookies, repressempty cookie found. Continuing.");
			return;
		}

		if (in_array($p, $acceptCookies)) {
			logline("unset_cookies, Cookie will be explicitely accepted: ", $p);
		} elseif ($v == '') {
			logline("unset_cookies, Not unsetting empty cookie.");
		} else {
			logline("unset_cookies, need to redirec to unset this: ", "$p = $v");
			$emptyCookies = true; break;
		}
	}

	if ($emptyCookies) {

		logline("unset_cookies, Relocating to empty");
		header("Location: " . $rewritebase . '/empty' . '?destination=' . urlencode($_SERVER['REQUEST_URI']));

		exit;
	} 
	
	logline("unset_cookies, All cookies accepted.");

}

