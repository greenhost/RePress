<?php
/*
Plugin Name: RePress plugin
Plugin URI: https://all4xs.net
Description: RePress plugin
Version: 0.1alpha16

Future features:

    - set max sizes for transfers
    - limit bandwidth usage per month
    - support HTTPS

*/

// css file
wp_enqueue_style( 'repress_css', get_bloginfo('wpurl') . '/wp-content/plugins/repress/repress.css', 'false', '0.1', 'all' );

// add activation hooks
register_activation_hook(__FILE__, 'activate_repress');
register_deactivation_hook(__FILE__, 'deactivate_repress');

function activate_repress() {
	// activate the plugin. initialize the options with the defaults
	// and add some well known censored sites to the database

	add_option('repress_errormsg', '');
	add_option('repress_rewritebase', '/repress');
	add_option('repress_remoteservers', json_encode ( array  (
							'wikileaks.org',
							'all4xs.net',
							'piratebay.org',
							'thepiratebay.org',
							'newton.baycdn.net',
							'piratebay.cc',
							'piratebay.am',
							'piratebay.net',
							'piratebay.se',
							'themusicbay.net',
							'themusicbay.org',
							'thepiratebay.com',
							'thepiratebay.net',
							'thepiratebay.se'
						) )
		);
	add_option('repress_push_post', 'yes');
	add_option('repress_push_cookie', 'no');
	add_option('repress_use_hashes', 'no');
	add_option('repress_secret', repress_make_secret());	// use secret key to support hashes in URLs
	add_option('repress_version', plugin_get_version());

	// statistics
	add_option('repress_bandwidth_usage_bytes', 0);
	add_option('repress_bandwidth_usage_mb', 0);
}

function deactivate_repress() {
	// deactivate the plugin. remove options

        delete_option('repress_errormsg');
        delete_option('repress_rewritebase');
        delete_option('repress_remoteservers');
        delete_option('repress_push_post');
        delete_option('repress_push_cookie');
        delete_option('repress_use_hashes');
        delete_option('repress_secret');
        delete_option('repress_version');
        delete_option('repress_bandwidth_usage_bytes');
        delete_option('repress_bandwidth_usage_mb');

}

// provide a menu in the administration interface to change options
add_action('admin_menu', 'repress_modify_menu');

function repress_modify_menu() {
	add_options_page(	'Repress',
				'RePress',
				'manage_options',
				__FILE__,
				'repress_set_options'
			);
}

// called in administration backend for options screen
function repress_set_options() {

	if ((array_key_exists('repress_rewritebase_randomize', $_REQUEST) && $_REQUEST['repress_rewritebase_randomize']) ||
	    (array_key_exists('repress_rewritebase_update', $_REQUEST) && $_REQUEST['repress_rewritebase_update']) ||
            (array_key_exists('uncensor', $_REQUEST) && $_REQUEST['uncensor']) ||
	    (array_key_exists('repressdelete', $_REQUEST) && $_REQUEST['repressdelete'])) {
		repress_update_options();
	}

	if (array_key_exists('save', $_REQUEST) && $_REQUEST['save']) {
		repress_update_options_save();
	}

	repress_draw_form();
}

