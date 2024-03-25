<?php declare(strict_types=1);

namespace Nadybot\Modules\EVENTS_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class EventModel extends DBRow {
	public function __construct(
		public int $time_submitted,
		public string $submitter_name,
		public string $event_name,
		public ?int $event_date=null,
		public ?string $event_desc=null,
		public ?string $event_attendees=null,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
	}

	/** @return string[] */
	public function getAttendees(): array {
		return array_values(array_diff(explode(',', $this->event_attendees ?? ''), ['']));
	}
}
