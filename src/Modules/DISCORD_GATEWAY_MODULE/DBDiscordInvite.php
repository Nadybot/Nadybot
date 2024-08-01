<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'discord_invite')]
class DBDiscordInvite extends DBTable {
	#[NCA\DB\PK] public UuidInterface $id;

	public function __construct(
		public string $character,
		public string $token,
		public ?int $expires=null,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
