<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol\Tyrbot;

class OnlineBlock {
	/** @param User[] $users */
	public function __construct(
		public Source $source,
		public array $users,
	) {
	}
}
