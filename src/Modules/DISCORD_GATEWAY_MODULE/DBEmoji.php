<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class DBEmoji extends DBRow {
	public function __construct(
		public string $name,
		public string $guild_id,
		public string $emoji_id,
		public int $registered,
		public int $version,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
