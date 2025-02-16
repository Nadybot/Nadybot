<?php declare(strict_types=1);

namespace Nadybot\Core\Routing;

use Nadybot\Core\Attributes\Event;

#[Event(mask: 'message')]
class RoutableMessage extends RoutableEvent {
	/** @param list<Source> $path */
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

	public function getData(): string {
		return (string)parent::getData();
	}
}
