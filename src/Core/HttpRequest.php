<?php declare(strict_types=1);

namespace Nadybot\Core;

class HttpRequest {

	private string $method;
	private string $uri;
	/** @var array<string,string> */
	private array $extraHeaders  = [];
	/** @var array<string,string|int|array<mixed>> */
	private array $queryParams   = [];
	private ?string $streamScheme = null;
	private ?int $streamPort = null;
	private ?string $streamHost = null;
	private ?string $postData = null;
	/**
	 * @var array<string,string|int>
	 * @psalm-var array{"scheme"?: string, "host"?: string, "port"?: int, "user"?: string, "pass"?: string, "path"?: string, "query"?: string, "fragment"?: string}
	 * @phpstan-var array{"scheme"?: string, "host"?: string, "port"?: int, "user"?: string, "pass"?: string, "path"?: string, "query"?: string, "fragment"?: string}
	 */
	private array $uriComponents = [];

	/** @internal */
	public static ?string $overridePathPrefix = null;

	/**
	 * @param array<string,mixed> $queryParams
	 * @param array<string,string> $extraHeaders
	 */
	public function __construct(string $method, string $uri, array $queryParams, array $extraHeaders, ?string $postData) {
		$this->method = $method;
		$this->uri = $uri;
		$this->queryParams = $queryParams;
		$this->extraHeaders = $extraHeaders;
		$this->postData = $postData;

		$this->parseUri();

		$this->extractStreamScheme();
		$this->extractStreamPort();
		$this->extractStreamHost();
	}

	/**
	 * Parse the URI into its parts
	 *
	 * @return void
	 * @throws InvalidHttpRequest on error
	 */
	private function parseUri(): void {
		$uriComponents = parse_url($this->uri);
		if (!is_array($uriComponents)) {
			throw new InvalidHttpRequest("Invalid URI: '{$this->uri}'");
		}
		$this->uriComponents = $uriComponents;
	}

	/**
	 * Figure out if this is a plain TCP or SSL connection
	 * @return void
	 * @throws InvalidHttpRequest on invalid schema
	 */
	private function extractStreamScheme(): void {
		if (($this->uriComponents['scheme']??null) === 'http') {
			$this->streamScheme = 'tcp';
		} elseif (($this->uriComponents['scheme']??null) === 'https') {
			$this->streamScheme = 'ssl';
		} else {
			throw new InvalidHttpRequest("URI has no valid scheme: '{$this->uri}'");
		}
	}

	/**
	 * Extract the port from the uri and set it
	 *
	 * @return void
	 * @throws InvalidHttpRequest on invalid scheme
	 */
	private function extractStreamPort(): void {
		if (($this->uriComponents['scheme']??null) === 'http') {
			if (isset($this->uriComponents['port'])) {
				$this->streamPort = $this->uriComponents['port'];
			} else {
				$this->streamPort = 80;
			}
		} elseif (($this->uriComponents['scheme']??null) === 'https') {
			if (isset($this->uriComponents['port'])) {
				$this->streamPort = $this->uriComponents['port'];
			} else {
				$this->streamPort = 443;
			}
		} else {
			throw new InvalidHttpRequest("URI has no valid scheme: '{$this->uri}'");
		}
	}

	/**
	 * Extract and set the hostname from the URI
	 *
	 * @return void
	 * @throws InvalidHttpRequest on error
	 */
	private function extractStreamHost(): void {
		if (!isset($this->uriComponents['host'])) {
			throw new InvalidHttpRequest("URI has no host: '{$this->uri}'");
		}
		$this->streamHost = $this->uriComponents['host'];
	}

	public function getHost(): ?string {
		return $this->streamHost;
	}

	public function getPort(): ?int {
		return $this->streamPort;
	}

	public function getScheme(): ?string {
		return $this->streamScheme;
	}

	public function getURI(): string {
		return ($this->uriComponents["scheme"]??"http") . "://".
			($this->uriComponents["host"]??"").
			(($this->uriComponents["port"]??null) ?: "").
			($this->uriComponents["path"]??"").
			(isset($this->uriComponents["query"]) ? "?" . $this->uriComponents["query"] : "");
	}

	public function getData(): string {
		$data = $this->getHeaderData();
		if ($this->method == 'post') {
			$data .= $this->getPostQueryStr();
		}

		return $data;
	}

	private function getHeaderData(): string {
		$path = $this->getRequestPath();
		$data = strtoupper($this->method) . " $path HTTP/1.0\r\n";

		foreach ($this->getHeaders() as $header => $value) {
			$data .= "$header: $value\r\n";
		}

		$data .= "\r\n";
		return $data;
	}

	private function getRequestPath(): string {
		$path     = isset($this->uriComponents['path']) ? $this->uriComponents['path'] : '/';
		$queryStr = isset($this->uriComponents['query']) ? $this->uriComponents['query'] : '';

		if ($this->method == 'get') {
			parse_str($queryStr, $queryArray);
			$queryArray = array_merge($queryArray, $this->queryParams);
			$queryStr = http_build_query($queryArray);
		} elseif ($this->method !== 'post') {
			throw new InvalidHttpRequest("Invalid http method: '{$this->method}'");
		}

		if (self::$overridePathPrefix) {
			$path = self::$overridePathPrefix . $path;
		}

		if (strlen($queryStr)) {
			return "$path?$queryStr";
		}
		return "$path";
	}

	/** @return array<string,?string> */
	private function getHeaders(): array {
		$headers = [];
		$headers['Host'] = $this->streamHost;
		$headers['User-Agent'] = 'Nadybot ' . BotRunner::getVersion();
		if ($this->method == 'post') {
			if ($this->postData) {
				$headers['Content-Length'] = (string)strlen($this->postData);
			} elseif ($this->queryParams) {
				$headers['Content-Type'] = 'application/x-www-form-urlencoded';
				$headers['Content-Length'] = (string)strlen($this->getPostQueryStr());
			}
		}

		$headers = array_merge($headers, $this->extraHeaders);
		return $headers;
	}

	private function getPostQueryStr(): string {
		return $this->postData ?? http_build_query($this->queryParams);
	}
}
