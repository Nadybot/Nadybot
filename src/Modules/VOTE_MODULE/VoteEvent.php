<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

abstract class VoteEvent extends PollEvent {
	public const EVENT_MASK = "vote(*)";

	public string $player;
}
