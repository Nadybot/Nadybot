<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Types\Status;
use Nadybot\Core\{Attributes as NCA, DBTable};

#[NCA\DB\Table(name: 'eventcfg')]
class EventCfg extends DBTable {
	public function __construct(
		#[NCA\DB\PK] public string $module,
		#[NCA\DB\PK] public string $type,
		#[NCA\DB\PK] public string $file,
		public string $description,
		public int $verify=0,
		public Status $status=Status::Disabled,
		public ?string $help=null,
	) {
	}
}
