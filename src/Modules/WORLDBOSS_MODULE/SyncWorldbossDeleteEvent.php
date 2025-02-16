<?php declare(strict_types=1);

namespace Nadybot\Modules\WORLDBOSS_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\SyncEvent;

#[Event(mask: 'sync(worldboss-delete)')]
class SyncWorldbossDeleteEvent extends SyncEvent {
	/**
	 * @param string $boss   For which worldboss: tara, reaper, loren, gauntlet
	 * @param string $sender Name of the person reporting the deletion
	 */
	public function __construct(
		public string $boss,
		public string $sender,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
