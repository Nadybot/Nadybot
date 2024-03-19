<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\SyncEvent;

class SyncNewsDeleteEvent extends SyncEvent {
	public const EVENT_MASK = "sync(news-delete)";

	public string $type = "sync(news-delete)";

	/** UUID of these news */
	public string $uuid;
}
