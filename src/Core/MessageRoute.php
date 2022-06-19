<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\Routing\RoutableEvent;
use Throwable;

class MessageRoute {
	#[NCA\Logger]
	public LoggerWrapper $logger;

	protected Route $route;

	/** @var EventModifier[] */
	protected array $modifiers = [];

	public function __construct(Route $route) {
		$this->route = $route;
	}

	public function getID(): int {
		return $this->route->id;
	}

	public function getSource(): string {
		return $this->route->source;
	}

	public function getDest(): string {
		return $this->route->destination;
	}

	/** @return EventModifier[] */
	public function getModifiers(): array {
		return $this->modifiers;
	}

	public function getTwoWay(): bool {
		return $this->route->two_way;
	}

	public function addModifier(EventModifier $modifier): self {
		$this->modifiers []= $modifier;
		return $this;
	}

	public function modifyEvent(RoutableEvent $event): ?RoutableEvent {
		$modifiedEvent = clone $event;
		foreach ($this->modifiers as $modifier) {
			try {
				$modifiedEvent = $modifier->modify($modifiedEvent);
			} catch (Throwable $e) {
				$this->logger->error('Error when modifying event: ' . $e->getMessage(), ["exception" => $e]);
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
	 * @return string[]
	 */
	public function renderModifiers(bool $asLink=false): array {
		$result = [];
		foreach ($this->route->modifiers as $modifier) {
			$result []= $modifier->toString($asLink);
		}
		return $result;
	}
}
