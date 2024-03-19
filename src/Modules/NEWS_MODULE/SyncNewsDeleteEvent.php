<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\SyncEvent;

class SyncNewsDeleteEvent extends SyncEvent {
	public const EVENT_MASK = "sync(news-delete)";

	/** @param string $uuid UUID of these news */
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
