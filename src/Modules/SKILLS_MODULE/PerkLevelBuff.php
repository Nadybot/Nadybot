<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\Types\Skill;
use Nadybot\Core\{Attributes\DB, DBTable};
use Ramsey\Uuid\UuidInterface;

#[DB\Table(name: 'perk_level_buffs', shared: DB\Shared::Yes)]
class PerkLevelBuff extends DBTable {
	public function __construct(
		#[DB\PK] public UuidInterface $perk_level_id,
		#[DB\PK, DB\ColName('skill_id')] public Skill $skill,
		public int $amount,
	) {
	}
}
