<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE;

use Nadybot\Core\MessageEmitter;
use Nadybot\Core\Routing\Source;

class MassChannel implements MessageEmitter {
	public function __construct(protected string $channel) {
	}

	public function getChannelName(): string {
		return Source::SYSTEM . "(mass-{$this->channel})";
	}
}
