<?php declare(strict_types=1);

namespace Nadybot\Core\Routing;

use Nadybot\Core\Attributes\Event;

/**
 * A routable message is a message that can be routed via the message hub
 */
#[Event(mask: 'message')]
class RoutableMessage extends RoutableEvent {
	/**
	 * @param string         $message       The message in text form
	 * @param list<Source>   $path          The hops this message has travelled so far
	 * @param bool           $routeSilently Whether to route the event
	 *                                      without displaying anything
	 * @param Character|null $char          The character who triggered the event,
	 *                                      or `null` for system events
	 */
	public function __construct(
		string $message,
		array $path=[],
		bool $routeSilently=false,
		?Character $char=null,
	) {
		parent::__construct(
			type: self::TYPE_MESSAGE,
			path: $path,
			routeSilently: $routeSilently,
			data: $message,
			char: $char,
		);
	}

	/** Get the routed message string */
	public function getData(): string {
		return (string)parent::getData();
	}
}
