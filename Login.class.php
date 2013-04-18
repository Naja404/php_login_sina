<?php
/*
*  sina数据采集
*	2013-4-1 
*
*/
class Collect{

	function curl_switch_method($curl, $method) {
		switch ($method) {
			case REQUEST_METHOD_POST:
				curl_setopt($curl, CURLOPT_POST, TRUE);
				break;
			case REQUEST_METHOD_HEAD:
				curl_setopt($curl, CURLOPT_NOBODY, TRUE);
				break;
			case REQUEST_METHOD_GET:
			default:
				curl_setopt($curl, CURLOPT_HTTPGET, TRUE);
				break;
		}
	}

	function curl_set_headers($curl, $headers) {
		if (empty($headers)) return;
		if (is_string($headers)) $headers = explode("\r\n", $headers);
		//#类型修复
		foreach($headers as & $header)
		if (is_array($header)) $header = sprintf('%s: %s', $header[0], $header[1]);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
	}

	function curl_set_datas($curl, $datas) {
		if (empty($datas)) return false;
		curl_setopt($curl, CURLOPT_POSTFIELDS, $datas);
	}

	function curl_request($url, $method = REQUEST_METHOD_GET, $datas = NULL, $headers = NULL) {
		static $curl;
		if (!$curl) $curl = curl_init();
		$this->curl_switch_method($curl, $method);
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($curl, CURLOPT_AUTOREFERER, TRUE);
		curl_setopt($curl, CURLOPT_COOKIEJAR, COOKIE_FILE);
		curl_setopt($curl, CURLOPT_COOKIESESSION, TRUE);
		if ($datas) $this->curl_set_datas($curl, $datas);
		if ($headers) $this->curl_set_headers($curl, $headers);
		$response = curl_exec($curl);
		if ($errno = curl_errno($curl)) {
			error_log(sprintf("d\t%s\n", $errno, curl_error($curl)), 3, 'php://stderr');
			return FALSE;
		}
		return $response;
	}

	function get_js_timestamp() {
		return time() * 1000 + rand(0, 999);
	}

	function http_build_query_no_encode($datas) {
		$r = array();
		foreach($datas as $k => $v){
			$r[] = $k.'='.$v;
		}
		
		return implode('&', $r);
	}

	function makeUrl($url, $info, $encode = TRUE) {
		if (!is_array($info) || empty($info)) return $url;
		$components = parse_url($url);
		if (array_key_exists('query', $components)) $query = parse_str($components['query']);
		else $query = array();
		if (is_string($info)) $info = parse_str($info);
		$query = array_merge($query, $info);
		$query = $encode ? http_build_query($query) : $this->http_build_query_no_encode($query);
		$components['scheme'] = array_key_exists('scheme', $components) ? $components['scheme'].'://' : '';
		$components['user'] = array_key_exists('user', $components) ? $components['user'].':'.$components[HTTP_URL_PASS].'@' : '';
		$components['host'] = array_key_exists('host', $components) ? $components['host'] : '';
		$components['port'] = array_key_exists('port', $components) ? ':'.$components['port'] : '';
		$components['path'] = array_key_exists('path', $components) ? '/'.ltrim($components['path'], '/') : '';
		$components['query'] = $query ? '?'.$query : '';
		$components['fragment'] = array_key_exists('fragment', $components) ? '#'.$components['fragment'] : '';
		return sprintf('%s%s%s%s%s%s%s', $components['scheme'], $components['user'], $components['host'],$components['port'], $components['path'],$components['query'], $components['fragment']);
	}

	function encode_username($username) {
		return base64_encode(urlencode($username));
	}

	function encode_password($pub_key, $password, $servertime, $nonce) {
		#这里是要用nodejs执行新浪的js文件
		#linux
		$response = `/usr/local/bin/node real_sina.js "$pub_key" "$servertime" "$nonce" "$password" `;
		#windows
		//$response = system("/usr/local/bin/node real_sina.js $pub_key $servertime $nonce $password");
		return substr($response, 0, strlen($response) - 1);
	}


	function main_page() {
		return curl_request('weibo.com');
	}

