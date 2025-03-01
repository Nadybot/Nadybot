<?php declare(strict_types=1);

namespace Nadybot\Core\Routing;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\Events\{Event, SyncEvent};
use Nadybot\Core\Routing\Events\Base;
use stdClass;

/** A routable event is an event that can be routed via the message hub */
#[NCA\Event(mask: 'event')]
class RoutableEvent extends Event {
	public const TYPE_MESSAGE = 'message';
	public const TYPE_EVENT = 'event';

	/**
	 * @param string                              $type          The type of the event
	 * @param list<Source>                        $path          The path the event has already
	 *                                                           travelled
	 * @param bool                                $routeSilently Whether to route the event
	 *                                                           without displaying anything
	 * @param null|string|Base|SyncEvent|stdClass $data          The actual event data
	 * @param null|Character                      $char          The character who triggered
	 *                                                           the event, or `null` for
	 *                                                           system events
	 */
	public function __construct(
		string $type,
		public array $path=[],
		public bool $routeSilently=false,
		public null|string|Base|SyncEvent|stdClass $data=null,
		public ?Character $char=null,
	) {
		parent::__construct($type);
	}

	/** Set the character of the event */
	public function setCharacter(Character $char): self {
		$this->char = $char;
		return $this;
	}

	/** Get the character who triggered this event */
	public function getCharacter(): ?Character {
		return $this->char;
	}

	/**
	 * Get the path this event has already travelled
	 *
	 * @return list<Source>
	 */
	public function getPath(): array {
		return $this->path;
	}

	/** Prepend a hop to the front of the event path */
	public function prependPath(Source $source): self {
		array_unshift($this->path, $source);
		return $this;
	}

	/** Append a hop to the event path */
	public function appendPath(Source $source): self {
		$this->path []= $source;
		return $this;
	}

	/** Get the actual event that was routed */
	public function getData(): mixed {
		return $this->data;
	}

	/** Set the event that was routed */
	public function setData(mixed $data): self {
		$this->data = $data;
		return $this;
	}
}
