<?php declare(strict_types=1);

namespace Nadybot\Core;

use InvalidArgumentException;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Route;
use Nadybot\Core\Routing\RoutableEvent;
use Psr\Log\LoggerInterface;
use Throwable;

class MessageRoute {
	/** @var EventModifier[] */
	private array $modifiers = [];

	#[NCA\Logger]
	private LoggerInterface $logger;

	public function __construct(private Route $route) {
		if (!isset($route->id)) {
			throw new InvalidArgumentException(__CLASS__ . '(): Argument #1 ($route) must have an id');
		}
	}

	public function getID(): int {
		assert(isset($this->route->id));
		return $this->route->id;
	}

	public function isDisabled(): bool {
		return isset($this->route->disabled_until)
			&& ($this->route->disabled_until) >= time();
	}

	public function getDisabled(): ?int {
		return $this->route->disabled_until;
	}

	public function disable(int $duration): void {
		$this->route->disabled_until = time() + $duration;
	}

	public function unmute(): void {
		$this->route->disabled_until = null;
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
				$this->logger->error('Error when modifying event: ' . $e->getMessage(), ['exception' => $e]);
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
