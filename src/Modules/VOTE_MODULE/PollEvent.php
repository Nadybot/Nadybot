<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'poll(*)')]
abstract class PollEvent {
	/** @param list<Vote> $votes */
	public function __construct(
		public Poll $poll,
		public array $votes=[],
	) {
	}
}
