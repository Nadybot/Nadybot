<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\Attributes\DB\{PK, Table};
use Nadybot\Core\DBTable;
use Ramsey\Uuid\UuidInterface;

#[Table(name: 'votes')]
class Vote extends DBTable {
	public function __construct(
		#[PK] public UuidInterface $poll_id,
		#[PK] public string $author,
		public ?string $answer=null,
		public ?int $time=null,
	) {
	}
}
