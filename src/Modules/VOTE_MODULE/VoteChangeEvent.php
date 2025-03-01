<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'vote(change)')]
class VoteChangeEvent extends VoteEvent {
	public function __construct(
		Poll $poll,
		string $player,
		public string $vote,
		public string $oldVote,
	) {
		parent::__construct(poll: $poll, player: $player);
	}
}
