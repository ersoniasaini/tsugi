<?php

// Just turn if off...
if ( function_exists ( 'libxml_disable_entity_loader' ) ) libxml_disable_entity_loader();

require_once 'OAuth.php';

// Returns true if this is a Basic LTI message
// with minimum values to meet the protocol
function is_lti_request() {
   $good_message_type = $_REQUEST["lti_message_type"] == "basic-lti-launch-request" ||
        $_REQUEST["lti_message_type"] == "ToolProxyReregistrationRequest";
   $good_lti_version = $_REQUEST["lti_version"] == "LTI-1p0" || $_REQUEST["lti_version"] == "LTI-2p0";
   if ($good_message_type and $good_lti_version ) return(true);
   return false;
}

/**
 * A Trivial memory-based store - no support for tokens
 */
class TrivialOAuthDataStore extends OAuthDataStore {
    private $consumers = array();

    function add_consumer($consumer_key, $consumer_secret) {
        $this->consumers[$consumer_key] = $consumer_secret;
    }

    function lookup_consumer($consumer_key) {
        if ( strpos($consumer_key, "http://" ) === 0 ) {
            $consumer = new OAuthConsumer($consumer_key,"secret", NULL);
            return $consumer;
        }
        if ( $this->consumers[$consumer_key] ) {
            $consumer = new OAuthConsumer($consumer_key,$this->consumers[$consumer_key], NULL);
            return $consumer;
        }
        return NULL;
    }

    function lookup_token($consumer, $token_type, $token) {
        return new OAuthToken($consumer, "");
    }

    // Return NULL if the nonce has not been used
    // Return $nonce if the nonce was previously used
    function lookup_nonce($consumer, $token, $nonce, $timestamp) {
        // Should add some clever logic to keep nonces from
        // being reused - for no we are really trusting
  // that the timestamp will save us
        return NULL;
    }

    function new_request_token($consumer) {
        return NULL;
    }

    function new_access_token($token, $consumer) {
        return NULL;
    }
}