// html form to set plugin options
function repress_draw_form() {

	if (get_option('repress_push_post') == 'yes') {
		$checkedPushPost = 'checked';
	} else {
		$checkedPushPost = '';
	}
	if (get_option('repress_push_cookie') == 'yes') {
		$checkedPushCookie = 'checked';
	} else {
		$checkedPushCookie = '';
	}
	if (get_option('repress_use_hashes') == 'yes') {
		$checkedUseHashes = 'checked';
	} else {
		$checkedUseHashes = '';
	}

	?>
		<div class="wrap"><h2>RePress options</h2>
		<div id="rpside">
			<a href="https://all4xs.net"><img src="<?php echo repress_draw_logo_url(); ?>" style="float: right"></a>
			<div id="rpundertext">Version <?php echo plugin_get_version(); ?></div>
			Designed by <a href="https://all4xs.net">all4xs.net</a><br /><br />
			html parser by<br />
			<a href="http://code.google.com/p/phproxyimproved/">phproxyimproved</a>
			<p>
			<b>Statistics</b>
			</p>
			<p>
			<? echo repress_draw_megabytes(); ?> proxied
			</p>
		</div>
		<?php

		// seperate documentation pages

		if (isset($_REQUEST['repressinfo'])) {

			if ($_REQUEST['repressinfo'] == 'obfuscation') {
				repress_display_obfuscation_info();
				return;
			}
		}

		// main options page

		if (get_option('repress_errormsg') !== '') {
			echo '<div id="rperror">' . get_option('repress_errormsg') . '</div>';
			repress_clean_error();
		}
		if ( get_option( 'permalink_structure' ) == '' ) {
			echo '<div id="rperror">' . _('You do not have permalinks enabled. This plugin will not function without having permalinks enabled.');
			echo '</div>';
		}

		if (ini_get('allow_url_fopen') !== '1') {
			echo '<div id="rperror">' . _('You do not have allow_url_fopen set to On in your php.ini. This plugin will not function without having permalinks enabled.');
			echo '</div>';
		}

		?>
		<br />
		RePress requires a unique permalink. This is the address under which the proxy will function.<br />
		This must be a single word. Leading slash is optional.<br />
		<p>
		<form method="post">
			<label for 'repress_rewritebase'>RePress permalink:
				<input type="text" name="repress_rewritebase" value="<?php echo get_option('repress_rewritebase'); ?>" />
			</label>
			&nbsp;&nbsp;<input type="submit" name="repress_rewritebase_update" value="Update" />
			&nbsp;&nbsp;<input type="submit" name="repress_rewritebase_randomize" value="Randomize" />
		</form>
		</p>
		</div>
		<div class="wrap"><h3>Uncensored domains</h3>
		The following domains are uncensored by RePress.<br />
		<p>
		<?php echo repress_draw_domaintable(); ?>
		</p>
		Free a domain from the clutches of tiranny (please type only the hostname, not HTTP).
		<form method="post">
			<label for 'repress_uncensordomain'>Add domainname:
				<input type="text" name="repress_uncensordomain" value="" />
			</label>
			&nbsp;&nbsp;<input type="submit" name="uncensor" value="Uncensor!" />
		</form>
		</div>
		<div class="wrap"><h3>Advanced options</h3>
		<form method="post">
			<label for 'repress_push_post'>Push POST variables:
				<input type="checkbox" name="repress_push_post" <?php echo $checkedPushPost; ?> />
			</label>
			<br />
			<label for 'repress_push_cookie'>Allow cookies:
				<input type="checkbox" name="repress_push_cookie" <?php echo $checkedPushCookie; ?>/>
			</label>
			<br />
			<?php

			if (!repress_can_hash()) {
				echo '<div id="rperror">' . _('Obfuscating URLs is not possible in this Wordpress installation due lacking server side support. ') . repress_draw_obfuscate_technical_link() . '</div>';
			} else {

			?>
			<label for 'repress_use_hashes'>Obfuscate URLs:
				<input type="checkbox" name="repress_use_hashes" <?php echo $checkedUseHashes; ?>/>
			</label>
			<?php
			}
			?>
			<br /><br />
			<input type="submit" name="save" value="Save" />
		</form>
		</div>
	<?php
}

// html rendering of logout page
function repress_draw_logoutpage() {
	header('Content-Type: text/html',true);

	?>
	<html>
	<head>
	<title>Please log out of wordpress</title>
	</head>
	<body>
	<html>
	In order to protect your blog from cross-site scripting attacks, please log out of your Wordpress administration backend before accessing any of the proxied site urls.<br />
	Please log out of Wordpress by clicking on this <a href="<?php echo wp_logout_url( $_SERVER['REQUEST_URI'] ); ?>" title="Logout">logout</a> link.<br />
	<br />
	Afterwards you will be directly redirected to the proxied website.
	<br />
	For security reasons. This will also delete all cookies for this domain (your remembered settings will be lost).
	</body>
	</html>

	<?php
}

// html rendering of the domain table
function repress_draw_domaintable() {
	$table = '<div id="rpdomaintable"><table>';

	$table .= '<tr>' . '<th width="80%">Domain</th>' . '<th>Remove</th>' . '<th>Visit</th>' . '</tr>';

	$domains = json_decode( get_option('repress_remoteservers'), TRUE );
	if (is_array($domains)) {
		foreach ($domains as $host) {
			$table .= '<tr>' . "<td>$host</td>" . "<td>" . repress_draw_deletehost($host) . "</td>" . "<td>" . repress_draw_site_link($host, 'Visit') . "</td></tr>";
		}
	}

	$table .= '</table></div>';
	return $table;
}

// html rendering of link to technical explanation of obfuscation requirements
function repress_draw_obfuscate_technical_link() {
	$text1 = _('Read '); $text2 = _('this'); $text3 = _('for server requirements.');
	return $text1 . '<a href = "' . $_SERVER['REQUEST_URI'] . '&repressinfo=obfuscation">' . $text2 . '</a> ' . $text3;
}

