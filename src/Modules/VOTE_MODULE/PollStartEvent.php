<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

class PollStartEvent extends PollEvent {
	public const EVENT_MASK = 'poll(start)';

	/** @param Vote[] $votes */
	public function __construct(
		public Poll $poll,
		public array $votes=[],
	) {
		$this->type = self::EVENT_MASK;
	}
}
