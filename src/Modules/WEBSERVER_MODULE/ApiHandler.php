<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Amp\Http\Server\{Request, Response};
use Closure;
use Exception;
use Nadybot\Core\Types\AccessLevel;
use ReflectionMethod;
use Throwable;

class ApiHandler {
	/**
	 * @param list<string>                        $allowedMethods
	 * @param ?Closure(Request,mixed...):Response $handler
	 * @param list<mixed>                         $args
	 */
	public function __construct(
		public ?string $accessLevelFrom,
		public ?AccessLevel $accessLevel,
		public string $path,
		public string $route,
		public ReflectionMethod $reflectionMethod,
		public array $allowedMethods=[],
		public ?Closure $handler=null,
		public array $args=[],
	) {
	}

	public function exec(Request $request): Response {
		$handler = $this->handler;
		if (!isset($handler)) {
			throw new Exception('Invalid request');
		}
		try {
			return $handler($request, ...$this->args);
		} catch (Throwable $e) {
			throw $e;
		}
	}
}
