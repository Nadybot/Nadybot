<?php declare(strict_types=1);

namespace Nadybot\Core\EventModifier;

use Nadybot\Core\{
	Attributes as NCA,
	Routing\Events\Online,
	Routing\Source,
};

#[NCA\EventModifier(name: 'remove-online-messages')]
/**
 * This modifier removes all XXX has joined/left messages
 * coming from the relay
 */
class RemoveOnlineMessages extends RemoveEvent {
	public function __construct() {
		parent::__construct([Online::TYPE], Source::RELAY . '(*)');
	}
}
