<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\Events\SyncEvent;
use Ramsey\Uuid\Uuid;

class SyncOrgNoteEvent extends SyncEvent {
	public const EVENT_MASK = 'sync(orgnote)';

	/**
	 * @param int    $time Unix timestamp when this was created
	 * @param string $name Name of the character who created the entry
	 * @param string $note Text of this note
	 * @param string $uuid UUID of this note
	 */
	public function __construct(
		public int $time,
		public string $name,
		public string $note,
		public string $uuid,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		$this->type = self::EVENT_MASK;
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}

	public static function fromOrgNote(OrgNote $note): self {
		return new self(
			time: $note->added_on,
			name: $note->added_by,
			note: $note->note,
			uuid: $note->id->toString(),
		);
	}

	public function toOrgNote(): OrgNote {
		$note = new OrgNote(
			added_by: $this->name,
			added_on: $this->time,
			id: Uuid::fromString($this->uuid),
			note: $this->note,
		);
		return $note;
	}
}