function signParameters($oldparms, $endpoint, $method, $oauth_consumer_key, $oauth_consumer_secret,
    $submit_text = false, $org_id = false, $org_desc = false)
{
    global $LastOAuthBodyBaseString;
    $parms = $oldparms;
    if ( ! isset($parms["lti_version"]) ) $parms["lti_version"] = "LTI-1p0";
    if ( ! isset($parms["lti_message_type"]) ) $parms["lti_message_type"] = "basic-lti-launch-request";
    if ( ! isset($parms["oauth_callback"]) ) $parms["oauth_callback"] = "about:blank";
    if ( $org_id ) $parms["tool_consumer_instance_guid"] = $org_id;
    if ( $org_desc ) $parms["tool_consumer_instance_description"] = $org_desc;
    if ( $submit_text ) $parms["ext_submit"] = $submit_text;

    $test_token = '';

    $hmac_method = new OAuthSignatureMethod_HMAC_SHA1();
    $test_consumer = new OAuthConsumer($oauth_consumer_key, $oauth_consumer_secret, NULL);

    $acc_req = OAuthRequest::from_consumer_and_token($test_consumer, $test_token, $method, $endpoint, $parms);
    $acc_req->sign_request($hmac_method, $test_consumer, $test_token);

    // Pass this back up "out of band" for debugging
    $LastOAuthBodyBaseString = $acc_req->get_signature_base_string();

    $newparms = $acc_req->get_parameters();

  // Don't want to pull GET parameters into POST data so
    // manually pull back the oauth_ parameters
  foreach($newparms as $k => $v ) {
        if ( strpos($k, "oauth_") === 0 ) {
            $parms[$k] = $v;
        }
    }

    return $parms;
}

  function postLaunchHTML($newparms, $endpoint, $debug=false, $iframeattr=false) {
    global $LastOAuthBodyBaseString;
    $r = "<div id=\"ltiLaunchFormSubmitArea\">\n";
    if ( $iframeattr ) {
        $r = "<form action=\"".$endpoint."\" name=\"ltiLaunchForm\" id=\"ltiLaunchForm\" method=\"post\" target=\"basicltiLaunchFrame\" encType=\"application/x-www-form-urlencoded\">\n" ;
    } else {
        $r = "<form action=\"".$endpoint."\" name=\"ltiLaunchForm\" id=\"ltiLaunchForm\" method=\"post\" encType=\"application/x-www-form-urlencoded\">\n" ;
    }
    $submit_text = $newparms['ext_submit'];
    foreach($newparms as $key => $value ) {
        $key = htmlspec_utf8($key);
        $value = htmlspec_utf8($value);
        if ( $key == "ext_submit" ) {
            $r .= "<input type=\"submit\" name=\"";
        } else {
            $r .= "<input type=\"hidden\" name=\"";
        }
        $r .= $key;
        $r .= "\" value=\"";
        $r .= $value;
        $r .= "\"/>\n";
    }
    if ( $debug ) {
        $r .= "<script language=\"javascript\"> \n";
        $r .= "  //<![CDATA[ \n" ;
        $r .= "function basicltiDebugToggle() {\n";
        $r .= "    var ele = document.getElementById(\"basicltiDebug\");\n";
        $r .= "    if(ele.style.display == \"block\") {\n";
        $r .= "        ele.style.display = \"none\";\n";
        $r .= "    }\n";
        $r .= "    else {\n";
        $r .= "        ele.style.display = \"block\";\n";
        $r .= "    }\n";
        $r .= "} \n";
        $r .= "  //]]> \n" ;
        $r .= "</script>\n";
        $r .= "<a id=\"displayText\" href=\"javascript:basicltiDebugToggle();\">";
        $r .= get_string("toggle_debug_data","basiclti")."</a>\n";
        $r .= "<div id=\"basicltiDebug\" style=\"display:none\">\n";
        $r .=  "<b>".get_string("basiclti_endpoint","basiclti")."</b><br/>\n";
        $r .= $endpoint . "<br/>\n&nbsp;<br/>\n";
        $r .=  "<b>".get_string("basiclti_parameters","basiclti")."</b><br/>\n";
        foreach($newparms as $key => $value ) {
            $key = htmlspec_utf8($key);
            $value = htmlspec_utf8($value);
            $r .= "$key = $value<br/>\n";
        }
        $r .= "&nbsp;<br/>\n";
        $r .= "<p><b>".get_string("basiclti_base_string","basiclti")."</b><br/>\n".$LastOAuthBodyBaseString."</p>\n";
        $r .= "</div>\n";
    }
    $r .= "</form>\n";
    if ( $iframeattr ) {
        $r .= "<iframe name=\"basicltiLaunchFrame\"  id=\"basicltiLaunchFrame\" src=\"\"\n";
        $r .= $iframeattr . ">\n<p>".get_string("frames_required","basiclti")."</p>\n</iframe>\n";
    }
    if ( ! $debug ) {
        $ext_submit = "ext_submit";
        $ext_submit_text = $submit_text;
        $r .= " <script type=\"text/javascript\"> \n" .
            "  //<![CDATA[ \n" .
            "    document.getElementById(\"ltiLaunchForm\").style.display = \"none\";\n" .
            "    nei = document.createElement('input');\n" .
            "    nei.setAttribute('type', 'hidden');\n" .
            "    nei.setAttribute('name', '".$ext_submit."');\n" .
            "    nei.setAttribute('value', '".$ext_submit_text."');\n" .
            "    document.getElementById(\"ltiLaunchForm\").appendChild(nei);\n" .
            "    document.ltiLaunchForm.submit(); \n" .
            "  //]]> \n" .
            " </script> \n";
    }
    $r .= "</div>\n";
    return $r;
}

/* This is a bit of homage to Moodle's pattern of internationalisation */
function get_string($key,$bundle) {
    return $key;
}

