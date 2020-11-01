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
	public $decodedBody = null;
	public array $query = [];
	public string $method;
	public string $path;
	public ?string $authenticatedAs = null;
	public float $version;
	public float $replied;
	public ?float $received = null;
}
