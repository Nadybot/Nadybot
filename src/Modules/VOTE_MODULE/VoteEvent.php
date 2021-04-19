<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

class VoteEvent extends PollEvent {
	public string $player;
	public string $vote;
	public string $oldVote;
}
