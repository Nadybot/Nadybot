<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Argument {
	/** @var string[] */
	public array $names = [];

	public function __construct(
		string $name,
		string ...$aliases,
	) {
		$this->names = [$name, ...$aliases];
	}
}
