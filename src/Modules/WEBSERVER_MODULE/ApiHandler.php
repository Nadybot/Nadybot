<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Amp\Http\Server\{Request, Response};
use Closure;
use Exception;
use ReflectionMethod;
use Throwable;

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

	public function exec(Request $request): ?Response {
		$handler = $this->handler;
		if (!isset($handler)) {
			throw new Exception("Invalid request");
		}
		try {
			return $handler($request, ...$this->args);
		} catch (Throwable $e) {
			var_dump($e->getMessage());
			throw $e;
		}
	}
}
