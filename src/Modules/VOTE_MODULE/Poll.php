<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Poll extends DBRow {
	/** @param string[] $answers */
	public function __construct(
		public string $author,
		public string $question,
		public string $possible_answers,
		public int $started,
		public int $duration,
		public int $status,
		#[NCA\DB\AutoInc] public ?int $id=null,
		public bool $allow_other_answers=true,
		#[NCA\DB\Ignore] public array $answers=[],
	) {
	}

	public function getTimeLeft(): int {
		return $this->started + $this->duration - time();
	}
}
