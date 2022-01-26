<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\SyncEvent;

class SyncRemnewsEvent extends SyncEvent {
	public string $type = "sync(remnews)";

	/** UUID of these news */
	public string $uuid;
}
