<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Amp\Deferred;

class WebhookQueueItem {
	/** @param null|Deferred<void> $deferred */
	public function __construct(
		public string $applicationId,
		public string $interactionToken,
		public string $message,
		public ?Deferred $deferred=null,
	) {
	}
}
