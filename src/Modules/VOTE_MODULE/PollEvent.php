<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\Event;

class PollEvent extends Event {
	public Poll $poll;
	/** @var Vote[] */
	public array $votes;
}
