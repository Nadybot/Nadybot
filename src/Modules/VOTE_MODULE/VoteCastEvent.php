<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

class VoteCastEvent extends VoteEvent {
	public const EVENT_MASK = "vote(cast)";

	public function __construct(
		public Poll $poll,
		public string $player,
		public string $vote,
	) {
		$this->type = self::EVENT_MASK;
	}
}