// link to logo image
function repress_draw_logo_url() {
	return get_bloginfo('wpurl') . '/wp-content/plugins/repress/img/logo.jpeg';
}

// make link to delete host from domain table
function repress_draw_deletehost($host) {
	return '<a href = "' . $_SERVER['REQUEST_URI'] . '&repressdelete=' . urlencode($host) . '">Delete</a>';
}

// display bandwidth usage
function repress_draw_megabytes() {

	$bytes = get_option('repress_bandwidth_usage_bytes');
	$megabytes = get_option('repress_bandwidth_usage_mb');

	// version < 0.1alpha12 had a calc bug in accounting of traffic. we fix this on-the-fly here
	// todo: remove in future version(s)
	if ($bytes > 1048576) {
		$megabytes += intval($bytes / 1048576 + 0.5);
		$bytes = 0;
		update_option('repress_bandwidth_usage_bytes', $bytes);
		update_option('repress_bandwidth_usage_mb', $megabytes);
	}

	if ($megabytes > 0) {
		return $megabytes . ' mb';
	} else {
		return $bytes . ' bytes';
	}
}

// html rendering of documentation on obfuscation
function repress_display_obfuscation_info() {
?>
	<div class="wrap"><h3>Weberver and PHP requirements for obfuscation</h3>
	<p>
	To obfuscate Weblinks RePress requires the installation of the PHP <b>MCrypt module</b>, with 'Blowfish' encryption compiled in. The availability of this module is <u>autodetected</u> and we could <u>not</u> find it. This is something only your hoster can solve, if you are not the hoster yourself. Please ask for the installation of the module if you want to use URL obfuscation.
	</p>
<?php
	$_SERVER['REQUEST_URI'] = preg_replace("#&repressinfo=.*$#", "", $_SERVER['REQUEST_URI']);
	echo _("Return to RePress") . ' ' . '<a href = "' . $_SERVER['REQUEST_URI'] . '">' . _('options') . '</a>.';
?>

	</div>
<?php
}

// html rendering of overview of uncensored sites
function repress_draw_overview_page() {
	header('Content-Type: text/html',true);
?>
	<html>
	<head>
	<title>RePress web proxy provider</title>
	</head>
	<body>
	<a href="https://all4xs.net" style="float:left"><img src="<?php echo repress_draw_logo_url(); ?>"></a>
	<h1 style="float:up">Uncensor the web using <a href="https://all4xs.net">RePress.</a></h1>
	<h3 style="margin-left:250px">Stop internet censorship now!</h3><br /><br /><br />
	The following sites are accessible through this webproxy:
	<br /><br />
<?php
	$domains = json_decode( get_option('repress_remoteservers'), TRUE );
	if (is_array($domains)) {
		sort($domains, SORT_STRING);
		foreach ($domains as $host) {
			echo repress_draw_site_link($host, $host);
			echo "<br />";
		}
	}
?>
	</body>
	</html>
<?php

}

// link to an uncensored site
function repress_draw_site_link($host, $comment) {
	if ( get_option('repress_use_hashes') == 'yes' ) {
		$secret = get_option('repress_secret');
		return '<a href="' . repress_get_url_rewritebase('repress_rewritebase') . '/' . repress_obfuscate($host, $secret) . '/">' . $comment . '</a>';
	} else {
		return '<a href="' . repress_get_url_rewritebase('repress_rewritebase') . '/' . repress_obfuscate($host) . '/">' . $comment . '</a>';
	}
}

