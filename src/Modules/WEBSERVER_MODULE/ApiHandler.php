<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Closure;

class ApiHandler {
	public array $allowedMethods = [];
	public ?string $accessLevelFrom;
	public ?string $accessLevel;
	public string $path;
	public string $route;
	public Closure $handler;
	public array $args = [];

	public function exec(Request $request, HttpProtocolWrapper $server): ?Response {
		$handler = $this->handler;
		return $handler($request, $server, ...$this->args);
	}
}