<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\Routing\Events\Online;
use Nadybot\Core\Routing\Source;

/**
 * @EventModifier("remove-online-messages")
 * @Description('This modifier removes all XXX has joined/left messages
 * 	coming from the relay')
 */
class RemoveOnlineMessages extends RemoveEvent {
	public function __construct() {
		parent::__construct([Online::TYPE], Source::RELAY . "(*)");
	}
}
