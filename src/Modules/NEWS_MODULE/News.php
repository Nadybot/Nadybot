<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\{Attributes as NCA, Util};

class News extends NewNews {
	public string $uuid;

	/**
	 * @param int    $time    Unix timestamp when this was created
	 * @param string $name    Name of the character who created the entry
	 * @param string $news    Text of these news
	 * @param bool   $sticky  Set to true if this is pinned above all unpinned news
	 * @param bool   $deleted Set to true if this is actually deleted
	 * @param string $uuid    The UUID of this news entry
	 * @param ?int   $id      The internal ID of this news entry
	 */
	public function __construct(
		int $time,
		string $name,
		string $news,
		bool $sticky,
		bool $deleted,
		?string $uuid=null,
		#[NCA\DB\AutoInc] public ?int $id=null,
	) {
		parent::__construct(
			time: $time,
			name: $name,
			news: $news,
			sticky: $sticky,
			deleted: $deleted,
		);
		$this->uuid = $uuid ?? Util::createUUID();
	}

	public static function fromNewNews(NewNews $news): self {
		return new self(
			time: $news->time,
			name: $news->name,
			news: $news->news,
			sticky: $news->sticky,
			deleted: $news->deleted,
		);
	}
}
