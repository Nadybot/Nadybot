<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\{Attributes\DB, DBTable};
use Ramsey\Uuid\UuidInterface;

#[DB\Table(name: 'news_confirmed', shared: DB\Shared::Yes)]
class NewsConfirmed extends DBTable {
	/** @param UuidInterface $id The confirmed news entry */
	public function __construct(
		#[DB\PK] public UuidInterface $id,
		#[DB\PK] public string $player,
		public int $time,
	) {
	}
}
