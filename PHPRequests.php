<?php
/**
 * @author: flyer103@gmail.com
 * @date: 2015-04-21
 * @desc: 类似 http://docs.python-requests.org/en/latest/ 的 http 工具.
 *
 * @note: 写这个工具的初衷是提供一个灵活的处理 http 请求的工具, 重点在便利获得 http_status_code、添加 header 和 cookie
 *        之类的信息.
 **/


/**
 * 类似 python requests 库发出网络请求后返回的对象.
 * */
class _HTTPResponse {

	public $prior;  // bool, 表征请求的成功或失败
	public $errno;  // int, 错误码
	public $errmsg;  // str, 错误信息
	public $url;  // str, 最后请求的 url
	public $statusCode;  // int, 请求成功时的状态码, 在请求失败时无意义
	public $headers;  // array, 请求成功时的 http header, 在请求失败时为空数组
	public $text;  // str, 请求成功时, 为对应的文本信息, 否则为空字符串

	public function __construct($res=false, $ch=null) {
		$this->prior = $res===false ? false : true;
		$this->errno = curl_errno($ch);
		$this->errmsg = curl_strerror($this->errno);

		$info = curl_getinfo($ch);
		$this->url = $info['url'];
		$this->statusCode = $info['http_code'];

		if ($this->prior) {
			$headerSize = $info['header_size'];
			$rawHeaders = substr($res, 0, $headerSize);
			$this->headers = $this->parseRawHeaders($rawHeaders);
			$this->text = substr($res, $headerSize);
		} else {
			$this->headers = [];
			$this->text = '';
		}
	}

	/**
	 * 获得解析后的 json 数据.
	 *
	 * @return 成功时为 array, 否则为 null
	 * */
	public function json() {
		return json_decode($this->text, true);
	}

	/**
	 * 解析原始的 http header.
	 * */
	private function parseRawHeaders($rawHeaders) {
		$headers = [];

		$header = explode("\r\n", $rawHeaders);
		foreach ($header as $item) {
			$items = explode(': ', $item, 2);
			if (count($items) == 2) {
				$headers[$items[0]] = $items[1];
			}
		}

		return $headers;
	}
}


/**
 * 类似 python requests 库的 http 工具.
 * */
class PHPRequests {
	private static $pool = [];

	private static function init($url, $function_name){
		$url = trim($url);
		if (!preg_match('/^(https?:\/\/[^\/]+)/i', $url, $match)) {
			throw new Exception('Only Http(s) Protocol supported! Url: ' . $url);
		}
		$key = $function_name . '|' . $match[1];

		if(!isset(self::$pool[$key])) {
			//实例过多的时候回收一次
			if(count(self::$pool) > 100) {
				foreach(self::$pool as $_ch) {
					curl_close($_ch);
				}
				self::$pool = [];
			}
			//新建curl session
			self::$pool[$key] = curl_init();
		}
		return self::$pool[$key];
	}

	/**
	 * 返回是哪个函数调用了当前方法
	 * @return string
	 */
	private static function get_called_function() {
		return array_slice(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), 2, 1)[0]['function'];
	}

	/**
	 * 实际的网络请求.
	 *
	 * Note:
	 *   + $tryTimes, int, 表示重试次数
	 *   + $timeWait, int, 表示重试之间的时间间隔
	 *   + $httpCodes, array, 每个元素都为 int, 调用者认为合法的 http 状态码列表
	 *   + 目前只有在网络请求失败及 http 状态码不在用户指定的状态码数据中时才重试
	 * */
	private static function request($url, $timeout=1, $headers=[], $cookies=[], $opts=[], $tryTimes=3, $timeWait=3, $httpCodes=[200]) {
		$ch = self::init($url, self::get_called_function());

		$httpHeaders = [];
		foreach ($headers as $key => $value) {
			$httpHeaders[] = "$key: $value";
		}

		$httpCookies = '';
		foreach ($cookies as $key => $value) {
			$httpCookies .= $httpCookies ? $httpCookies . "; $key=$value" : "$key=$value";
		}

		$opts += [
			CURLOPT_URL => $url,

			CURLOPT_HTTPHEADER => $httpHeaders,
			CURLOPT_COOKIE => $httpCookies,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_ENCODING => '',  // enable gzip
			CURLOPT_HEADER => true,
			CURLOPT_VERBOSE => true,
		];
		if ($timeout < 1) {
			$opts[CURLOPT_TIMEOUT_MS] = $opts[CURLOPT_CONNECTTIMEOUT_MS] = intval($timeout * 1000);
		} else {
			$opts[CURLOPT_TIMEOUT] = $opts[CURLOPT_CONNECTTIMEOUT] = intval($timeout);
		}
		curl_setopt_array($ch, $opts);

		$tryTimes = $tryTimes>0 ? $tryTimes : 1;
		while ($tryTimes > 0) {
			$res = curl_exec($ch);
			$response = new _HTTPResponse($res, $ch);
			if ($response->prior && in_array($response->statusCode, $httpCodes)) break;

			sleep($timeWait);
			$tryTimes -= 1;
		}

		return $response;
	}

	/**
	 * Get the return of $url through HTTP GET Request
	 *
	 * @param $url, str, target url
	 * @param $params, array, GET 的参数
	 * @param $timeout, int|float 单位是秒，1秒以内的用小数表示
	 * @param $headers, array, 自定义的 http headers
	 * @param $cookies, array, 自定义的 cookie
	 * @return mixed, 成功时 _HTTPResponse 对象, 失败时 false
	 */
	public static function get($url, $params=[], $timeout=1, $headers=[], $cookies=[], $tryTimes=3, $timeWait=3, $httpCode=[200]) {
		$opts = [
			CURLOPT_HTTPGET => true,
		];

		if ($params) {
			$urlParams = parse_url($url);
			$queries = http_build_query($params);
			$urlParams['query'] = isset($urlParams['query']) ? $urlParams['query'] . '&' . $queries : $queries;
			$url = build_url($urlParams);
		}

		return self::request($url, $timeout, $headers, $cookies, $opts, $tryTimes, $timeWait, $httpCode);
	}

	/**
	 * Get the return of $url through HTTP POST Request
	 *
	 * @param $url, str, target url
	 * @param $post_data, string|array, POST 的数据
	 * @param $timeout, int|float 单位是秒，1秒以内的用小数表示
	 * @param $headers, array, 自定义的 http headers
	 * @param $cookies, array, 自定义的 cookie
	 * @return mixed, 成功时 _HTTPResponse 对象, 失败时 false
	 */
	public static function post($url, $post_data, $timeout=1, $headers=[], $cookies=[], $tryTimes=3, $timeWait=3, $httpCode=[200]) {
		$opts = [
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $post_data,
		];
		return self::request($url, $timeout, $headers, $cookies, $opts, $tryTimes, $timeWait, $httpCode);
	}
}