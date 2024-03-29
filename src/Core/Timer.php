<?php declare(strict_types=1);

namespace Nadybot\Core;

use Nadybot\Core\Attributes as NCA;

#[NCA\Instance]
class Timer {
	/**
	 * Array of waiting timer events.
	 *
	 * @internal
	 *
	 * @var \Nadybot\Core\TimerEvent[]
	 */
	private array $timerEvents = [];

	/** Execute all timer events that are due now */
	public function executeTimerEvents(): void {
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
	 *
	 * $this->util->callLater(5, fn($message) => print $message, 'Hello World');
	 *
	 * Prints 'Hello World' after 5 seconds.
	 *
	 * @deprecated 6.0.3
	 */
	public function callLater(int $delay, callable $callback, mixed ...$additionalArgs): TimerEvent {
		return $this->addTimerEvent($delay, $callback, $additionalArgs);
	}

	/**
	 * Abort an already timed event
	 *
	 * @deprecated 6.0.3
	 */
	public function abortEvent(TimerEvent $event): void {
		$key = array_search($event, $this->timerEvents, true);
		if ($key !== false) {
			unset($this->timerEvents[$key]);
			$this->sortEventsByTime();
		}
	}

	/**
	 * Run an event again with the configured amount of delay
	 *
	 * @deprecated 6.0.3
	 */
	public function restartEvent(TimerEvent $event): void {
		$event->time = intval($event->delay) + time();
		$this->sortEventsByTime();
	}

	/**
	 * Adds a new timer event.
	 * $callback will be called with arguments $args array after $delay seconds.
	 *
	 * @param mixed[] $args
	 */
	private function addTimerEvent(int $delay, callable $callback, array $args): TimerEvent {
		$event = new TimerEvent(time() + $delay, $delay, $callback, $args);
		$this->timerEvents []= $event;
		$this->sortEventsByTime();
		return $event;
	}

	/**
	 * Sort all registered events by their next event time, ascending
	 *
	 * @internal
	 */
	private function sortEventsByTime(): void {
		usort(
			$this->timerEvents,
			fn (TimerEvent $a, TimerEvent $b) => $a->time <=> $b->time
		);
	}
}
