<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

use Nadybot\Core\StringableTrait;

class ToolFunction extends Tool {
	use StringableTrait;

	public function __construct(
		public readonly FunctionSignature $function,
	) {
		parent::__construct('function');
	}
}
