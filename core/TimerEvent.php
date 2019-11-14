<?php

namespace Budabot\Core;

class TimerEvent {
	/** @var int $time */
	public $time = 0;
	/** @var int $delay */
	public $delay = 0;
	/** @var callable $callback */
	public $callback = null;
	/** @var mixed[] $args */
	public $args = array();

	/**
	 * Constructor of the TimerEvent
	 *
	 * @param int $time When to fire the event
	 * @param int $delay Delay between restarts of the event
	 * @param callable $callback Callback to call when the event triggers
	 * @param mixed[] $args Arguments to pass to $callback
	 * @return void
	 */
	public function __construct($time, $delay, $callback, $args) {
		$this->time = $time;
		$this->delay = $delay;
		$this->callback = $callback;
		$this->args = $args;
	}

	/**
	 * Call the registered callback
	 *
	 * @return void
	 * @internal
	 */
	public function callCallback() {
		call_user_func_array($this->callback, $this->args);
	}
}
