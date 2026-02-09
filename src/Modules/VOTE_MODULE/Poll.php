<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use function Safe\json_decode;

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

	/**
	 * Get an array with all possible answers
	 *
	 * @return list<string>
	 */
	public function getPossibleAnswers(): array {
		/** @var list<string> */
		$result = [];
		$decoded = json_decode($this->possible_answers, false);
		if (!is_array($decoded)) {
			return $result;
		}
		foreach ($decoded as $value) {
			if (is_string($value)) {
				$result []= $value;
			}
		}
		return $result;
	}
}
