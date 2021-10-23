<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\MessageEmitter;

class GauntletChannel implements MessageEmitter {
	protected string $event;

	public function __construct(string $event) {
		$this->event = $event;
	}

	public function getChannelName(): string {
		return "spawn(gauntlet-{$this->event})";
	}
}
