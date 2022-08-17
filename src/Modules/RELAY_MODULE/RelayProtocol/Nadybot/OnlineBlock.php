<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Nadybot;

use Nadybot\Core\Routing\Source;

class OnlineBlock {
	/** @var Source[] */
	public array $path = [];

	/** @var RelayCharacter[] */
	public array $users = [];
}
