<?php declare(strict_types=1);

namespace Nadybot\Modules\NADYNET_MODULE;

use Nadybot\Core\Event;

class NadynetEvent extends Event {
	public function __construct(
		public string $type,
		public Message $message,
	) {
	}
}
