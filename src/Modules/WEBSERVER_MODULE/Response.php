<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use DateTime;

class Response {
	public const CONTINUE = 100;
	public const SWITCHING_PROTOCOLS = 101;
	public const PROCESSING = 102;
	public const EARLY_HINTS = 103;
	public const OK = 200;
	public const CREATED = 201;
	public const ACCEPTED = 202;
	public const NON_AUTHORITATIVE_INFORMATION = 203;
	public const NO_CONTENT = 204;
	public const RESET_CONTENT = 205;
	public const PARTIAL_CONTENT = 206;
	public const MULTI_STATUS = 207;
	public const ALREADY_REPORTED = 208;
	public const IM_USED = 226;
	public const MULTIPLE_CHOICES = 300;
	public const MOVED_PERMANENTLY = 301;
	public const FOUND = 302;
	public const SEE_OTHER = 303;
	public const NOT_MODIFIED = 304;
	public const USE_PROXY = 305;
	public const SWITCH_PROXY = 306;
	public const TEMPORARY_REDIRECT = 307;
	public const PERMANENT_REDIRECT = 308;
	public const BAD_REQUEST = 400;
	public const UNAUTHORIZED = 401;
	public const PAYMENT_REQUIRED = 402;
	public const FORBIDDEN = 403;
	public const NOT_FOUND = 404;
	public const METHOD_NOT_ALLOWED = 405;
	public const NOT_ACCEPTABLE = 406;
	public const PROXY_AUTHENTICATION_REQUIRED = 407;
	public const REQUEST_TIMEOUT = 408;
	public const CONFLICT = 409;
	public const GONE = 410;
	public const LENGTH_REQUIRED = 411;
	public const PRECONDITION_FAILED = 412;
	public const PAYLOAD_TOO_LARGE = 413;
	public const URI_TOO_LONG = 414;
	public const UNSUPPORTED_MEDIA_TYPE = 415;
	public const RANGE_NOT_SATISFIABLE = 416;
	public const EXPECTATION_FAILED = 417;
	public const IM_A_TEAPOT = 418;
	public const MISDIRECTED_REQUEST = 421;
	public const UNPROCESSABLE_ENTITY = 422;
	public const LOCKED = 423;
	public const FAILED_DEPENDENCY = 424;
	public const TOO_EARLY = 425;
	public const UPGRADE_REQUIRED = 426;
	public const PRECONDITION_REQUIRED = 428;
	public const TOO_MANY_REQUESTS = 429;
	public const REQUEST_HEADER_FIELDS_TOO_LARGE = 431;
	public const UNAVAILABLE_FOR_LEGAL_REASONS = 451;
	public const INTERNAL_SERVER_ERROR = 500;
	public const NOT_IMPLEMENTED = 501;
	public const BAD_GATEWAY = 502;
	public const SERVICE_UNAVAILABLE = 503;
	public const GATEWAY_TIMEOUT = 504;
	public const HTTP_VERSION_NOT_SUPPORTED = 505;
	public const VARIANT_ALSO_NEGOTIATES = 506;
	public const INSUFFICIENT_STORAGE = 507;
	public const LOOP_DETECTED = 508;
	public const NOT_EXTENDED = 510;
	public const NETWORK_AUTHENTICATION_REQUIRED = 511;

