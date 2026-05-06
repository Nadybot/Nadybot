<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE;

use Closure;

class ExposedFunction {
	/**
	 * @param string            $name       Name of function
	 * @param Closure           $function   Closure to execute the  function
	 * @param array<string,int> $paramOrder Mapping parameter name => parameter position
	 */
	public function __construct(
		public string $name,
		public Closure $function,
		public array $paramOrder=[]
	) {
	}
}
