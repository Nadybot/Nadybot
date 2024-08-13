<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class SlotClusterType extends DBRow {
	public function __construct(
		public string $slot,
		public string $cluster_type,
	) {
	}
}
