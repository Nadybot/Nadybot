<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Event;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\Payload;

class DiscordGatewayEvent extends Event {
	public const EVENT_MASK = 'discord(*)';

	public function __construct(
		public Payload $payload,
		string $type,
		public ?string $message=null,
	) {
		$this->type = $type;
	}
}
