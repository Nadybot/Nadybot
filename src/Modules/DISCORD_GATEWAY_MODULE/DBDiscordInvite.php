<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class DBDiscordInvite extends DBRow {
	public function __construct(
		public string $character,
		public string $token,
		public ?int $expires=null,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}
}
