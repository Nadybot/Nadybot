<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\SyncEvent;

class SyncOrgNoteEvent extends SyncEvent {
	public const EVENT_MASK = "sync(orgnote)";

	public string $type = "sync(orgnote)";

	/** Unix timestamp when this was created */
	public int $time;

	/** Name of the character who created the entry */
	public string $name;

	/** Text of these note */
	public string $note;

	/** UUID of this note */
	public string $uuid;

	public static function fromOrgNote(OrgNote $note): self {
		$event = new self();
		$event->time = $note->added_on;
		$event->name = $note->added_by;
		$event->note = $note->note;
		$event->uuid = $note->uuid;
		return $event;
	}

	public function toOrgNote(): OrgNote {
		$note = new OrgNote();
		$note->added_by = $this->name;
		$note->added_on = $this->time;
		$note->uuid = $this->uuid;
		$note->note = $this->note;
		return $note;
	}
}
