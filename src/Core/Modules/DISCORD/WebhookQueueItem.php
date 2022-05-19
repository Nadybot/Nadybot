<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Closure;

class WebhookQueueItem {
	public function __construct(
		public string $applicationId,
		public string $interactionToken,
		public string $message,
		public ?Closure $callback=null,
	) {
	}
}
