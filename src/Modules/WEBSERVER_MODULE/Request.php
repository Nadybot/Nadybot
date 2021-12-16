<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

class Request {
	public const GET = 'get';
	public const POST = 'post';
	public const HEAD = 'head';
	public const PUT = 'put';
	public const PATCH = 'patch';
	public const DELETE = 'delete';

	/** @var array<string,string> */
	public array $headers = [];
	public ?string $body = null;
	public mixed $decodedBody = null;
	public array $query = [];
	public string $method;
	public string $path;
	public ?string $authenticatedAs = null;
	public float $version;
	public float $replied;
	public ?float $received = null;

	public function getCookies(): array {
		$cookies = [];

		if (!strlen($this->headers['cookie']??'')) {
			return [];
		}
		$parts = explode("; ", $this->headers['cookie']);
		for ($i = 0; $i < count($parts); $i++) {
			$kv = explode("=", $parts[$i], 2);
			if (count($kv) < 2) {
				continue;
			}
			$cookies[$kv[0]] = $kv[1];
		}
		return $cookies;
	}
}