// handle updating of options
function repress_update_options() {

	if ((array_key_exists('repress_rewritebase_randomize', $_REQUEST) && $_REQUEST['repress_rewritebase_randomize']) ||
 	    (array_key_exists('repress_rewritebase_update', $_REQUEST) && $_REQUEST['repress_rewritebase_update'])) {

		if (array_key_exists('repress_rewritebase_randomize', $_REQUEST) && $_REQUEST['repress_rewritebase_randomize']) {
			$_REQUEST['repress_rewritebase'] = repress_make_permalink();

		} elseif (array_key_exists('repress_rewritebase_update', $_REQUEST) && $_REQUEST['repress_rewritebase_update']) {

			// validation

			if (!is_string($_REQUEST['repress_rewritebase_update'])) {
				repress_produce_error('The base URL is not a string!');
				return;
			}
		}

		// remove beginning and trailing slashes
		$_REQUEST['repress_rewritebase'] = preg_replace("/^\/*/", "", $_REQUEST['repress_rewritebase']);
		$_REQUEST['repress_rewritebase'] = preg_replace("/\/*$/", "", $_REQUEST['repress_rewritebase']);

		if ($_REQUEST['repress_rewritebase'] == '') {
			repress_produce_error('Running RePress on the root of your website is not supported!');
			return;
		}

		// now validate: only alphanumerical characters
		if (preg_match("/[^a-zA-Z0-9]/", $_REQUEST['repress_rewritebase'])) {
			repress_produce_error('The base URL may only contain alphanumerical characters.');
			return;
		}

		// add single leading slash
		$_REQUEST['repress_rewritebase'] = '/' . $_REQUEST['repress_rewritebase'];

		// set option	
		update_option('repress_rewritebase', $_REQUEST['repress_rewritebase']);
	}

	if (array_key_exists('repressdelete', $_REQUEST) && $_REQUEST['repressdelete']) {

		$hosts = json_decode( get_option('repress_remoteservers'), TRUE );
		$del = $_REQUEST['repressdelete'];
		$copy = array();
		foreach ($hosts as $host) {
			if ($host !== $del) {
				$copy[] = $host;
			}
		}
		$_SERVER['REQUEST_URI'] = preg_replace("#&repressdelete=.*$#", "", $_SERVER['REQUEST_URI']);
		update_option('repress_remoteservers', json_encode( $copy ));
	}

	if (array_key_exists('repress_uncensordomain', $_REQUEST) && $_REQUEST['repress_uncensordomain']) {

		// validation

		$hostname = $_REQUEST['repress_uncensordomain'];

		if (preg_match("/(?:(?:(?:(?:[a-zA-Z0-9][-a-zA-Z0-9]*)?[a-zA-Z0-9])[.])*(?:[a-zA-Z][-a-zA-Z0-9]*[a-zA-Z0-9]|[a-zA-Z])[.]?)/", $hostname)) {

			// make sure we do not have any duplicates, then add

			$hosts = json_decode( get_option('repress_remoteservers'), TRUE );

			if (in_array($hostname, $hosts)) {
				repress_produce_error('Duplicate host.');
				return;
			}

			$hosts[] = $hostname;
			$_SERVER['REQUEST_URI'] = preg_replace("#&repress_uncensordomain=.*$#", "", $_SERVER['REQUEST_URI']);
			update_option('repress_remoteservers', json_encode( $hosts ));
		
			
		} else {
			repress_produce_error('The host name appears to be invalid.');
			return;
		}

	}

}

// get the url pointing to the repress rewrite root
function repress_get_url_rewritebase() {
	return get_bloginfo('wpurl') . get_option('repress_rewritebase');	
}

// get the relative directory to the repress rewrite root
function repress_make_rewrite_base() {

        $root = get_bloginfo('wpurl') . get_option('repress_rewritebase');

        $root = preg_replace("#^http[s]?://(.*?)/#", "/", $root);

        return $root;
}

// checkboxes save
function repress_update_options_save() {

	if (array_key_exists('repress_push_post', $_REQUEST) && $_REQUEST['repress_push_post']) {
		update_option('repress_push_post', 'yes');
	} else {
		update_option('repress_push_post', '');
	}

	if (array_key_exists('repress_push_cookie', $_REQUEST) && $_REQUEST['repress_push_cookie']) {
		update_option('repress_push_cookie', 'yes');
	} else {
		update_option('repress_push_cookie', '');
	}

	if (array_key_exists('repress_use_hashes', $_REQUEST) && $_REQUEST['repress_use_hashes']) {
		update_option('repress_use_hashes', 'yes');
	} else {
		update_option('repress_use_hashes', '');
	}


}

// store error feedback message to be shown after page refresh
function repress_produce_error($str) {
	update_option('repress_errormsg', $str);
}

// clean error message
function repress_clean_error() {
	update_option('repress_errormsg', '');
}

// get current version of the plugin
function plugin_get_version() {

	if ( function_exists('get_plugin_data') ) {
		$plugin_data = get_plugin_data( __FILE__ );
		$plugin_version = $plugin_data['Version'];
		update_option('repress_version', $plugin_version );
		return $plugin_version;
	}

	$plugin_version = get_option('repress_version');
	if (is_string($plugin_version) && $plugin_version !== '') {
		return $plugin_version;
	}

	return 'unknown';
}

// try to protect against XSS attacks by logging out the Wordpress user
function repress_logout_wordpress_user() {
	if (is_user_logged_in() == TRUE) {
		repress_draw_logoutpage();
		exit;
	}
}

