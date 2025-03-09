<?php declare(strict_types=1);

namespace Nadybot\Core\Testing;

use Nadybot\Core\EventManager;

/** An interface used to capture messages sent by the bot */
interface CapturerInterface {
	/** Get the captured output */
	public function getOutput(): string;

	/** Register this capturer to listen to the events that we want to capture */
	public function register(EventManager $eventManager): void;

	/** Unregister tjhis capturer from listening to events */
	public function unregister(EventManager $eventManager): void;
}
