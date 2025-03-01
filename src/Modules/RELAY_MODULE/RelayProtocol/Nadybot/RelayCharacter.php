<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Nadybot;

use Nadybot\Core\Routing\Character;

class RelayCharacter extends Character {
	public function __construct(
		string $name,
		?int $id=null,
		?int $dimension=null,
		public ?string $main=null,
	) {
		parent::__construct(name: $name, id: $id, dimension: $dimension);
	}
}
