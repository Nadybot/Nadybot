<?php declare(strict_types=1);

namespace Nadybot\Modules\TRICKLE_MODULE;

use Nadybot\Core\DBRow;

class Trickle extends DBRow {
	public function __construct(
		public readonly int $id,
		public readonly int $skill_id,
		public readonly string $groupName,
		public readonly string $name,
		public readonly float $amountAgi,
		public readonly float $amountInt,
		public readonly float $amountPsy,
		public readonly float $amountSta,
		public readonly float $amountStr,
		public readonly float $amountSen,
		public ?float $amount=null,
	) {
	}
}
