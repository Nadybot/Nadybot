<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Routing\Events\Online;
use Nadybot\Core\Routing\Source;

#[
	NCA\EventModifier(
		name: "remove-online-messages",
		description:
			"This modifier removes all XXX has joined/left messages\n".
			"coming from the relay"
	)
]
class RemoveOnlineMessages extends RemoveEvent {
	public function __construct() {
		parent::__construct([Online::TYPE], Source::RELAY . "(*)");
	}
}
