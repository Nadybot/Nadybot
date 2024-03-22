<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

class VoteDelEvent extends VoteEvent {
	public const EVENT_MASK = 'vote(del)';

	public function __construct(
		public Poll $poll,
		public string $player,
	) {
		$this->type = self::EVENT_MASK;
	}
}
