<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE;

use Nadybot\Core\MessageEmitter;

class MassChannel implements MessageEmitter {
	public const TYPE = "mass";

	protected string $channel;

	public function __construct(string $channel) {
		$this->channel = $channel;
	}

	public function getChannelName(): string {
		return self::TYPE . "({$this->channel})";
	}
}
