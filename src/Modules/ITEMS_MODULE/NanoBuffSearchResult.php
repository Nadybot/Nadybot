<?php declare(strict_types=1);

namespace Nadybot\Modules\ITEMS_MODULE;

class NanoBuffSearchResult extends Buff {
	public function __construct(
		int $id,
		int $nano_id,
		?int $disc_id,
		?int $use_id,
		string $name,
		int $ncu,
		int $nanocost,
		int $school,
		int $strain,
		int $duration,
		int $attack,
		int $recharge,
		int $range,
		int $initskill,
		public int $amount,
		public bool $froob_friendly=false,
		public ?string $use_name=null,
		public ?int $lowid=null,
		public ?int $highid=null,
		public ?int $lowql=null,
		public ?int $low_ncu=null,
		public ?int $low_amount=null,
	) {
		parent::__construct(
			id: $id,
			nano_id: $nano_id,
			disc_id: $disc_id,
			use_id: $use_id,
			name: $name,
			ncu: $ncu,
			nanocost: $nanocost,
			school: $school,
			strain: $strain,
			duration: $duration,
			attack: $attack,
			recharge: $recharge,
			range: $range,
			initskill: $initskill,
		);
	}
}
