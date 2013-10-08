<?php
/* Copyright (C) 2011 Jeffery Schefke
 *
 * Modified for RePress plugin
 **/

$GLOBALS['proxyhelp_rewritebase'] = '';
$GLOBALS['proxyhelp_abbreviation'] = '';
$GLOBALS['proxyhelp_use_hashes'] = '';
$GLOBALS['proxyhelp_secret'] = '';
$GLOBALS['proxyhelp_remoteservers'] = array();
function proxyhelp_init_for_repress($rewritebase, $abbreviation, $remoteservers, $usehashes, $secret) {
	$GLOBALS['proxyhelp_rewritebase'] = $rewritebase;
	$GLOBALS['proxyhelp_abbreviation'] = $abbreviation;
	$GLOBALS['proxyhelp_remoteservers'] = $remoteservers;
	$GLOBALS['proxyhelp_use_hashes'] = $usehashes;
	$GLOBALS['proxyhelp_secret'] = $secret;
}

function encode_url($url) {
        return rawurlencode($url);
}

function decode_url($url) {
	return str_replace(array('&amp;', '&#38;'), '&', rawurldecode($url));
}

function url_parse($url) {
	return $url;
}

// This functions gets a complete url in any reference on the proxied object, and rewrites it.
function complete_url($url, $proxify = true) {

	logline("proxify_html_resource: got ", $url);

	/***************************/
	/** Start parsing the URL **/
	/***************************/

	// server protocol
	$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https" : "http";

	// handle //'s as no-protocol links
	if (preg_match("#^//#", $url)) {
		$url = "$protocol:" . $url;
	}

	/**********************************/
	/*** Start investigation of URL ***/
	/**********************************/

	/*
         * The first issue is to determine if it needs to be rewritten.
	 * The second issue is to rewrite it, optionally obfuscated.
	 */

	$parsed = parse_url($url);	// this PHP function does not seem to validate hostname in any way.

	if (!isset($parsed['host']) || !repress_recognize_host($parsed['host'])) {

		logline("proxify_html_resource: resource is not one of our hosts. looking further");

		// not recognized as an URL apparantly, may be Javascript or a relative path, or anything really ...

		// try to construct an URL object ourselves if this is legit
		$parsed = array();
		$parsed['scheme'] = $protocol;
		$parsed['host'] = $GLOBALS['proxyhelp_abbreviation'];
		$accepted = FALSE;

		if (strlen($url) > 0 && $url[0] == '/') {

			logline("proxify_html_resource: something with a leading slash");

			// path to something with a leading /
			// we will take this
			$parsed['path'] = substr($url, 1);
			$accepted = TRUE;
		} else {

			// anything ... we try to recognize file extensions, if we not find them, we do not proxy

			// TODO experiment with this

			if (!preg_match("/^(http|https|ftp)/", $url) &&
			     preg_match("/\.(html|css|js|php|xml|pl|rb|asp|aspx|png|img|jpeg|jpg|bmp|gif|swf)$/", $url)) {
				logline("proxify_html_resource: accepting this path as poiting to a file");
				$parsed['path'] = $url;
				$accepted = TRUE;
			}


		}

		if (!$accepted) {

			logline("proxify_html_resource: found nothing");

			return $url;		// can do nothing with object
		}

	} else {

		/** Handle Host **/

		// obfuscate the hostname if this option is set (obfuscation)

		if ($GLOBALS['proxyhelp_use_hashes']) {
			$parsed['host'] = repress_obfuscate($parsed['host'], $GLOBALS['proxyhelp_secret']);
		}

	}

	/*** From here on we consider the $parsed object a complete URL object ***/

	/** Handle Query **/

	if (isset($parsed['query'])) {
		$parsed['query'] = htmlspecialchars_decode($parsed['query']);
	}

	/** Handle Path **/

	if (isset($parsed['path'])) {

		// strip the leading / from the path
		if (strlen($parsed['path']) > 0 && $parsed['path'][0] == '/') {
			$parsed['path'] = substr($parsed['path'], 1);
		}

		// obfuscate path if this option is set AND obfuscate the GET query (if there is one) that goes with it into a single hash (obfuscation)

		if ($GLOBALS['proxyhelp_use_hashes']) {

			if (isset($parsed['query'])) {
				$parsed['path'] = repress_obfuscate($parsed['path'] . '?' . $parsed['query'], $GLOBALS['proxyhelp_secret']);
				unset($parsed['query']);	// now we have subsumed the query part in the path, ie. our hash
			} else {
				$parsed['path'] = repress_obfuscate($parsed['path'], $GLOBALS['proxyhelp_secret']);
			}
		}

	}

	/** Build the rewrite URL **/

	$repressBase = $GLOBALS['proxyhelp_rewritebase'];

	if (isset($parsed['query'])) {

                return $repressBase . '/' . $parsed['host'] . '/' . $parsed['path'] . '?' . $parsed['query'];

//		  TODO: is this better?
//                return $repressBase . $parsed['path'] . '?' . repress_rawqueryencode($parsed['query']);

        } elseif (isset($parsed['path'])) {

                return $repressBase . '/' . $parsed['host'] . '/' . $parsed['path'];

        } else {

                return $repressBase . '/' . $parsed['host'];

        }
}

