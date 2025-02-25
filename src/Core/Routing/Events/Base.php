<?php declare(strict_types=1);

namespace Nadybot\Core\Routing\Events;

/** This is a base routable event */
class Base {
	/**
	 * @param string      $type       The event type
	 * @param bool        $renderPath Render the path of this event?
	 * @param string|null $message    The message it carries or `null` if without any
	 */
	public function __construct(
		public string $type,
		public bool $renderPath=true,
		public ?string $message=null,
	) {
	}
}
