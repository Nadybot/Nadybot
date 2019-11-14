<?php

namespace Budabot\Core;

/**
 * @Instance
 */
class Timer {
	/**
	 * @internal
	 * Array of waiting timer events.
	 * @var \Budabot\Core\TimerEvent[] $timerEvents
	 */
	private $timerEvents = array();

	/**
	 * Execute all timer events that are due now
	 *
	 * @return void
	 */
	public function executeTimerEvents() {
		// execute timer events
		$time = time();
		while (count($this->timerEvents) > 0 && $this->timerEvents[0]->time <= $time) {
			$timerEvent = array_shift($this->timerEvents);
			$timerEvent->callCallback();
		}
	}

	/**
	 * Calls given callback asynchronously after $delay seconds.
	 *
	 * The callback has following signature:
	 * <code>
	 * function callback(...)
	 * </code>
	 *  * ... - optional values which are same as given as arguments to this method.
	 *
	 * Example usage:
	 * <code>
	 * $this->util->callLater(5, function($message) {
	 *     print $message;
	 * }, 'Hello World');
	 * </code>
	 * Prints 'Hello World' after 5 seconds.
	 *
	 * @param integer  $delay time in seconds to delay the call
	 * @param callback $callback callback which is called after timeout
	 * @param mixed $additionalArgs Any additional parameters are passed to the callback
	 * @return TimerEvent
	 */
	public function callLater($delay, $callback, ...$additionalArgs) {
		return $this->addTimerEvent($delay, $callback, $additionalArgs);
	}

	/**
	 * Abort an already times event
	 *
	 * @param \Budabot\Core\TimerEvent $event The event to remove from the queue
	 * @return void
	 */
	public function abortEvent($event) {
		$key = array_search($event, $this->timerEvents, true);
		if ($key !== false) {
			unset($this->timerEvents[$key]);
			$this->sortEventsByTime();
		}
	}

	/**
	 * Run an event again with the  configured amount of delay
	 *
	 * @param\Budabot\Core\TimerEvent $event
	 * @return void
	 */
	public function restartEvent($event) {
		$event->time = intval($event->delay) + time();
		$this->sortEventsByTime();
	}

	/**
	 * Adds new timer event.
	 *
	 * $callback will be called with arguments $args array after $delay seconds.
	 *
	 * @param int $delay Delay between runs of this event
	 * @param callable $callback Function to call when this event fires
	 * @param mixed[] $args Arguments to pass to your callback function when the event fires
	 * @return \Budabot\Core\TimerEvent
	 */
	private function addTimerEvent($delay, $callback, $args) {
		$event = new TimerEvent(time() + $delay, $delay, $callback, $args);
		$this->timerEvents []= $event;
		$this->sortEventsByTime();
		return $event;
	}

	/**
	 * Sort all registered events by their next event time, ascending
	 *
	 * @return void
	 * @internal
	 */
	private function sortEventsByTime() {
		usort($this->timerEvents, function($a, $b) {
			if ($a->time == $b->time) {
				return 0;
			}
			return ($a->time < $b->time) ? -1 : 1;
		});
	}
}
