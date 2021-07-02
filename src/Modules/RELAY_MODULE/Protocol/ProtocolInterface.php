<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE\Protocol;

use Nadybot\Core\Relaying\RoutableEvent;

interface ProtocolInterface {
	/**
	 * Render a routable event into a string that we use to send as
	 * data over the transport layers
	 *
	 * @param RoutableEvent $event The event to render
	 * @return string[] Either a rendered string or null if this event is not supported
	 */
	public function render(RoutableEvent $event): array;

	/**
	 * Parse a natively encoded protocol string into a routable event
	 *
	 * @param string $message The string to parse
	 * @return null|RoutableEvent The parsed event or null if not parsable
	 */
	public function parse(string $message): ?RoutableEvent;
}