function do_post_request($url, $data, $optional_headers = null)
{
  if ($optional_headers !== null) {
     $header = $optional_headers . "\r\n";
  }
  $header = $header . "Content-Type: application/x-www-form-urlencoded\r\n";

  return do_post($url,$data,$header);
}


  // Parse a descriptor
  function launchInfo($xmldata) {
    $xml = new SimpleXMLElement($xmldata);
    if ( ! $xml ) {
       echo("Error parsing Descriptor XML\n");
       return;
    }
    $launch_url = $xml->secure_launch_url[0];
    if ( ! $launch_url ) $launch_url = $xml->launch_url[0];
    if ( $launch_url ) $launch_url = (string) $launch_url;
    $custom = array();
    if ( $xml->custom[0]->parameter )
    foreach ( $xml->custom[0]->parameter as $resource) {
      $key = (string) $resource['key'];
      $key = strtolower($key);
      $nk = "";
      for($i=0; $i < strlen($key); $i++) {
        $ch = substr($key,$i,1);
        if ( $ch >= "a" && $ch <= "z" ) $nk .= $ch;
        else if ( $ch >= "0" && $ch <= "9" ) $nk .= $ch;
        else $nk .= "_";
      }
      $value = (string) $resource;
      $custom["custom_".$nk] = $value;
    }
    return array("launch_url" => $launch_url, "custom" => $custom ) ;
  }

  function addCustom(&$parms, $custom) {
    foreach ( $custom as $key => $val) {
      $key = strtolower($key);
      $nk = "";
      for($i=0; $i < strlen($key); $i++) {
        $ch = substr($key,$i,1);
        if ( $ch >= "a" && $ch <= "z" ) $nk .= $ch;
        else if ( $ch >= "0" && $ch <= "9" ) $nk .= $ch;
        else $nk .= "_";
      }
      $parms["custom_".$nk] = $val;
    }
  }

  function curPageURL() {
    $pageURL = (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != "on")
             ? 'http'
             : 'https';
    $pageURL .= "://";
    $pageURL .= $_SERVER['HTTP_HOST'];
    //$pageURL .= $_SERVER['REQUEST_URI'];
    $pageURL .= $_SERVER['PHP_SELF'];
    return $pageURL;
  }


function getLastOAuthBodyBaseString() {
    global $LastOAuthBodyBaseString;
    return $LastOAuthBodyBaseString;
}

function getLastOAuthBodyHashInfo() {
    global $LastOAuthBodyHashInfo;
    return $LastOAuthBodyHashInfo;
}


function getOAuthKeyFromHeaders()
{
    $request_headers = OAuthUtil::get_headers();
    // print_r($request_headers);

    if (@substr($request_headers['Authorization'], 0, 6) == "OAuth ") {
        $header_parameters = OAuthUtil::split_header($request_headers['Authorization']);

        // echo("HEADER PARMS=\n");
        // print_r($header_parameters);
        return $header_parameters['oauth_consumer_key'];
    }
    return false;
}

function handleOAuthBodyPOST($oauth_consumer_key, $oauth_consumer_secret)
{
    $request_headers = OAuthUtil::get_headers();
    // print_r($request_headers);

    // Must reject application/x-www-form-urlencoded
    if ($request_headers['Content-Type'] == 'application/x-www-form-urlencoded' ) {
        throw new Exception("OAuth request body signing must not use application/x-www-form-urlencoded");
    }

    if (@substr($request_headers['Authorization'], 0, 6) == "OAuth ") {
        $header_parameters = OAuthUtil::split_header($request_headers['Authorization']);

        // echo("HEADER PARMS=\n");
        // print_r($header_parameters);
        $oauth_body_hash = $header_parameters['oauth_body_hash'];
        // echo("OBH=".$oauth_body_hash."\n");
    }

    if ( ! isset($oauth_body_hash)  ) {
        throw new Exception("OAuth request body signing requires oauth_body_hash body");
    }

    // Verify the message signature
    $store = new TrivialOAuthDataStore();
    $store->add_consumer($oauth_consumer_key, $oauth_consumer_secret);

    $server = new OAuthServer($store);

    $method = new OAuthSignatureMethod_HMAC_SHA1();
    $server->add_signature_method($method);
    $request = OAuthRequest::from_request();

    global $LastOAuthBodyBaseString;
    $LastOAuthBodyBaseString = $request->get_signature_base_string();
    // echo($LastOAuthBodyBaseString."\n");

    try {
        $server->verify_request($request);
    } catch (Exception $e) {
        $message = $e->getMessage();
        throw new Exception("OAuth signature failed: " . $message);
    }

    $postdata = file_get_contents('php://input');
    // echo($postdata);

    $hash = base64_encode(sha1($postdata, TRUE));

    global $LastOAuthBodyHashInfo;
    $LastOAuthBodyHashInfo = "hdr_hash=$oauth_body_hash body_len=".strlen($postdata)." body_hash=$hash";

    if ( $hash != $oauth_body_hash ) {
        throw new Exception("OAuth oauth_body_hash mismatch");
    }

    return $postdata;
}

