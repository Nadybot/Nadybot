<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Table};
use Nadybot\Core\DBTable;

#[Table(name: 'discord_mapping')]
class DiscordMapping extends DBTable {
	public function __construct(
		#[PK] public string $name,
		#[PK] public string $discord_id,
		public int $created,
		#[\SensitiveParameter] public ?string $token=null,
		public ?int $confirmed=null,
	) {
	}
}
