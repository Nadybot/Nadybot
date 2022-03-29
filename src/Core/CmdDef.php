<?php declare(strict_types=1);

namespace Nadybot\Core;

class CmdDef {
	public function __construct(
		public string $description,
		public string $accessLevel="mod",
		public ?int $defaultStatus=null,
		public ?string $help=null,
		/** @var string[] */
		public array $handlers=[],
		public ?string $parentCommand=null,
	) {
	}
}
