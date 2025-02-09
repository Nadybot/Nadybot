<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes\DB\MapRead;
use Nadybot\Core\DBRow;
use Nadybot\Core\Types\ImplantSlot;

class SlotClusterType extends DBRow {
	public function __construct(
		#[MapRead([ImplantSlot::class, 'byTypeId'])] public ImplantSlot $slot,
		#[MapRead([ClusterGrade::class, 'fromId'])] public ClusterGrade $grade,
	) {
	}
}
