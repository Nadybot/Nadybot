<?php declare(strict_types=1);

namespace Nadybot\Core;

class TimerEvent {
	/** @var int $time */
	public int $time = 0;

	/** @var int $delay */
	public int $delay = 0;

	/** @var callable $callback */
	public $callback = null;

	/** @var mixed[] $args */
	public array $args = [];

	public function __construct(int $time, int $delay, callable $callback, array $args) {
		$this->time = $time;
		$this->delay = $delay;
		$this->callback = $callback;
		$this->args = $args;
	}

	/**
	 * Call the registered callback
	 *
	 * @internal
	 */
	public function callCallback(): void {
		($this->callback)(...$this->args);
	}
}
