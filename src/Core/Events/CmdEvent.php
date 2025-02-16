<?php declare(strict_types=1);

namespace Nadybot\Core\Events;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\CommandHandler;

#[Event(mask: 'command(*)')]
abstract class CmdEvent {
	public function __construct(
		public string $sender,
		public string $channel,
		public string $cmd,
		public ?CommandHandler $cmdHandler=null,
	) {
	}
}
