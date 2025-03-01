<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'vote(*)')]
abstract class VoteEvent {
	public function __construct(
		public Poll $poll,
		public string $player,
	) {
	}
}
