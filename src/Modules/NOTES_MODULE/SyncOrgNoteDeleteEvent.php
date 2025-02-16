<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\SyncEvent;

#[Event(mask: 'sync(orgnote-delete)')]
class SyncOrgNoteDeleteEvent extends SyncEvent {
	/** @param string $uuid UUID of this note */
	public function __construct(
		public string $uuid,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
