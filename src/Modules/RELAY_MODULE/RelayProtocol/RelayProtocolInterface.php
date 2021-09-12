<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\RelayProtocol;

use Nadybot\Core\Routing\RoutableEvent;
use Nadybot\Modules\RELAY_MODULE\RelayMessage;
use Nadybot\Modules\RELAY_MODULE\RelayStackMemberInterface;

interface RelayProtocolInterface extends RelayStackMemberInterface {
	/**
	 * Render a routable event into a string that we use to send as
	 * data over the transport layers
	 *
	 * @param RoutableEvent $event The event to render
	 * @return string[] An array of rendered protocol strings
	 */
	public function send(RoutableEvent $event): array;

	/**
	 * Parse a natively encoded protocol string into a routable event
	 *
	 * @param RelayMessage $message The packets to parse
	 * @return null|RoutableEvent The parsed event or null if not parsable
	 */
	public function receive(RelayMessage $message): ?RoutableEvent;
}
