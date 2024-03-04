<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Revolt\EventLoop\Suspension;

class WebhookQueueItem {
	/** @param null|Suspension<void> $suspension */
	public function __construct(
		public string $applicationId,
		public string $interactionToken,
		public string $message,
		public ?Suspension $suspension=null,
	) {
	}
}
