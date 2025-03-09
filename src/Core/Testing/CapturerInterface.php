<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use Nadybot\Core\EventManager;

/** An interface used to capture messages sent by the bot */
interface CapturerInterface {
	/** Get the captured output */
	public function getOutput(): string;

	/** Register to capture */
	public function register(EventManager $eventManager): void;

	/** Unregister from capturing */
	public function unregister(EventManager $eventManager): void;
}
