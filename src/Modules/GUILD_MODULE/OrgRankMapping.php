<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Table};
use Nadybot\Core\DBTable;
use Nadybot\Core\Types\AccessLevel;

#[Table(name: 'org_rank_mapping')]
class OrgRankMapping extends DBTable {
	public function __construct(
		#[PK] public AccessLevel $access_level,
		public int $min_rank,
	) {
	}
}