function do_get($url, $header = false) {
    $response = get_stream($url, $header);
    if ( $response !== false ) return $response;
/*
    $response = get_socket($url, $header);
    if ( $response !== false ) return $response;
    $response = get_curl($url, $header);
    if ( $response !== false ) return $response;
*/
    echo("Unable to GET<br/>\n");
    echo("Url=$url <br/>\n");
    echo("Headers:<br/>\n$headers<br/>\n");
    throw new Exception("Unable to get");
}

function get_stream($url, $header) {
    $params = array('http' => array(
        'method' => 'GET',
        'header' => $header
        ));

    $ctx = stream_context_create($params);
    try {
        $fp = @fopen($url, 'r', false, $ctx);
        $response = @stream_get_contents($fp);
    } catch (Exception $e) {
        return false;
    }
    return $response;
}

function sendOAuthBodyPOST($method, $endpoint, $oauth_consumer_key, $oauth_consumer_secret, $content_type, $body)
{
    $hash = base64_encode(sha1($body, TRUE));

    $parms = array('oauth_body_hash' => $hash);

    $test_token = '';
    $hmac_method = new OAuthSignatureMethod_HMAC_SHA1();
    $test_consumer = new OAuthConsumer($oauth_consumer_key, $oauth_consumer_secret, NULL);

    $acc_req = OAuthRequest::from_consumer_and_token($test_consumer, $test_token, $method, $endpoint, $parms);
    $acc_req->sign_request($hmac_method, $test_consumer, $test_token);

    // Pass this back up "out of band" for debugging
    global $LastOAuthBodyBaseString;
    $LastOAuthBodyBaseString = $acc_req->get_signature_base_string();

    $header = $acc_req->to_header();
    $altheader = $acc_req->to_alternate_header();
    $header = $header . "\r\n" . $altheader . "\r\nContent-Type: " . $content_type . "\r\n";

    global $LastPOSTHeader;
    $LastPOSTHeader = $header;

    return do_post($endpoint,$body,$header);
}

function get_post_sent_debug() {
    global $LastPOSTMethod;
    global $LastPOSTURL;
    global $LastHeadersSent;

    $ret = "POST Used: " . $LastPOSTMethod . "\n" . 
         $LastPOSTURL . "\n\n" .
         $LastHeadersSent . "\n";
    return $ret;
}

function get_post_received_debug() {
    global $LastPOSTURL;
    global $last_http_response;
    global $LastPOSTMethod;
    global $LastHeadersReceived;

    $ret = "POST Used: " . $LastPOSTMethod . "\n" .
         "HTTP Response: " . $last_http_response . "\n" .
         $LastPOSTURL . "\n" .
         $LastHeadersReceived . "\n";
    return $ret;
}

// Sadly this tries several approaches depending on 
// the PHP version and configuration.  You can use only one
// if you know what version of PHP is working and how it will be 
// configured...
function do_post($url, $body, $header) {
    global $LastPOSTURL;
    global $LastPOSTMethod;
    global $LastHeadersSent;
    global $last_http_response;
    global $LastHeadersReceived;
    global $LastPostResponse;

    $LastPOSTURL = $url;
    $LastPOSTMethod = false;
    $LastHeadersSent = false;
    $last_http_response = false;
    $LastHeadersReceived = false;
    $lastPOSTResponse = false;

    // Prefer curl because it checks if it works before trying
    $lastPOSTResponse = post_curl($url, $body, $header);
    $LastPOSTMethod = "CURL";
    if ( $lastPOSTResponse !== false ) return $lastPOSTResponse;
    $lastPOSTResponse = post_socket($url, $body, $header);
    $LastPOSTMethod = "Socket";
    if ( $lastPOSTResponse !== false ) return $lastPOSTResponse;
    // Timeout does not seem to yet work in post_stream
    $lastPOSTResponse = post_stream($url, $body, $header);
    $LastPOSTMethod = "Stream";
    if ( $lastPOSTResponse !== false ) return $lastPOSTResponse;
    $LastPOSTMethod = "Error";
    echo("Unable to post<br/>\n");
    echo("Url=$url <br/>\n");
    echo("Headers:<br/>\n$header<br/>\n");
    echo("Body:<br/>\n$body<br/>\n");
    throw new Exception("Unable to post");
}

