<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Nadybot\Core\JSONDataModel;

class DiscordEmbedField extends JSONDataModel {
	public string $name;
	public string $value;
}