function proxify_inline_css($css)
{
    preg_match_all('#url\s*\(\s*(([^)]*(\\\))*[^)]*)(\)|$)?#i', $css, $matches, PREG_SET_ORDER);

    for ($i = 0, $count = count($matches); $i < $count; ++$i)
    {
        $css = str_replace($matches[$i][0], 'url(' . proxify_css_url($matches[$i][1]) . ')', $css);
    }
    
    return $css;
}

function proxify_css($css)
{
    $css = proxify_inline_css($css);

    preg_match_all("#@import\s*(?:\"([^\">]*)\"?|'([^'>]*)'?)([^;]*)(;|$)#i", $css, $matches, PREG_SET_ORDER);

    for ($i = 0, $count = count($matches); $i < $count; ++$i)
    {
        $delim = '"';
        $url   = $matches[$i][2];

        if (isset($matches[$i][3]))
        {
            $delim = "'";
            $url = $matches[$i][3];
        }

        $css = str_replace($matches[$i][0], '@import ' . $delim . proxify_css_url($matches[$i][1]) . $delim . (isset($matches[$i][4]) ? $matches[$i][4] : ''), $css);
    }

    return $css;
}

function proxify_css_url($url)
{
    $url   = trim($url);
    $delim = strpos($url, '"') === 0 ? '"' : (strpos($url, "'") === 0 ? "'" : '');

    return $delim . preg_replace('#([\(\),\s\'"\\\])#', '\\$1', complete_url(trim(preg_replace('#\\\(.)#', '$1', trim($url, $delim))))) . $delim;
}

