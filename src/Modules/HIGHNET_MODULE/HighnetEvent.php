<?php declare(strict_types=1);

namespace Nadybot\Modules\HIGHNET_MODULE;

use Nadybot\Core\Event;

class HighnetEvent extends Event {
	public const EVENT_MASK = 'highnet(*)';

	public function __construct(
		public Message $message,
	) {
		$this->type = 'highnet(' . strtolower($message->channel) . ')';
	}
}
