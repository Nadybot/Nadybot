<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\DBRow;

class NewNews extends DBRow {
	/** Unix timestamp when this was created */
	public int $time;

	/** Name of the character who created the entry */
	public string $name;

	/** Text of these news */
	public string $news;

	/** Set to true if this is pinned above all unpinned news */
	public bool $sticky;

	/** Set to true if this is actually deleted */
	public bool $deleted;
}
