<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Nadybot;

class OnlineList {
	public string $type = 'online_list';

	/** @var OnlineBlock[] */
	public array $online = [];
}
