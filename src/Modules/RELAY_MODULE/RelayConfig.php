<?php declare(strict_types=1);

namespace Nadybot\Modules\RELAY_MODULE;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\{Attributes as NCA, DBTable};
use Ramsey\Uuid\{Uuid, UuidInterface};

#[NCA\DB\Table(name: 'relay')]
class RelayConfig extends DBTable {
	#[NCA\JSON\Ignore] #[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string         $name   The name of this relay
	 * @param ?UuidInterface $id     The unique ID of this relay config
	 * @param RelayLayer[]   $layers The individual layers that make up this relay
	 * @param RelayEvent[]   $events A list of events this relay allows in- and/or outbound
	 *
	 * @psalm-param list<RelayLayer> $layers
	 * @psalm-param list<RelayEvent> $events
	 */
	public function __construct(
		public string $name,
		?UuidInterface $id=null,
		#[
			NCA\DB\Ignore,
			CastListToType(RelayLayer::class)
		] public array $layers=[],
		#[
			NCA\DB\Ignore,
			CastListToType(RelayEvent::class)
		] public array $events=[],
	) {
		$this->id = $id ?? Uuid::uuid7();
	}

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
