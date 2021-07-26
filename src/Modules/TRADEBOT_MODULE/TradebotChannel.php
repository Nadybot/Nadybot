<?php declare(strict_types=1);

namespace Nadybot\Modules\TRADEBOT_MODULE;

use Nadybot\Core\MessageEmitter;

class TradebotChannel implements MessageEmitter {
	protected string $bot;

	public function __construct(string $bot) {
		$this->bot = $bot;
	}

	public function getChannelName(): string {
		return "tradebot({$this->bot})";
	}
}
