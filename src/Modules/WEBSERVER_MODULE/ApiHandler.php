<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use ReflectionMethod;
use Closure;
use Exception;
use Generator;

class ApiHandler {
	/** @var string[] */
	public array $allowedMethods = [];
	public ?string $accessLevelFrom;
	public ?string $accessLevel;
	public string $path;
	public string $route;
	/** @psalm-var null|Closure(Request,HttpProtocolWrapper,mixed...) */
	public ?Closure $handler = null;
	public ReflectionMethod $reflectionMethod;
	/** @var mixed[] */
	public array $args = [];

	public function exec(Request $request, HttpProtocolWrapper $server): null|Response|Generator {
		$handler = $this->handler;
		if (!isset($handler)) {
			throw new Exception("Invalid request");
		}
		return $handler($request, $server, ...$this->args);
	}
}