// did we surf to the root of the rewrite permalink itself? draw overview of uncensored sites

if (preg_replace("#/$#", "", $_SERVER['REQUEST_URI']) == repress_make_rewrite_base()) {
	repress_draw_overview_page();
	exit;
}

add_action('init', 'repress_proxy');

function repress_proxy() {

	// require plugin proxy code. which runs directly.

	require_once('proxy.php');


}

/** Bandwidth accounting **/

function repress_register_bandwidth_usage($size) {

	// in wordpress plugin, keep this in database

	$usage_now_bytes = get_option('repress_bandwidth_usage_bytes');

	$usage_new_bytes = $usage_now_bytes + $size;
	
	if ($usage_new_bytes > 1048576) {

		// another megabyte has been transferred

		$usage_new_bytes -= 1048576;
		$usage_now_mb = get_option('repress_bandwidth_usage_mb');

		update_option('repress_bandwidth_usage_mb', $usage_now_mb + 1);
	}

	update_option('repress_bandwidth_usage_bytes', $usage_new_bytes);
}


/** URL obfuscation functions (hashing) **/

// seed with microseconds
function make_seed() {
	list($usec, $sec) = explode(' ', microtime());
	return (float) $sec + ((float) $usec * 100000);
}

// Generate secret key to use in hashing functions.
function repress_make_secret() {

	mt_srand(make_seed());
 	
	$secret = '';
 	$secret_len = mt_rand(8, 12);
	for ($i = 0; $i < $secret_len; $i++) {
		$secret .= chr(mt_rand(32, 126));
	}

	return $secret;
}


// Generate random RePress permalink
function repress_make_permalink() {

	mt_srand(make_seed());

	$secret = '';
	for ($i = 0; $i < 12; $i++) {
		$num = mt_rand(0, 61);	// 62 combinations
		if ($num < 10) {
			$secret .= chr(48 + $num);		// 0 - 9
		} elseif ($num < 36) {
			$secret .= chr(97 + ($num-10));		// a - z
		} else {
			$secret .= chr(65 + ($num-10-26));	// A - Z
		}

	}

	return $secret;
}

// detect server pre-requisites for hashing
function repress_can_hash() {

	if (!function_exists('mcrypt_ecb') || !defined('MCRYPT_BLOWFISH')) {
		return FALSE;
	}

	return TRUE;

}

// Try to decode a hash to a hostname
function repress_get_host_from_hash($hash, $secret) {

	// add client IP adress to our salt key to make it unique
	$secret = $_SERVER['REMOTE_ADDR'] . $secret;

        if (function_exists('mcrypt_ecb') && defined('MCRYPT_BLOWFISH') && isset($secret)) {

		// Soms editions of PHP 5.2 produce empty 'Initialization Vector' warnings when using the mcrypt_ecb() function,
		// even though an IV is not used in ECB mode. Therefore we set it to the dummy value of '12345678'
		// - see https://bugs.php.net/bug.php?id=46010
                if ( $decrypted = mcrypt_ecb(MCRYPT_BLOWFISH, $secret, base64_decode_urlsafe( $hash ), MCRYPT_DECRYPT, "12345678" ) ) {
	                return $decrypted;
		}
                
        }

        return $hash;

}

// Obfuscate the hostname (may have subdomains) to a hash
function repress_obfuscate($hostname, $secret = null) {

	if (!$secret) { return $hostname; }

	// add client IP adress to our salt key to make it unique
	$secret = $_SERVER['REMOTE_ADDR'] . $secret;

        if (function_exists('mcrypt_ecb') && defined('MCRYPT_BLOWFISH') && isset($secret)) {

		// Soms editions of PHP 5.2 produce empty 'Initialization Vector' warnings when using the mcrypt_ecb() function,
		// even though an IV is not used in ECB mode. Therefore we set it to the dummy value of '12345678'
		// - see https://bugs.php.net/bug.php?id=46010
		$encrypted = base64_encode_urlsafe( mcrypt_ecb(MCRYPT_BLOWFISH, $secret, $hostname, MCRYPT_ENCRYPT, "12345678" ) );
		return urlencode ( $encrypted );

        } else {

                return $hostname;

        }

}

// Base64 encoding can contain characters like '/', which some webservers like Apache by default do not accept in URL strings
// Conform RFC 3548 we encode it to a URL safe string here

function base64_encode_urlsafe($input) {

	return strtr(base64_encode($input), '+/=', '-_.');

}

function base64_decode_urlsafe($input) {

	return base64_decode(strtr($input, '-_.', '+/='));

}

