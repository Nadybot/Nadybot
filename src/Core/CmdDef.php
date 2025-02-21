<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Types\{AccessLevel, Status};

class CmdDef {
	public function __construct(
		public string $description,
		public AccessLevel $accessLevel=AccessLevel::Mod,
		public ?Status $defaultStatus=null,
		public ?string $help=null,
		/** @var list<string> */
		public array $handlers=[],
		public ?string $parentCommand=null,
	) {
	}
}
