<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Poll extends DBRow {
	public int $id;
	public string $author;
	public string $question;
	public string $possible_answers;
	public bool $allow_other_answers=true;

	/** @var string[] */
	#[NCA\DB\Ignore]
	public array $answers = [];
	public int $started;
	public int $duration;
	public int $status;

	public function getTimeLeft(): int {
		return $this->started + $this->duration - time();
	}
}
