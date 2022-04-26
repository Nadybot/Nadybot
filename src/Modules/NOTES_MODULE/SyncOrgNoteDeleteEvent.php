<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\SyncEvent;

class SyncOrgNoteDeleteEvent extends SyncEvent {
	public string $type = "sync(orgnote-delete)";

	/** UUID of this note */
	public string $uuid;
}