	public const DEFAULT_RESPONSE_TEXT = [
		self::CONTINUE => 'Continue',
		self::SWITCHING_PROTOCOLS => 'Switching Protocols',
		self::PROCESSING => 'Processing',
		self::EARLY_HINTS => 'Early Hints',
		self::OK => 'OK',
		self::CREATED => 'Created',
		self::ACCEPTED => 'Accepted',
		self::NON_AUTHORITATIVE_INFORMATION => 'Non-Authoritative Information',
		self::NO_CONTENT => 'No Content',
		self::RESET_CONTENT => 'Reset Content',
		self::PARTIAL_CONTENT => 'Partial Content',
		self::MULTI_STATUS => 'Multi-Status',
		self::ALREADY_REPORTED => 'Already Reported',
		self::IM_USED => 'IM Used',
		self::MULTIPLE_CHOICES => 'Multiple Choices',
		self::MOVED_PERMANENTLY => 'Moved Permanently',
		self::FOUND => 'Found',
		self::SEE_OTHER => 'See Other',
		self::NOT_MODIFIED => 'Not Modified',
		self::USE_PROXY => 'Use Proxy',
		self::SWITCH_PROXY => 'Switch Proxy',
		self::TEMPORARY_REDIRECT => 'Temporary Redirect',
		self::PERMANENT_REDIRECT => 'Permanent Redirect',
		self::BAD_REQUEST => 'Bad Request',
		self::UNAUTHORIZED => 'Unauthorized',
		self::PAYMENT_REQUIRED => 'Payment Required',
		self::FORBIDDEN => 'Forbidden',
		self::NOT_FOUND => 'Not Found',
		self::METHOD_NOT_ALLOWED => 'Method Not Allowed',
		self::NOT_ACCEPTABLE => 'Not Acceptable',
		self::PROXY_AUTHENTICATION_REQUIRED => 'Proxy Authentication Required',
		self::REQUEST_TIMEOUT => 'Request Timeout',
		self::CONFLICT => 'Conflict',
		self::GONE => 'Gone',
		self::LENGTH_REQUIRED => 'Length Required',
		self::PRECONDITION_FAILED => 'Precondition Failed',
		self::PAYLOAD_TOO_LARGE => 'Payload Too Large',
		self::URI_TOO_LONG => 'URI Too Long',
		self::UNSUPPORTED_MEDIA_TYPE => 'Unsupported Media Type',
		self::RANGE_NOT_SATISFIABLE => 'Range Not Satisfiable',
		self::EXPECTATION_FAILED => 'Expectation Failed',
		self::IM_A_TEAPOT => 'I\'m a Teapot',
		self::MISDIRECTED_REQUEST => 'Misdirected Request',
		self::UNPROCESSABLE_ENTITY => 'Unprocessable Entity',
		self::LOCKED => 'Locked',
		self::FAILED_DEPENDENCY => 'Failed Dependency',
		self::TOO_EARLY => 'Too Early',
		self::UPGRADE_REQUIRED => 'Upgrade Required',
		self::PRECONDITION_REQUIRED => 'Precondition Required',
		self::TOO_MANY_REQUESTS => 'Too Many Requests',
		self::REQUEST_HEADER_FIELDS_TOO_LARGE => 'Request Header Fields Too Large',
		self::UNAVAILABLE_FOR_LEGAL_REASONS => 'Unavailable For Legal Reasons',
		self::INTERNAL_SERVER_ERROR => 'Internal Server Error',
		self::NOT_IMPLEMENTED => 'Not Implemented',
		self::BAD_GATEWAY => 'Bad Gateway',
		self::SERVICE_UNAVAILABLE => 'Service Unavailable',
		self::GATEWAY_TIMEOUT => 'Gateway Timeout',
		self::HTTP_VERSION_NOT_SUPPORTED => 'HTTP Version Not Supported',
		self::VARIANT_ALSO_NEGOTIATES => 'Variant Also Negotiates',
		self::INSUFFICIENT_STORAGE => 'Insufficient Storage',
		self::LOOP_DETECTED => 'Loop Detected',
		self::NOT_EXTENDED => 'Not Extended',
		self::NETWORK_AUTHENTICATION_REQUIRED => 'Network Authentication Required',
	];

	public int $code;
	public string $codeString;
	/** @var array<string,null|string> */
	public array $headers = [];
	public ?string $body;

	/**
	 * @param array<string,null|string> $headers
	 */
	public function __construct(int $code=200, array $headers=[], ?string $body=null) {
		$this->code = $code;
		$codeString = static::DEFAULT_RESPONSE_TEXT[$this->code] ?? "Unknown";
		$this->codeString = $codeString;
		$this->body = $body;
		$date = (new DateTime())->format(DateTime::RFC7231);
		if (is_string($date)) {
			$this->headers['Date'] = $date;
		}
		$this->headers = array_merge($this->headers, $headers);
	}

	public function toString(Request $request): string {
		if (
			$this->code >= 400
			&& $this->code < 500
			&& !isset($this->body)
			&& (!isset($request->method) || $request->method !== $request::HEAD)
		) {
			$this->body = "<!DOCTYPE html>".
				"<html>".
					"<head>".
						"<title>{$this->codeString}</title>".
					"</head>".
					"<body>".
						"<h1>".
							"{$this->code} {$this->codeString}".
						"</h1>".
					"</body>".
				"</html>";
		}
		if (isset($this->body) && !isset($this->headers["Content-Type"])) {
			$this->headers["Content-Type"] = "text/html; charset=utf-8";
		}
		if (isset($this->body) || in_array($this->code, [static::OK, static::CREATED])) {
			if (!array_key_exists('Content-Length', $this->headers)) {
				$this->headers["Content-Length"] = (string)strlen($this->body??"");
			}
		}
		$headers = "";
		foreach ($this->headers as $key => $value) {
			if ($value !== null) {
				$headers .= "$key: $value\r\n";
			}
		}
		$response = "HTTP/1.1 {$this->code} {$this->codeString}\r\n".
			"Server: Nadybot\r\n".
			$headers . "\r\n";
		if (isset($this->body)) {
			$response .= $this->body;
		}
		return $response;
	}

	public function setCode(int $code): self {
		$this->code = $code;
		if (isset(static::DEFAULT_RESPONSE_TEXT[$code])) {
			$this->codeString = static::DEFAULT_RESPONSE_TEXT[$code];
		}
		return $this;
	}
}