	function prepare_login_info() {
		$time = $this->get_js_timestamp();
		$url = $this->makeUrl('http://login.sina.com.cn/sso/prelogin.php', array(
			'entry' => 'sso',
			'callback' => 'sinaSSOController.preloginCallBack',
			'su' => $this->encode_username('undefined'),
			'rsakt' => 'mod',
			'client' => 'ssologin.js(v1.4.5)',
			'_' => $time, ), FALSE);
		$response = $this->curl_request($url);
		$length = strlen($response);
		$left = 0;
		$right = $length - 1;
		while ($left < $length)
		if ($response[$left] == '{') break;
		else $left++;
		while ($right > 0)
		if ($response[$right] == '}') break;
		else $right--;
		$response = substr($response, $left, $right - $left + 1);
		return array_merge(json_decode($response, TRUE), array('preloginTime' => max($this->get_js_timestamp() - $time, 100), ));
	}

	function login($info, $username, $password) {
		$feedbackurl = $this->makeUrl('http://weibo.com/ajaxlogin.php', array(
			'framelogin' => 1,
			'callback' => 'parent.sinaSSOController.feedBackUrlCallBack', ));
		$datas = array(
			'encoding' => 'UTF-8',
			'entry' => 'weibo',
			'from' => '',
			'gateway' => 1,
			'nonce' => $info['nonce'],
			'pagerefer' => 'http://login.sina.com.cn/sso/logout.php?entry=miniblog&r=http://weibo.com/logout.php?backurl=/',
			'prelt' => $info['preloginTime'],
			'pwencode' => 'rsa2',
			'returntype' => 'META',
			'rsakv' => $info['rsakv'],
			'savestate' => 7,
			'servertime' => $info['servertime'],
			'service' => 'miniblog',
			'sp' => $this->encode_password($info['pubkey'], $password, $info['servertime'], $info['nonce']),
			//'ssosimplelogin' => 1,
			'su' => $this->encode_username($username),
			'url' => $feedbackurl,
			'useticket' => 1,
			'vsnf' => 1, );
		$url = $this->makeUrl('http://login.sina.com.cn/sso/login.php', array('client' => 'ssologin.js(v1.4.5)', ), FALSE);
		$response = $this->curl_request($url, REQUEST_METHOD_POST, $datas, '', 1);
		$regex = '/http:\/\/.*retcode\=0/';
		$matches = array();
		if (preg_match($regex, $response, $matches)) {
			$content = $matches[0];
			$content = $this->curl_request($content, REQUEST_METHOD_GET);
			$matches = array();
			$regex1 = '/\(\{\".*\}\)/';
			if (preg_match($regex1, $content, $matches)) {
				$content = $matches[0];
				$content = substr($content, 1);
				$content = substr($content, 0, (strlen($content) - 1));
				$info = json_decode($content);
				if($info->userinfo->uniqueid){
					return $info;
				}else{
					return false;
				}
			}
		}
	}

	function getUser($location = '121.437621,31.234706', $page = 1){
		$contents = $this->curl_request('http://place.weibo.com/users/n/'.$location.'/');
		preg_match('/<div class=\"place_details\">\s+<h2>(.*)<\/h2>\s+<\/div>/', $contents, $locations);
		preg_match_all('/<div\s+class=\"bd\">([^.?]+)<\/div>/iU', $contents, $res);
		preg_match_all('/\"feed_list_page_n\">(\d+)</', $contents, $pages);
		$j = 0;
		foreach ($pages[1] as $k => $v) {
			if((int)$v > $j){
				$j = (int)$v;
			}
		}
		$pages = $j;

		$result = array();

		foreach ($res[0] as $k => $v) {
			preg_match('/href=\"\/u\/([0-9]{1,30})\"\s+target/', $v, $uid);
			preg_match('/<h4><[^>]+>(.*)<\/a><\/h4>/iU', $v, $name);
			preg_match('/<div\s+class=\"info\">\s+<b class=\"(.*)\"><\/b><span>/', $v, $sex);
			preg_match('/<span>(.*)<\/span>/', $v, $city);
			$result[$k]['uid'] = $uid[1];
			$result[$k]['name'] = $name[1];
			$result[$k]['sex'] = $sex[1] == 'male' ? '1' : '2';
			$result[$k]['city'] = $city[1];
			$result[$k]['location'] = $locations[1];
		}
		return $result;
	}

	function addweibo($text){

		$add_weibo = array(
			'_surl' => '',
			'_t' => '0',
			'hottopicid' => '',
			'location' => 'home',
			'module' => 'stissue',
			'pic_id' => '',
			'rank' => '0',
			'rankid' => '',
			'text' => $text,
		);

		$rand_time = $this->get_js_timestamp();
		$result = $this->curl_request('http://weibo.com/aj/mblog/add?_wv=5&__rnd='.$rand_time, REQUEST_METHOD_POST, $add_weibo);
		return $result;
	}
}

?>
