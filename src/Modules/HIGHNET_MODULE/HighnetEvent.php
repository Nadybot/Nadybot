<?php declare(strict_types=1);

namespace Nadybot\Modules\HIGHNET_MODULE;

use Nadybot\Core\Event;

class HighnetEvent extends Event {
	public function __construct(
		public string $type,
		public Message $message,
	) {
	}
}
