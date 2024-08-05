<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{Attributes\DB, DBTable};
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;
use Ramsey\Uuid\{Uuid, UuidInterface};

#[DB\Table(name: 'perk_level_actions', shared: DB\Shared::Yes)]
class PerkLevelAction extends DBTable {
	#[DB\PK] public UuidInterface $id;

	public function __construct(
		#[DB\Ignore] public int $perk_level,
		public int $action_id,
		public UuidInterface $perk_level_id,
		public bool $scaling=false,
		#[DB\Ignore] public ?AODBEntry $aodb=null,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7();
	}
}
