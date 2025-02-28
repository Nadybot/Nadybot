<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\{
	Attributes as NCA,
	DBSchema\Route,
	Routing\RoutableEvent,
	Types\EventModifier,
};
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use Throwable;

/** A configured route with its modifiers */
class MessageRoute {
	/**
	 * A list of event modifiers that each messages being routed need to be modified with
	 *
	 * @var list<EventModifier>
	 */
	private array $modifiers = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	public function __construct(private Route $route) {
	}

	/** Get the route's unique identifier */
	public function getID(): UuidInterface {
		return $this->route->id;
	}

	/** Is this route currently disabled? */
	public function isDisabled(): bool {
		return isset($this->route->disabled_until)
			&& ($this->route->disabled_until) >= time();
	}

	/**
	 * Get a UNIX time stamp until which the route is disabled
	 *
	 * @return ?int `null` if the route is not disabled
	 */
	public function getDisabled(): ?int {
		return $this->route->disabled_until;
	}

	/**
	 * Disable this route for a given number of seconds
	 *
	 * @param int $duration Number of seconds to disable this route
	 */
	public function disable(int $duration): void {
		$this->route->disabled_until = time() + $duration;
	}

	/** Re-enable the route */
	public function unmute(): void {
		$this->route->disabled_until = null;
	}

	/** Get the source for this route (from) */
	public function getSource(): string {
		return $this->route->source;
	}

	/** Get the destination for this route (to) */
	public function getDest(): string {
		return $this->route->destination;
	}

	/**
	 * Get a list of all the event modifiers of this route
	 *
	 * @return list<EventModifier>
	 */
	public function getModifiers(): array {
		return $this->modifiers;
	}

	/** Is this a two-way route (routing from from->to and to->from)? */
	public function getTwoWay(): bool {
		return $this->route->two_way;
	}

	/**
	 * Add an event modifier to the back of the list of modifiers
	 *
	 * @return $this
	 */
	public function addModifier(EventModifier $modifier): self {
		$this->modifiers []= $modifier;
		return $this;
	}

	/**
	 * Modify a routable event with the event modifiers of this route
	 *
	 * @return ?RoutableEvent `null` if the event should be dropped, otherwise
	 *                        a new, modified routable event.
	 */
	public function modifyEvent(RoutableEvent $event): ?RoutableEvent {
		$modifiedEvent = clone $event;
		foreach ($this->modifiers as $modifier) {
			try {
				$modifiedEvent = $modifier->modify($modifiedEvent);
			} catch (Throwable $e) {
				$this->logger->error('Error when modifying event: {error}', [
					'error' => $e->getMessage(),
					'exception' => $e,
				]);
				continue;
			}
			if (!isset($modifiedEvent)) {
				return null;
			}
		}
		return $modifiedEvent;
	}

	/**
	 * Render the modifiers so we can display them
	 *
	 * @param bool $asLink Render the modifiers as clickable links for getting
	 *                     their documentation
	 *
	 * @return list<string> A list of the rendered modifiers
	 */
	public function renderModifiers(bool $asLink=false): array {
		$result = [];
		foreach ($this->route->modifiers as $modifier) {
			$result []= $modifier->toString($asLink);
		}
		return $result;
	}
}
