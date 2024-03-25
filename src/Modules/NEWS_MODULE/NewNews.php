<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\DBRow;

class NewNews extends DBRow {
	/**
	 * @param int    $time    Unix timestamp when this was created
	 * @param string $name    Name of the character who created the entry
	 * @param string $news    Text of these news
	 * @param bool   $sticky  Set to true if this is pinned above all unpinned news
	 * @param bool   $deleted Set to true if this is actually deleted
	 */
	public function __construct(
		public int $time,
		public string $name,
		public string $news,
		public bool $sticky,
		public bool $deleted,
	) {
	}
}
