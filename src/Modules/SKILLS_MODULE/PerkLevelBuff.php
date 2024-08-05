<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{Attributes\DB, DBTable};
use Nadybot\Modules\ITEMS_MODULE\Skill;
use Ramsey\Uuid\UuidInterface;

#[DB\Table(name: 'perk_level_buffs', shared: DB\Shared::Yes)]
class PerkLevelBuff extends DBTable {
	#[DB\Ignore]
	public ?Skill $skill=null;

	public function __construct(
		#[DB\PK] public UuidInterface $perk_level_id,
		#[DB\PK] public int $skill_id,
		public int $amount,
	) {
	}
}
