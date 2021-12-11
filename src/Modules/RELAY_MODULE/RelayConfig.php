<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use Nadybot\Core\DBRow;

class RelayConfig extends DBRow {
	/**
	 * The unique ID of this relay config
	 * @json:ignore
	 */
	public int $id;

	/** The name of this relay */
	public string $name;

	/**
	 * The individual layers that make up this relay
	 * @db:ignore
	 * @var RelayLayer[]
	 */
	public array $layers = [];

	/**
	 * A list of events this relay allows in- and/or outbound
	 * @db:ignore
	 * @var RelayEvent[]
	 */
	public array $events = [];

	public function getEvent(string $name): ?RelayEvent {
		foreach ($this->events as $event) {
			if ($event->event === $name) {
				return $event;
			}
		}
		return null;
	}

	public function addEvent(RelayEvent $newEvent): void {
		for ($i = 0; $i < count($this->events); $i++) {
			$event = $this->events[$i];
			if ($event->event === $newEvent->event) {
				$this->events[$i] = $newEvent;
				return;
			}
		}
		$this->events []= $newEvent;
	}

	public function deleteEvent(string $name): bool {
		for ($i = 0; $i < count($this->events); $i++) {
			$event = $this->events[$i];
			if ($event->event === $name) {
				unset($this->events[$i]);
				$this->events = array_values($this->events);
				return true;
			}
		}
		return false;
	}
}
