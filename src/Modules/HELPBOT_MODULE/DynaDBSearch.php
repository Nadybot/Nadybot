<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

class DynaDBSearch extends DynaDB {
	public function __construct(
		int $playfield_id,
		string $mob,
		int $min_ql,
		int $max_ql,
		int $x_coord,
		int $y_coord,
		public ?Playfield $pf=null,
	) {
		parent::__construct(
			playfield_id: $playfield_id,
			mob: $mob,
			min_ql: $min_ql,
			max_ql: $max_ql,
			x_coord: $x_coord,
			y_coord: $y_coord,
		);
	}
}
