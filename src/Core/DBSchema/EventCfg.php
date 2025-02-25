<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Types\Status;
use Nadybot\Core\{Attributes as NCA, DBTable};

/** A single, user-configurable event */
#[NCA\DB\Table(name: 'eventcfg')]
class EventCfg extends DBTable {
	/**
	 * @param string      $module      Name of the module that defines the event
	 * @param string      $type        Type of event (`timer(10secs)`, `package(2)`, `connect`, …)
	 * @param string      $file        The file in which the event is defined
	 * @param string      $description A description what the event is doing
	 * @param int         $verify      Internal status to track if the event is still defined by the bot
	 * @param Status      $status      Is the event enabled or disabled?
	 * @param null|string $help        An optional help text for the event
	 */
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