// From: http://php.net/manual/en/function.file-get-contents.php
function post_socket($endpoint, $data, $moreheaders=false) {
  global $sendOAuthBodyPOSTTimeout;
  if ( ! function_exists('fsockopen') ) return false;
  if ( ! function_exists('stream_get_transports') ) return false;
    $url = parse_url($endpoint);

    if (!isset($url['scheme'])) return false;
    if (!isset($url['port'])) {
      if ($url['scheme'] == 'http') { $url['port']=80; }
      elseif ($url['scheme'] == 'https') { $url['port']=443; }
    }

    $url['query']=isset($url['query'])?$url['query']:'';

    $hostport = ':'.$url['port'];
    if ($url['scheme'] == 'http' && $hostport == ':80' ) $hostport = '';
    if ($url['scheme'] == 'https' && $hostport == ':443' ) $hostport = '';

    $url['protocol']=$url['scheme'].'://';
    $eol="\r\n";

    $uri = "/";
    if ( isset($url['path'])) $uri = $url['path'];
    if ( isset($url['query']) ) $uri .= '?'.$url['query'];
    if ( isset($url['fragment']) ) $uri .= '#'.$url['fragment'];

    $headers =  "POST ".$uri." HTTP/1.0".$eol.
                "Host: ".$url['host'].$hostport.$eol.
                "Referer: ".$url['protocol'].$url['host'].$url['path'].$eol.
                "Content-Length: ".strlen($data).$eol;
    if ( is_string($moreheaders) ) $headers .= $moreheaders;
    $len = strlen($headers);
    if ( substr($headers,$len-2) != $eol ) {
        $headers .= $eol;
    }
    $headers .= $eol.$data;
    // echo("\n"); echo($headers); echo("\n");
    // echo("PORT=".$url['port']);
    $hostname = $url['host'];
    if ( $url['port'] == 443 ) $hostname = "ssl://" . $hostname;
    $timeout = 10;
    if ( isset($sendOAuthBodyPOSTTimeout) ) $timeout = $sendOAuthBodyPOSTTimeout;
    try {
        $fp = fsockopen($hostname, $url['port'], $errno, $errstr, $timeout);
        stream_set_timeout($fp, $timeout);
        stream_set_blocking($fp, TRUE );
        if($fp) {
            fputs($fp, $headers);
            $result = '';
            while(!feof($fp)) { 
                $data = fgets($fp, 1024); 
                if ( $data === false ) {
                    $info = stream_get_meta_data($fp);
                    if ($info['timed_out']) {
                        throw new Exception('Time out');
                    }
                }
                $result .= $data;
            }
            fclose($fp);
            // removes HTTP response headers
            $pattern="/^.*\r\n\r\n/s";
            $result=preg_replace($pattern,'',$result);
            return $result;
        }
    } catch(Exception $e) {
        error_log("post_socket error=".$e->getMessage());
        throw $e;
    }
    return false;
}

function post_stream($url, $body, $header) {
    global $sendOAuthBodyPOSTTimeout;
    $timeout = 10;
    if ( isset($sendOAuthBodyPOSTTimeout) ) $timeout = $sendOAuthBodyPOSTTimeout;

    $params = array('http' => array(
        'method' => 'POST',
        'content' => $body,
        'timeout' => $timeout*1.0, 
        'header' => $header
        ));

    $ctx = stream_context_create($params);
    try {
        $fp = @fopen($url, 'r', false, $ctx);
        $response = @stream_get_contents($fp);
    } catch (Exception $e) {
        error_log("post_stream error=".$e->getMessage());
        throw $e;
    }
    return $response;
}

