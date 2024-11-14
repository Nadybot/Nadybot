<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Nadybot\Core\DBSchema\Player;

class RankGroup {
	/**
	 * @param list<Player> $online
	 * @param list<Player> $offline
	 */
	public function __construct(
		public int $total=0,
		public array $online=[],
		public array $offline=[],
	) {
	}
}
