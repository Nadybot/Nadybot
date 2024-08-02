<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\DB\Table(name: 'news', shared: NCA\DB\Shared::Yes)]
class News extends DBTable {
	/** The internal ID of this news entry */
	#[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param int            $time    Unix timestamp when this was created
	 * @param string         $name    Name of the character who created the entry
	 * @param string         $news    Text of these news
	 * @param bool           $sticky  Set to true if this is pinned above all unpinned news
	 * @param bool           $deleted Set to true if this is actually deleted
	 * @param ?UuidInterface $id      The internal ID of this news entry
	 */
	public function __construct(
		public int $time,
		public string $name,
		public string $news,
		public bool $sticky,
		public bool $deleted,
		?UuidInterface $id=null,
	) {
		$this->id = $id ?? Uuid::uuid7((new DateTimeImmutable())->setTimestamp($time));
	}
}
