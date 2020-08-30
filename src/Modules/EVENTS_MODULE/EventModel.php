<?php declare(strict_types=1);

namespace Nadybot\Modules\EVENTS_MODULE;

use Nadybot\Core\DBRow;

class EventModel extends DBRow {
	public int $id;
	public int $time_submitted;
	public string $submitter_name;
	public string $event_name;
	public ?int $event_date;
	public ?string $event_desc;
	public ?string $event_attendees;

	public function getAttendees(): array {
		return array_values(array_diff(explode(",", $this->event_attendees ?? ""), [""]));
	}
}
