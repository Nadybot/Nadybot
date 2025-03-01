<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Events\Event;
use Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\Payload;

#[NCA\Event(mask: 'discord(*)')]
class DiscordGatewayEvent extends Event {
	public function __construct(
		public Payload $payload,
		string $type,
		public ?string $message=null,
	) {
		parent::__construct(type: $type);
	}
}
