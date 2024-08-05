<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'polls')]
class Poll extends DBTable {
	#[NCA\DB\PK] public UuidInterface $id;

	/** @param list<string> $answers */
	public function __construct(
		public string $author,
		public string $question,
		public string $possible_answers,
		public int $started,
		public int $duration,
		public int $status,
		?UuidInterface $id=null,
		public bool $allow_other_answers=true,
		#[NCA\DB\Ignore] public array $answers=[],
	) {
		$this->id = $id ?? Uuid::uuid7();
	}

	public function getTimeLeft(): int {
		return $this->started + $this->duration - time();
	}
}
