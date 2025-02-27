<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Types\ParamType;

/** The name and specs of a function parameter */
class FunctionParameter {
	/**
	 * @param string      $name        Name of the parameter
	 * @param ParamType   $type        Type of the parameter
	 * @param null|string $description An optional description explaining what the
	 *                                 parameter is used for
	 * @param bool        $required    Whether this parameter is optional or required
	 */
	public function __construct(
		public string $name,
		public ParamType $type,
		public ?string $description=null,
		public bool $required=true,
	) {
	}
}
