<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'poll(del)')]
class PollDelEvent extends PollEvent {
}
