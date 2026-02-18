<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Exception;
use Nadybot\Core\{Attributes as NCA, DBTable, Safe};
use Nadylib\Type;
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

	/**
	 * Get an array with all possible answers
	 *
	 * @return list<string>
	 */
	public function getPossibleAnswers(): array {
		try {
			return Safe::jsonDecode($this->possible_answers, Type\vec(Type\string()));
		} catch (Exception) {
			/** @var list<string> */
			$empty = [];
			return $empty;
		}
	}
}