function post_curl($url, $body, $header) {
  if ( ! function_exists('curl_init') ) return false;
  global $last_http_response;
  global $LastHeadersSent;
  global $LastHeadersReceived;
  global $sendOAuthBodyPOSTTimeout;

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);

  // Make sure that the header is an array and pitch white space
  $LastHeadersSent = trim($header);
  $header = explode("\n", trim($header));
  $htrim = Array();
  foreach ( $header as $h ) {
    $htrim[] = trim($h);
  }
  curl_setopt ($ch, CURLOPT_HTTPHEADER, $htrim);

  curl_setopt($ch, CURLOPT_POST, 1);
  curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); // ask for results to be returned
  curl_setopt($ch, CURLOPT_HEADER, 1);
  $timeout = 10;
  if ( isset($sendOAuthBodyPOSTTimeout) ) $timeout = $sendOAuthBodyPOSTTimeout;
  curl_setopt($ch,CURLOPT_TIMEOUT,$timeout);
  curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);
/*
  if(CurlHelper::checkHttpsURL($url)) {
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
  }
*/

  // Send to remote and return data to caller.
  $result = curl_exec($ch);
  $info = curl_getinfo($ch);
  if ( curl_errno($ch) == 28 ) {
    error_log("CURL Timed out - ".$url);
    throw new Exception('Time out');
  }
  $last_http_response = $info['http_code'];
  $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  $LastHeadersReceived = substr($result, 0, $header_size);
  $body = substr($result, $header_size);
  curl_close($ch);
  return $body;
}

/*  $postBody = str_replace(
      array('SOURCEDID', 'GRADE', 'OPERATION','MESSAGE'),
      array($sourcedid, $_REQUEST['grade'], $operation, uniqid()),
      getPOXGradeRequest());
*/

function getPOXGradeRequest() {
    return '<?xml version = "1.0" encoding = "UTF-8"?>
<imsx_POXEnvelopeRequest xmlns = "http://www.imsglobal.org/services/ltiv1p1/xsd/imsoms_v1p0">
  <imsx_POXHeader>
    <imsx_POXRequestHeaderInfo>
      <imsx_version>V1.0</imsx_version>
      <imsx_messageIdentifier>MESSAGE</imsx_messageIdentifier>
    </imsx_POXRequestHeaderInfo>
  </imsx_POXHeader>
  <imsx_POXBody>
    <OPERATION>
      <resultRecord>
        <sourcedGUID>
          <sourcedId>SOURCEDID</sourcedId>
        </sourcedGUID>
        <result>
          <resultScore>
            <language>en-us</language>
            <textString>GRADE</textString>
          </resultScore>
        </result>
      </resultRecord>
    </OPERATION>
  </imsx_POXBody>
</imsx_POXEnvelopeRequest>';
}

/*  $postBody = str_replace(
      array('SOURCEDID', 'OPERATION','MESSAGE'),
      array($sourcedid, $operation, uniqid()),
      getPOXRequest());
*/
function getPOXRequest() {
    return '<?xml version = "1.0" encoding = "UTF-8"?>
<imsx_POXEnvelopeRequest xmlns = "http://www.imsglobal.org/services/ltiv1p1/xsd/imsoms_v1p0">
  <imsx_POXHeader>
    <imsx_POXRequestHeaderInfo>
      <imsx_version>V1.0</imsx_version>
      <imsx_messageIdentifier>MESSAGE</imsx_messageIdentifier>
    </imsx_POXRequestHeaderInfo>
  </imsx_POXHeader>
  <imsx_POXBody>
    <OPERATION>
      <resultRecord>
        <sourcedGUID>
          <sourcedId>SOURCEDID</sourcedId>
        </sourcedGUID>
      </resultRecord>
    </OPERATION>
  </imsx_POXBody>
</imsx_POXEnvelopeRequest>';
}

/*     sprintf(getPOXResponse(),uniqid(),'success', "Score read successfully",$message_ref,$body);
*/

