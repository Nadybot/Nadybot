<?php declare(strict_types=1);

namespace Nadybot\Core\Attributes\Help;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Epilogue {
	public function __construct(
		public string $text,
	) {
	}
}