function proxify_html_resource($_response_body) {

    //
    // PROXIFY HTML RESOURCE
    //
    
    $tags = array
    (
        'a'          => array('href'),
        'img'        => array('src', 'longdesc'),
        'image'      => array('src', 'longdesc'),
        'body'       => array('background'),
        'base'       => array('href'),
        'frame'      => array('src', 'longdesc'),
        'iframe'     => array('src', 'longdesc'),
        'head'       => array('profile'),
        'layer'      => array('src'),
        'input'      => array('src', 'usemap'),
        'form'       => array('action'),
        'area'       => array('href'),
        'link'       => array('href', 'src', 'urn'),
        'meta'       => array('content'),
        'param'      => array('value'),
        'applet'     => array('codebase', 'code', 'object', 'archive'),
        'object'     => array('usermap', 'codebase', 'classid', 'archive', 'data'),
        'script'     => array('src'),
        'select'     => array('src'),
        'hr'         => array('src'),
        'table'      => array('background'),
        'tr'         => array('background'),
        'th'         => array('background'),
        'td'         => array('background'),
        'bgsound'    => array('src'),
        'blockquote' => array('cite'),
        'del'        => array('cite'),
        'embed'      => array('src'),
        'fig'        => array('src', 'imagemap'),
        'ilayer'     => array('src'),
        'ins'        => array('cite'),
        'note'       => array('src'),
        'overlay'    => array('src', 'imagemap'),
        'q'          => array('cite'),
        'ul'         => array('src')
    );

    preg_match_all('#(<\s*style[^>]*>)(.*?)(<\s*/\s*style[^>]*>)#is', $_response_body, $matches, PREG_SET_ORDER);

    for ($i = 0, $count_i = count($matches); $i < $count_i; ++$i)
    {
        $_response_body = str_replace($matches[$i][0], $matches[$i][1]. proxify_css($matches[$i][2]) .$matches[$i][3], $_response_body);
    }

    preg_match_all("#<\s*([a-zA-Z\?-]+)([^>]+)>#S", $_response_body, $matches);

    for ($i = 0, $count_i = count($matches[0]); $i < $count_i; ++$i)
    {
        if (!preg_match_all("#([a-zA-Z\-\/]+)\s*(?:=\s*(?:\"([^\">]*)\"?|'([^'>]*)'?|([^'\"\s]*)))?#S", $matches[2][$i], $m, PREG_SET_ORDER))
        {
            continue;
        }
        
        $rebuild    = false;
        $extra_html = $temp = '';
        $attrs      = array();

        for ($j = 0, $count_j = count($m); $j < $count_j; $attrs[strtolower($m[$j][1])] = (isset($m[$j][4]) ? $m[$j][4] : (isset($m[$j][3]) ? $m[$j][3] : (isset($m[$j][2]) ? $m[$j][2] : false))), ++$j);
        
        if (isset($attrs['style']))
        {
            $rebuild = true;
            $attrs['style'] = proxify_inline_css($attrs['style']);
        }
        
        $tag = strtolower($matches[1][$i]);

        if (isset($tags[$tag]))
        {
            switch ($tag)
            {
                case 'a':
                    if (isset($attrs['href']))
                    {
                        $rebuild = true;
                        $attrs['href'] = complete_url($attrs['href']);
                    }
                    break;
                case 'img':
                    if (isset($attrs['src']))
                    {
                        $rebuild = true;
                        $attrs['src'] = complete_url($attrs['src']);
                    }
                    if (isset($attrs['longdesc']))
                    {
                        $rebuild = true;
                        $attrs['longdesc'] = complete_url($attrs['longdesc']);
                    }
                    break;
                case 'form':
                    if (isset($attrs['action']))
                    {
                        $rebuild = true;
                        
                        if (trim($attrs['action']) === '')
                        {
                            $attrs['action'] = $_url_parts['path'];
                        }

			// ProxyHelp can rewrite Form fields. We do not use this.

/*
			if (!isset($attrs['method']) || strtolower(trim($attrs['method'])) === 'get')
                        {
                            $extra_html = '<input type="hidden" name="' . $_config['get_form_name'] . '" value="' . encode_url(complete_url($attrs['action'], false)) . '" />';
                            $attrs['action'] = '';
                            break;
                        }
 */

                        $attrs['action'] = complete_url($attrs['action']);
                    }
                    break;
                case 'base':
                    if (isset($attrs['href']))
                    {
                        $rebuild = true;  
                        url_parse($attrs['href'], $_base);
                        $attrs['href'] = complete_url($attrs['href']);
                    }
                    break;
                case 'meta':
		    // ProxyHelp can strip meta attributes. We do not use this.

/*
                    if ($_flags['strip_meta'] && isset($attrs['name']))
                    {
                        $_response_body = str_replace($matches[0][$i], '', $_response_body);
                    }
 */
                    if (isset($attrs['http-equiv'], $attrs['content']) && preg_match('#\s*refresh\s*#i', $attrs['http-equiv']))
                    {
                        if (preg_match('#^(\s*[0-9]*\s*;\s*url=)(.*)#i', $attrs['content'], $content))
                        {
                            $rebuild = true;
                            $attrs['content'] =  $content[1] . complete_url(trim($content[2], '"\''));
                        }
                    }
                    break;
                case 'head':
                    if (isset($attrs['profile']))
                    {
                        $rebuild = true;
                        $attrs['profile'] = implode(' ', array_map('complete_url', explode(' ', $attrs['profile'])));
                    }
                    break;
                case 'applet':
                    if (isset($attrs['codebase']))
                    {
                        $rebuild = true;
                        $temp = $_base;
                        url_parse(complete_url(rtrim($attrs['codebase'], '/') . '/', false), $_base);
                        unset($attrs['codebase']);
                    }
                    if (isset($attrs['code']) && strpos($attrs['code'], '/') !== false)
                    {
                        $rebuild = true;
                        $attrs['code'] = complete_url($attrs['code']);
                    }
                    if (isset($attrs['object']))
                    {
                        $rebuild = true;
                        $attrs['object'] = complete_url($attrs['object']);
                    }
                    if (isset($attrs['archive']))
                    {
                        $rebuild = true;
                        $attrs['archive'] = implode(',', array_map('complete_url', preg_split('#\s*,\s*#', $attrs['archive'])));
                    }
                    if (!empty($temp))
                    {
                        $_base = $temp;
                    }
                    break;
                case 'object':
                    if (isset($attrs['usemap']))
                    {
                        $rebuild = true;
                        $attrs['usemap'] = complete_url($attrs['usemap']);
                    }
                    if (isset($attrs['codebase']))
                    {
                        $rebuild = true;
                        $temp = $_base;
                        url_parse(complete_url(rtrim($attrs['codebase'], '/') . '/', false), $_base);
                        unset($attrs['codebase']);
                    }
                    if (isset($attrs['data']))
                    {
                        $rebuild = true;
                        $attrs['data'] = complete_url($attrs['data']);
                    }
                    if (isset($attrs['classid']) && !preg_match('#^clsid:#i', $attrs['classid']))
                    {
                        $rebuild = true;
                        $attrs['classid'] = complete_url($attrs['classid']);
                    }
                    if (isset($attrs['archive']))
                    {
                        $rebuild = true;
                        $attrs['archive'] = implode(' ', array_map('complete_url', explode(' ', $attrs['archive'])));
                    }
                    if (!empty($temp))
                    {
                        $_base = $temp;
                    }
                    break;
                case 'param':
                    if (isset($attrs['valuetype'], $attrs['value']) && strtolower($attrs['valuetype']) == 'ref' && preg_match('#^[\w.+-]+://#', $attrs['value']))
                    {
                        $rebuild = true;
                        $attrs['value'] = complete_url($attrs['value']);
                    }
                    break;
                case 'frame':
                case 'iframe':
                    if (isset($attrs['src']))
                    {
                        $rebuild = true;
			// ProxyHelp adds &nf=1
//                        $attrs['src'] = complete_url($attrs['src']) . '&nf=1';
                        $attrs['src'] = complete_url($attrs['src']); 
                    }
                    if (isset($attrs['longdesc']))
                    {
                        $rebuild = true;
                        $attrs['longdesc'] = complete_url($attrs['longdesc']);
                    }
                    break;
                default:
                    foreach ($tags[$tag] as $attr)
                    {
                        if (isset($attrs[$attr]))
                        {
                            $rebuild = true;
                            $attrs[$attr] = complete_url($attrs[$attr]);
                        }
                    }
                    break;
            }
        }
    
        if ($rebuild)
        {
            $new_tag = "<$tag";
            foreach ($attrs as $name => $value)
            {
                $delim = strpos($value, '"') && !strpos($value, "'") ? "'" : '"';
                $new_tag .= ' ' . $name . ($value !== false ? '=' . $delim . $value . $delim : '');
            }

            $_response_body = str_replace($matches[0][$i], $new_tag . '>' . $extra_html, $_response_body);
        }
    }
    
    return $_response_body;

}
?>
