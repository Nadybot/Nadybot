<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Countable;
use InvalidArgumentException;
use Iterator;
use Nadybot\Core\Routing\RoutableEvent;

/**
 * @implements Iterator<RoutableEvent>
 */
class MessageQueue implements Iterator, Countable {
	private int $maxLength = 10;

	/** @var RoutableEvent[] */
	private array $msgs = [];

	public function __construct(?int $maxLength=null) {
		if (isset($maxLength)) {
			$this->setMaxLength($maxLength);
		}
	}

	public function setMaxLength(int $maxLength): void {
		if ($maxLength < 0) {
			throw new InvalidArgumentException("max length must be 0 or positive");
		}
		$this->maxLength = $maxLength;
		while (count($this->msgs) > $this->maxLength) {
			array_shift($this->msgs);
		}
	}

	public function enqueue(RoutableEvent $event): void {
		$this->msgs []= $event;
		while (count($this->msgs) > $this->maxLength) {
			array_shift($this->msgs);
		}
	}

	public function count(): int {
		return count($this->msgs);
	}

	public function rewind(): void {
	}

	public function next(): void {
		array_shift($this->msgs);
	}

	public function valid(): bool {
		return count($this->msgs) > 0;
	}

	public function key(): int {
		return 0;
	}

	public function current(): RoutableEvent {
		return $this->msgs[0];
	}
}
