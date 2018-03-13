<?php

class mozyException extends Exception {

	public function __construct($message, $code = 0, $details = false) {

		parent::__construct($message, $code);
		$this->_details = $details;

	}

	public function getDetails() {return $this->_details;}

}

class mozyHTTPException extends mozyException {}

class MozyREST {

	private $baseHeaders = array('Accept: application/vnd.mozy.bifrost+json;v=1', 'Content-Type: application/json');

	private $token;
	private $tokenExpire;
	protected $details;
	protected $status;
	protected $code;
	private $options;

	public $limit = 1000;
	public $offset = 0;
	public $partnerId;
	public $insecure;

	function __construct($endpoint, $apiKey, $logging = false) {

		$this->endpoint = $endpoint;
		$this->apikey = $apiKey;
		$this->logging = $logging;
		$this->startTime = time();
		//$this->ipAddress =

	}

	/////BEGIN OF PRIVATE///////

	protected function request($method = 'GET') {

		$curl = curl_init();
		$logFile = dirname(__DIR__) . '/logs/' . $this->logging;

		if ($this->logging) {
			$logFirstLine = date('Y-m-d H:i:s') . " - Starting $method Request" . PHP_EOL;
			file_put_contents($logFile, $logFirstLine, FILE_APPEND | LOCK_EX);
			$fp = fopen($logFile, 'a');
			curl_setopt($curl, CURLOPT_VERBOSE, true);
			curl_setopt($curl, CURLOPT_STDERR, $fp);
		}

		switch ($method) {
		case 'POST':
			curl_setopt($curl, CURLOPT_POST, true);
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data = $this->data);
			break;
		case 'DELETE':
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "DELETE");
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data = $this->data);
			break;
		case 'PUT':
			curl_setopt($curl, CURLOPT_CUSTOMREQUEST, "PUT");
			curl_setopt($curl, CURLOPT_POSTFIELDS, $data = $this->data);
			break;
		default:
			$method = 'GET';
			$data = null;
			break;
		}

		if ($this->insecure) {

			curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, 0);
			curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, 0);
		}
		curl_setopt($curl, CURLOPT_HEADER, 1);
		if (isset($this->headers)) {
			curl_setopt($curl, CURLOPT_HTTPHEADER, $this->headers);
		}

		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($curl, CURLOPT_URL, $this->url);

		$response = curl_exec($curl);
		$headersOut = curl_getinfo($curl, CURLINFO_HEADER_OUT);
		$headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
		$curlInfo = curl_getinfo($curl);
		$logLastLine = date('Y-m-d H:i:s') . " - Finished $method Request" . PHP_EOL;

		if ($this->logging) {
			$debug = ob_get_clean();
			file_put_contents($logFile, $debug, FILE_APPEND | LOCK_EX);
			if ($data) {
				if (strlen($this->data) > 1000) {

					file_put_contents($logFile, 'Request Body: ' . strlen($this->data) . PHP_EOL, FILE_APPEND | LOCK_EX);
				} else {

					file_put_contents($logFile, 'Request Body: ' . $this->data . PHP_EOL, FILE_APPEND | LOCK_EX);
				}

			}
			file_put_contents($logFile, 'Response Body: ' . $response . PHP_EOL, FILE_APPEND | LOCK_EX);

			file_put_contents($logFile, $logLastLine, FILE_APPEND | LOCK_EX);
			fclose($fp);
		}
		$x = 0;
		foreach (preg_split("/((\r?\n)|(\r\n?))/", $response) as $line) {

			if ($x == 1) {$html = $line;}

			if (empty($line)) {$x++;}

		}

		$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

		if (isset($html)) {
			if ($this->isJson($html)) {
				$data = (object) json_decode($html, true);
			} else {
				$data = $html;
			}

		}

		$x = 0;

		$headers = $this->get_headers_from_curl_response($response, $headerSize);

		if ($httpcode !== 200 && $httpcode !== 201 && $httpcode !== 204) {

			if (!$data && !$httpcode) {
				throw new mozyException('Unexpected error from HTTP request', '400', curl_error($curl));

			}

			if (is_object($data)) {
				$data = preg_replace('/\(|\)|\- /', '', $data->message);
			}
			if ($this->logging) {

				file_put_contents($logFile, $data, FILE_APPEND | LOCK_EX);
			}

			throw new mozyHTTPException($data, $httpcode, curl_error($curl));

		}

		return (object) array('httpcode' => (int) $httpcode, 'data' => $data, 'headers' => $headers);

	}

	private function get_headers_from_curl_response($headerContent, $headerSize) {

		$headers = [];
		foreach (explode("\r\n", trim(substr($headerContent, 0, $headerSize))) as $row) {
			if (preg_match('/(.*?): (.*)/', $row, $matches)) {
				$headers[$matches[1]] = $matches[2];
			}
		}

		return $headers;
	}

	private function reset($token = false) {
		$this->details = null;
		$this->apiRes = null;
		$this->req = null;
		$this->message = null;
		$this->status = null;
		$this->code = null;
		$this->details = null;
		$this->options = null;
		$this->limit = 1000;
		$this->offset = 0;

		if ($token) {
			$this->token = null;
		}
		return;
	}

	private function isTokenAvailable() {

		if (isset($this->token) && time() < $this->tokenExpire) {
			return true;
		}

	}

	private function isJson($string) {
		json_decode($string);
		return (json_last_error() == JSON_ERROR_NONE);
	}

	private function fetchToken($validate = false) {

		if ($this->isTokenAvailable()) {
			return $this->token;
		}

		$this->url = $this->endpoint . '/auth/exchange';
		$this->headerAPI();
		try {
			$tokenData = $this->request()->data;
			$this->token = $tokenData->token_type . ' ' . $tokenData->token;
			$this->tokenExpire = time() + $tokenData->expires;
			$this->tokenArr = (object) array('type' => $tokenData->token_type, 'token' => $tokenData->token, 'expire' => $this->tokenExpire);

		} catch (mozyException $e) {
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

		if ($validate) {

			try {
				$this->validateToken();

			} catch (mozyException $e) {

				if (preg_match("/^\s*(You are not authorized to access this resource)/", $e->getDetails())) {
					$realIP = file_get_contents("http://ifconfig.co/ip");
					$message = $e->getMessage() . ' - Verify the IP address ' . $realIP . ' is added to the partner API whitelist';
					throw new mozyException($message, $e->getCode(), $e->getDetails());
				} else {
					throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
				}

			}
		}

	}

	private function validateToken() {

		$this->url = $this->endpoint . '/accounts/users?limit=0';
		$this->headerToken();

		try {
			$this->request()->data;

		} catch (mozyHTTPException $e) {
			throw new mozyException('Unable to verify token: ' . $this->token, $e->getCode(), $e->getMessage());
		}

	}

	private function headerAPI() {
		$this->headers = $this->baseHeaders;
		array_push($this->headers, 'Api-Key: ' . $this->apikey);
		if (is_numeric($this->partnerId)) {
			array_push($this->headers, 'X-MozyPartner: ' . $this->partnerId);
		}

	}

	private function headerToken() {
		$this->headers = $this->baseHeaders;
		array_push($this->headers, 'Authorization: ' . $this->token);

	}

	private function doGetAPI() {

		$limitOffset = false;
		try {
			$this->fetchToken();

		} catch (mozyException $e) {

			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());

		}

		$this->headerToken();

		if (!isset($this->includeLimit)) {
			$this->includeLimit = $this->limit;
		}

		if (substr($this->apiRes, -1) == 's' || $this->includeLimit) {
			$limitOffset = "?limit=$this->limit&offset=$this->offset";
			$checkString = strpos($this->apiRes, '?');
			if ($checkString) {
				$limitOffset = "&limit=$this->limit&offset=$this->offset";
			}

		}

		$this->url = $this->endpoint . $this->apiRes . $limitOffset . $this->options;

		try {
			return $this->request();

		} catch (mozyException $e) {

			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());

		}

	}

	private function doPostAPI() {

		try {
			$this->fetchToken();

		} catch (mozyException $e) {

			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());

		}

		$this->headerToken();
		$this->url = $this->endpoint . $this->apiRes;
		try {
			return $this->request('POST');

		} catch (mozyException $e) {
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	private function doDeleteAPI() {

		try {
			$this->fetchToken();

		} catch (mozyException $e) {

			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());

		}

		$this->headerToken();
		$this->url = $this->endpoint . $this->apiRes;
		try {
			return $this->request('DELETE');

		} catch (mozyException $e) {
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	private function doPutAPI() {

		try {
			$this->fetchToken();

		} catch (mozyException $e) {

			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());

		}

		$this->headerToken();
		$this->url = $this->endpoint . $this->apiRes;
		try {
			return $this->request('PUT');

		} catch (mozyException $e) {
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	private function isEmail($email) {

		return filter_var(preg_replace("/[^(a-zA-Z0-9-!#$%&+})@(^a-zA-Z0-9-\.)]+/", "", trim($email)), FILTER_VALIDATE_EMAIL);

	}

	protected function returnResult() {

		$endTime = time();
		$this->timeProcess = $endTime - $this->startTime;

		return (object) array('status' => $this->status, 'message' => $this->message, 'code' => $this->code, 'details' => $this->details, 'extra' => array('time' => $this->timeProcess, 'ip' => trim(shell_exec("dig +short myip.opendns.com @resolver1.opendns.com"))));
	}

	private function returnException($e) {

		error_log($e->getTraceAsString());
		$this->message = $e->getMessage();
		$this->details = $e->getDetails();
		$this->status = false;
		$this->code = $e->getCode();
		return $this->returnResult();
	}

	/////END OF PRIVATE///////

	public function doRequest($url, $headers = false) {
		try {
			$this->url = $url;
			$this->headers = $headers;
			$req = $this->request();
			$this->message = $req;
			$this->status = true;
			$this->code = $req->httpcode;
			return $this->returnResult();
		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
		}

	}

	public function getToken($validate = true) {
		try {
			$req = $this->fetchToken($validate);
			$this->message = $this->token;
			$this->code = 200;
			$this->status = true;
			$this->details = $this->tokenArr;
			return $this->returnResult();
		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
			//print_r($e);
		}

	}

	/////USER API///////

	public function getUserbyUsername($username, $subpart = false) {

		$username = urlencode($this->isEmail($username));
		$this->options = '&q=username:' . '"' . $username . '"';
		if ($subpart) {
			$this->options .= '&scope=include_sub_partners&extend_with=storage';
		} else {
			$this->options .= '&extend_with=storage';
		}

		$this->apiRes = '/accounts/users';

		try {
			$req = $this->doGetAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function getUserbyExternalId($extId, $subpart = false) {

		$extId = urlencode($extId);
		$this->options = '&q=external_id:' . '"' . $extId . '"';
		if ($subpart) {
			$this->options .= '&scope=include_sub_partners&extend_with=storage';
		} else {
			$this->options .= '&extend_with=storage';
		}

		$this->apiRes = '/accounts/users';

		try {
			$req = $this->doGetAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function getUsers($limit = false, $subpart = false, $userId = false) {
		$this->reset();
		if (is_numeric($userId) && $userId !== 0) {
			$this->apiRes = '/accounts/user/' . $userId;
			$this->options = '?extend_with=storage';
		} else {

			if ($subpart) {
				$this->options = '&scope=include_sub_partners&extend_with=storage';
			} else {
				$this->options = '&extend_with=storage';
			}

			if (is_numeric($limit)) {
				$this->limit = $limit;
			}
			$this->apiRes = '/accounts/users';

		}

		try {

			$usersArr = $this->doGetAPI()->data;
			if ($usersArr->partial_result && !is_numeric($limit)) {

				$x = 0;
				$totalUsers = $usersArr->total;
				$cntUsers = $usersArr->count;

				while ($cntUsers >= $this->limit || $last) {

					foreach ($usersArr->items as $key => $userData) {

						$results[$x++] = $userData;

					}

					$this->offset = $this->offset + $this->limit;

					$usersArr = json_decode($this->doGetAPI()->data);
					$cntUsers = $usersArr->count;

					if ($cntUsers < $this->limit && $cntUsers > 0) {
						$last = true;
					} else {
						$last = false;
					}
				}

				$usersArr->items = $results;
			}

			$this->message = $usersArr;
			$this->details = false;
			$this->status = true;
			$this->code = 0;
			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function createNewUser($userEmail, $userName, $groupId, $externalId, $password = false, $type = 'Desktop', $sync = false) {

		//$username = urlencode($this->isEmail($username));
		$this->apiRes = '/accounts/users';
		$userData = array(

			'user_group_id' => (int) $groupId,
			'username' => $userEmail,
			'name' => $userName,
			'external_id' => $externalId,
			'type' => $type,
			'sync' => $sync,

		);

		if ($password) {
			$userData['password'] = $password;
		}

		$this->data = json_encode($userData);

		try {
			//return $this->data;

			$req = $this->doPostAPI();

			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}
	}

	public function deleteUser($userId = null) {

		if ($userId) {
			$this->apiRes = '/accounts/user/' . $userId;
		} else {
			$this->apiRes = '/accounts/users';
		}

		try {
			$req = $this->doDeleteAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function setUserStorage($userId, $storage, $type = 'desktop') {

		$this->apiRes = '/accounts/user/' . $userId . '/storage/' . $type;
		$storageData = array(

			'pool_setting' => 'limited',
			'pool_limit' => array('unit' => 'GB', 'value' => (int) $storage),

		);

		$this->data = json_encode($storageData);

		try {

			$req = $this->doPutAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function sendUserActivationEmail($userId) {

		$this->apiRes = '/emails/deliver';

		$emailData = array(

			'template' => 'new_user_notification',
			'language' => 'en',
			'user_id' => $userId,
		);

		$this->data = json_encode($emailData);

		try {
			$req = $this->doPostAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}
	/////END USER API///////

	/////PARTNER API///////

	public function createNewPartner($partnerName, $companyType, $parentPartnerId, $adminEmail, $adminName, $roleId, $accountType, $address = null, $city = null, $state = null, $country = null, $zipCode = null, $externalId = null, $externalInfo = null, $phone = null, $subdomain = null, $sync = false) {

		//$adminEmail = urlencode($this->isEmail($adminEmail));
		$this->apiRes = '/accounts/partners';

		$partnerData = array(
			'billing_address' => $address,
			'billing_city' => $city,
			'billing_state' => $state,
			'billing_country' => $country,
			'billing_zip' => $zipCode,
			'company_type' => $companyType,
			'external_id' => $externalId,
			'external_info' => $externalInfo,
			'name' => $partnerName,
			'parent_partner_id' => intval($parentPartnerId),
			'phone' => $phone,
			'admin' => array(
				'display_name' => $adminName,
				'external_id' => $externalId,
				'full_name' => $adminName,
				'language' => 'en',
				'username' => $adminEmail,
			),
			'root_role_id' => intval($roleId),
			'subdomain' => $subdomain,
			'sync' => $sync,
			'security_requirement' => 'Standard',
			'account_type' => $accountType,
		);

		unset($partnerData['external_info']);

		$this->data = json_encode($partnerData);

		try {
			//return $this->data;

			$req = $this->doPostAPI();

			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();
			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnException($e);

			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}
	}

	public function getPartners($limit = false, $partnerId = false) {

		if (is_numeric($partnerId) && $partnerId !== 0) {
			$this->apiRes = '/accounts/partner/' . $partnerId;
			$this->options = '?extend_with=storage';
		} else {

			$this->options = '&extend_with=storage';

			if (is_numeric($limit)) {
				$this->limit = $limit;
			}
			$this->apiRes = '/accounts/partners';
		}

		try {

			$partnersArr = $this->doGetAPI()->data;

			if ($partnersArr->partial_result && !is_numeric($limit)) {

				$x = 0;
				$totalPartners = $partnersArr->total;
				$cntPartners = $partnersArr->count;
				//$saveCnt = $partnersArr->count;

				while ($cntPartners >= $this->limit || $last) {

					foreach ($partnersArr->items as $key => $partnerData) {

						$results[$x++] = $partnerData;

					}

					$this->offset = $this->offset + $this->limit;
					//error_log(print_r($this->doGetAPI()->data, true));
					//$partnersArr = json_decode($this->doGetAPI()->data, true);
					$partnersArr = $this->doGetAPI()->data;
					$cntPartners = $partnersArr->count;

					if ($cntPartners < $this->limit && $cntPartners > 0) {
						$last = true;
					} else {
						$last = false;
					}
				}

				$partnersArr->items = $results;
				$partnersArr->total = $totalPartners;
				$partnersArr->count = $cntPartners;
			}

			$this->message = $partnersArr;
			$this->status = true;
			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
			//throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());

		}

	}

	public function getPartnerbyExternalId($extId, $subpart = false) {

		$extId = urlencode($extId);
		$this->options = '&q=external_id:' . '"' . $extId . '"';
		if ($subpart) {
			$this->options .= '&scope=include_sub_partners&extend_with=storage';
		} else {
			$this->options .= '&extend_with=storage';
		}

		$this->apiRes = '/accounts/partners';

		try {
			$req = $this->doGetAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();
			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function getPartnerResources($partnerId, $refresh = false, $limit = false) {
		$refreshUrl = null;
		if ($refresh) {
			$refreshUrl = '?refresh=true&quick_report=false';
		}

		if (is_numeric($limit)) {
			$this->includeLimit = true;
			$this->limit = $limit;
		}

		if (!$partnerId) {

			$partnerId = $this->partnerId;
		}

		$this->apiRes = '/reports/resources/partner/' . $partnerId . $refreshUrl;
		//$extId = urlencode($extId);
		// $this->options = '&q=external_id:' . '"' . $extId . '"';
		// if ($subpart) {
		// 	$this->options .= '&scope=include_sub_partners&extend_with=storage';
		// } else {
		// 	$this->options .= '&extend_with=storage';
		// }

		// $this->apiRes = '/accounts/partners';

		try {
			$req = $this->doGetAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
			//throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());

		}

	}

	public function updatePartnerExternalID($partnerId, $extId) {

		//$extId = urlencode($extId);
		// $this->options = '&q=external_id:' . '"' . $extId . '"';
		// if ($subpart) {
		// 	$this->options .= '&scope=include_sub_partners&extend_with=storage';
		// } else {
		// 	$this->options .= '&extend_with=storage';
		// }

		$data = array(
			'external_id' => $extId,
		);

		$this->data = json_encode($data);

		$this->apiRes = '/accounts/partner/' . $partnerId;

		try {
			$req = $this->doPutAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();
			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function updatePartnerExternalInfo($partnerId, $extIinfo) {

		//$extId = urlencode($extId);
		// $this->options = '&q=external_id:' . '"' . $extId . '"';
		// if ($subpart) {
		// 	$this->options .= '&scope=include_sub_partners&extend_with=storage';
		// } else {
		// 	$this->options .= '&extend_with=storage';
		// }

		$data = array(
			'external_info' => $extIinfo,
		);

		$this->data = json_encode($data);

		$this->apiRes = '/accounts/partner/' . $partnerId;

		try {
			$req = $this->doPutAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();
			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
			//throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function setPartnerStorage($partnerId, $storage, $type = 'desktop') {

		$this->apiRes = '/accounts/partner/' . $partnerId . '/storage/' . $type;
		$storageData = array(

			'pool_setting' => 'assigned',
			'pool_limit' => array('unit' => 'GB', 'value' => (int) $storage),

		);

		$this->data = json_encode($storageData);

		try {

			$req = $this->doPutAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();
			//return $this->returnException($e);
			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function getStorageSummary($partnerId = false) {

		$this->reset();
		if (!$partnerId) {
			$partnerId = $this->partnerId;
		}

		if (!$partnerId) {

			$this->message = 'No Partner ID provided';
			$this->status = false;
			$this->code = 400;

			return $this->returnResult();
		}

		$this->apiRes = '/accounts/partner/' . $partnerId . '/storage';

		try {

			$req = $this->doGetAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();

			//throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function deletePartner($partnerId = null) {

		if ($partnerId) {
			$this->apiRes = '/accounts/partner/' . $partnerId;
		} else {
			$this->apiRes = '/accounts/partners';
		}

		try {
			$req = $this->doDeleteAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();
			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	/////MACHINE API///////

	public function createNewContainer($userId, $containerType, $containerName) {

		$this->apiRes = '/accounts/containers';
		$containerData = array(

			'type' => $containerType,
			'user_id' => $userId,
			'name' => $containerName,
		);

		$this->data = json_encode($containerData);

		try {
			//return $this->data;

			$req = $this->doPostAPI();

			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();
			//return $this->returnException($e);
			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}
	}

	public function getMachine($userId, $machineId) {

		$this->apiRes = '/accounts/machines';
		//' . $machineId;

		if ($userId) {
			$this->apiRes = '/accounts/machines?q=userid:' . $userId;
		}

		try {

			$req = $this->doGetAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function setDeviceLimit($userId, $deviceNumber, $type = 'desktop') {

		$this->apiRes = '/accounts/user/' . $userId . '/device/';
		$deviceData = array(

			'license_type' => $type,
			'limit' => $deviceNumber,

		);

		$this->data = json_encode($deviceData);

		try {

			$req = $this->doPutAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function provisionLicenses($amount, $partnerId, $type = 'desktop') {

		$this->apiRes = '/accounts/licenses';

		$licenseData = array(

			'license_type' => $type,
			'licenses' => (int) $amount,
			'partner_id' => (int) $partnerId,
		);

		$this->data = json_encode($licenseData);

		try {
			$req = $this->doPostAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();
			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function assignFreeLicense($userEmail, $partnerId = false, $userGroupId = false, $limit = 1, $type = 'Desktop') {

		//$username = urlencode($this->isEmail($userEmail));
		$this->apiRes = '/accounts/licenses?license_type=' . $type . '&status=free&limit=' . $limit;
		//partner_id=' . $partnerId . '
		if ($partnerId) {
			$this->apiRes .= '&partner_id=' . $partnerId;
		}
		if ($userGroupId) {
			$this->apiRes .= '&user_group_id=' . $userGroupId;
		}
		$licenseData = array(
			"assigned_email_address" => $userEmail,
		);

		$this->data = json_encode($licenseData);

		try {

			$req = $this->doPutAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();
			//return $this->returnException($e);
			//throw new mozyHTTPException($data['message'], $httpcode);
			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	/////LICENSE API///////

	public function assignLicense($keyString, $userEmail) {
		//$username = urlencode($this->isEmail($userEmail));
		//$userEmail = urlencode($this->isEmail($userEmail));
		$this->apiRes = '/accounts/license/' . $keyString;
		$licenseData = array(
			"assigned_email_address" => $userEmail,
		);

		$this->data = json_encode($licenseData);

		try {

			$req = $this->doPutAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function unassignLicense($keyString) {
		//$username = urlencode($this->isEmail($userEmail));
		//$userEmail = urlencode($this->isEmail($userEmail));
		$this->apiRes = '/accounts/license/' . $keyString;
		$licenseData = array(
			"assigned_email_address" => null,
		);

		$this->data = json_encode($licenseData);

		try {

			$req = $this->doPutAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();
			//return $this->returnException($e);
			//throw new mozyHTTPException($data['message'], $httpcode);
			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function getLicenseSummary($partnerId = false) {

		$this->reset();

		$this->apiRes = '/accounts/licenses/summary';

		if (is_numeric($partnerId)) {

			$this->apiRes = $this->apiRes . '?partner_id=' . $partnerId;
		}

		try {
			$req = $this->doGetAPI();

			if (is_numeric($partnerId)) {

				if (intval($req->data->query['scope']['partner']) !== intval($partnerId)) {

					// $this->message = 'Partner ID from response does not match the partner from the request';
					// $this->status = false;
					// $this->code = $req->httpcode;
					// $this->details = $req->data;

					$this->details = 'Partner ID from response does not match the partner from the request';

					//return $this->returnResult();

				} elseif (intval($req->data->query['scope']['partner']) !== intval($this->partnerId)) {
					// $this->message = 'Partner ID from response does not match the partner from the request';
					// $this->status = false;
					// $this->code = $req->httpcode;
					// $this->details = $req->data;

					$this->details = 'Partner ID from response does not match the partner from the request';

					//return $this->returnResult();
				} else {
					$this->details = null;
				}

			}

			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			$this->details = $e->getDetails();
			//throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
			return $this->returnResult();
		}

	}

	public function getActiveLicenses($type = false, $partnerId = false, $limit = false) {

		$this->reset();
		$this->apiRes = '/accounts/licenses?status=used';
		error_log($this->apiRes);
		if ($type) {
			$this->apiRes .= $this->apiRes . '&license_type=' . $type;
		}

		if ($partnerId) {
			$this->apiRes .= '&partner_id=' . $partnerId;
		}

		if (is_numeric($limit)) {
			$this->includeLimit = true;
			$this->limit = $limit;
		}

		try {
			$req = $this->doGetAPI();
			$licenseArr = $req->data;

			if ($licenseArr->partial_result && !is_numeric($limit)) {
				$this->includeLimit = true;
				$x = 0;
				$totalLicenses = $licenseArr->total;
				$cntLicenses = $licenseArr->count;

				while ($cntLicenses >= $this->limit || $last) {

					foreach ($licenseArr->items as $key => $LicenseData) {

						$results[$x++] = $LicenseData;

					}

					$this->offset = $this->offset + $this->limit;

					$licenseArr = $this->doGetAPI()->data;
					$cntLicenses = $licenseArr->count;

					if ($cntLicenses < $this->limit && $cntLicenses > 0) {
						$last = true;
					} else {
						$last = false;
					}
				}

				$licenseArr->items = $results;
			}

			$this->message = $licenseArr;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function getLicenses($type = 'Desktop', $free = true, $partnerId = false, $limit = false) {

		$this->reset();
		$condition = null;
		if ($free) {
			$condition = '&status=free';
		}

		//$username = urlencode($this->isEmail($userEmail));
		$this->apiRes = '/accounts/licenses?license_type=' . $type . $condition;

		if (!$type) {
			$this->apiRes = '/accounts/licenses';
			if ($partnerId) {
				$this->apiRes = '/accounts/licenses?partner_id=' . $partnerId;
			}
		} else {

			if ($partnerId) {
				$this->apiRes .= '&partner_id=' . $partnerId;
			}
		}

		if (is_numeric($limit)) {
			$this->limit = $limit;
		}

		try {

			$req = $this->doGetAPI();
			$licenseArr = $req->data;

			if ($licenseArr->partial_result) {
				$this->includeLimit = true;
				$x = 0;
				$totalLicenses = $licenseArr->total;
				$cntLicenses = $licenseArr->count;

				while ($cntLicenses >= $this->limit || $last) {

					foreach ($licenseArr->items as $key => $LicenseData) {

						$results[$x++] = $LicenseData;

					}

					$this->offset = $this->offset + $this->limit;

					$licenseArr = $this->doGetAPI()->data;
					$cntLicenses = $licenseArr->count;

					if ($cntLicenses < $this->limit && $cntLicenses > 0) {
						$last = true;
					} else {
						$last = false;
					}
				}

				$licenseArr->items = $results;
			}

			$this->message = $licenseArr;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	public function deleteLicenses($type = 'Desktop', $amount = null) {

		$this->apiRes = '/accounts/licenses';

		if (!$amount) {

			$amount = $this->getLicenses($type)->message->count;

		}

		$licenseData = array(

			'licenses' => (int) $amount,
			'license_type' => $type,
		);

		$this->data = json_encode($licenseData);

		try {
			$req = $this->doDeleteAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;
			//
			return $this->returnResult();

		} catch (mozyException $e) {
			//return 'Message: ' . $e->getMessage();
			throw new mozyException($e->getMessage(), $e->getCode(), $e->getDetails());
		}

	}

	/////ADMIN API///////

	public function getAdmins($adminId = false) {

		if ($this->partnerId) {
			$token = true;
			$this->partnerId = null;
		} else {
			$token = false;
		}
		$this->reset($token);
		if (is_numeric($adminId) && $partnerId !== 0) {
			$this->apiRes = '/accounts/admin/' . $adminId;
		} else {
			$this->apiRes = '/accounts/admins';
		}

		//$this->apiRes = '/accounts/admin/' . $partnerId . '/storage';

		try {

			$req = $this->doGetAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
		}

	}

	public function changeAdminEmail($adminId, $newUsername) {
		$this->reset();
		$this->apiRes = '/accounts/admin/' . $adminId;

		$adminData = array(

			'username' => $newUsername,

		);

		$this->data = json_encode($adminData);

		try {

			$req = $this->doPutAPI();
			$this->message = $req->data;
			$this->status = true;
			$this->code = $req->httpcode;

			return $this->returnResult();

		} catch (mozyException $e) {

			$this->message = $e->getMessage();
			$this->status = false;
			$this->code = $e->getCode();
			return $this->returnResult();
		}

	}
}