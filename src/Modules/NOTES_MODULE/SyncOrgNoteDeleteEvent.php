<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Nadybot\Core\SyncEvent;

class SyncOrgNoteDeleteEvent extends SyncEvent {
	public const EVENT_MASK = 'sync(orgnote-delete)';

	/** @param string $uuid UUID of this note */
	public function __construct(
		public string $uuid,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		$this->type = self::EVENT_MASK;
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
