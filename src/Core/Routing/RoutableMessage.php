<?php declare(strict_types=1);

namespace Nadybot\Core\Routing;

class RoutableMessage extends RoutableEvent {
	public const EVENT_MASK = self::TYPE_MESSAGE;

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
