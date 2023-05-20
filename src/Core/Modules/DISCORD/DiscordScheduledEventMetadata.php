<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Nadybot\Core\JSONDataModel;

class DiscordScheduledEventMetadata extends JSONDataModel {
	public ?string $location=null;
}