function getPOXResponse() {
    return '<?xml version="1.0" encoding="UTF-8"?>
<imsx_POXEnvelopeResponse xmlns="http://www.imsglobal.org/services/ltiv1p1/xsd/imsoms_v1p0">
    <imsx_POXHeader>
        <imsx_POXResponseHeaderInfo>
            <imsx_version>V1.0</imsx_version>
            <imsx_messageIdentifier>%s</imsx_messageIdentifier>
            <imsx_statusInfo>
                <imsx_codeMajor>%s</imsx_codeMajor>
                <imsx_severity>status</imsx_severity>
                <imsx_description>%s</imsx_description>
                <imsx_messageRefIdentifier>%s</imsx_messageRefIdentifier>
                <imsx_operationRefIdentifier>%s</imsx_operationRefIdentifier>
            </imsx_statusInfo>
        </imsx_POXResponseHeaderInfo>
    </imsx_POXHeader>
    <imsx_POXBody>%s
    </imsx_POXBody>
</imsx_POXEnvelopeResponse>';
}

function replaceResultRequest($grade, $sourcedid, $endpoint, $oauth_consumer_key, $oauth_consumer_secret) {
    $method="POST";
    $content_type = "application/xml";
    $operation = 'replaceResultRequest';
    $postBody = str_replace(
        array('SOURCEDID', 'GRADE', 'OPERATION','MESSAGE'),
        array($sourcedid, $grade, $operation, uniqid()),
        getPOXGradeRequest());

    $response = sendOAuthBodyPOST($method, $endpoint, $oauth_consumer_key, $oauth_consumer_secret, $content_type, $postBody);
    return parseResponse($response);
}

function parseResponse($response) {
    $retval = Array();
    try {
        $xml = new SimpleXMLElement($response);
        $imsx_header = $xml->imsx_POXHeader->children();
        $parms = $imsx_header->children();
        $status_info = $parms->imsx_statusInfo;
        $retval['imsx_codeMajor'] = (string) $status_info->imsx_codeMajor;
        $retval['imsx_severity'] = (string) $status_info->imsx_severity;
        $retval['imsx_description'] = (string) $status_info->imsx_description;
        $retval['imsx_messageIdentifier'] = (string) $parms->imsx_messageIdentifier;
        $imsx_body = $xml->imsx_POXBody->children();
        $operation = $imsx_body->getName();
        $retval['response'] = $operation;
        $parms = $imsx_body->children();
    } catch (Exception $e) {
        throw new Exception('Error: Unable to parse XML response' . $e->getMessage());
    }

    if ( $operation == 'readResultResponse' ) {
       try {
           $retval['language'] =(string) $parms->result->resultScore->language;
           $retval['textString'] = (string) $parms->result->resultScore->textString;
       } catch (Exception $e) {
            throw new Exception("Error: Body parse error: ".$e->getMessage());
       }
    }
    return $retval;
}

// Compares base strings, start of the mis-match
// Returns true if the strings are identical
// This is setup to be displayed in <pre> tags as newlines are added
function compare_base_strings($string1, $string2)
{
    if ( $string1 == $string2 ) return true;

    $out2 = "";
    $out1 = "";
    $chars = 0;
    $oops = false;
    for($i=0; $i<strlen($string1)&&$i<strlen($string2); $i++) {
        if ( $oops || $string1[$i] == $string2[$i] ) {
            $out1 = $out1 . $string1[$i];
            $out2 = $out2 . $string2[$i];
        } else { 
            $out1 = $out1 . ' ->' . $string1[$i] .'<- ';
            $out2 = $out2 . ' ->' . $string2[$i] .'<- ';
            $oops = true;
        }
        $chars = $chars + 1;
        if ( $chars > 79 ) {
            $out1 .= "\n";
            $out2 .= "\n";
            $chars = 0;
        }
    }
    if ( $i < strlen($string1) ) {
        $out2 = $out2 . ' -> truncated ';
        for($i=0; $i<strlen($string1); $i++) {
            $out1 = $out1 . $string1[$i];
            $chars = $chars + 1;
            if ( $chars > 79 ) {
                $out1 .= "\n";
                $chars = 0;
            }
        }
    }

    if ( $i < strlen($string2) ) {
        $out1 = $out1 . ' -> truncated ';
        for($i=0; $i<strlen($string2); $i++) {
            $out2 = $out2 . $string2[$i];
            $chars = $chars + 2;
            if ( $chars > 79 ) {
                $out2 .= "\n";
                $chars = 0;
            }
        }
    }
    return $out1 . "\n-------------\n" . $out2 . "\n";
}
?>
