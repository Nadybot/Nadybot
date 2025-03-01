<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\Attributes\Event;
use Nadybot\Core\Events\SyncEvent;

/** Triggered when deleting a news entry */
#[Event(mask: 'sync(news-delete)')]
class SyncNewsDeleteEvent extends SyncEvent {
	/** @param string $uuid UUID of these news */
	public function __construct(
		public string $uuid,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}
}
