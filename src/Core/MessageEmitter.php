<?php declare(strict_types=1);

namespace Nadybot\Core;

interface MessageEmitter {
	/**
	 * Get the name of the channel for which this object
	 * wants to send or receive events
	 */
	public function getChannelName(): string;
}
