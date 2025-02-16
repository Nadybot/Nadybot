<?php declare(strict_types=1);

namespace Nadybot\Modules\HIGHNET_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Types\EventInterface;

#[NCA\Event(mask: 'highnet(*)')]
class HighnetEvent implements EventInterface {
	public function __construct(
		public Message $message,
	) {
	}

	public function getEvent(): string {
		return 'highnet(' . strtolower($this->message->channel) . ')';
	}
}
