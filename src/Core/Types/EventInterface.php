<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/**
 * The instances of this class can have a different event mask than
 * the ones defined with #[Event(mask: <mask>)]
 * Use this interface, if your generic mask has a wildcard in it,
 * but the actual value depends on a property of the object, like
 * the type of package it's given.
 */
interface EventInterface {
	/** Get the name of the event mask for this instance */
	public function getEvent(): string;
}
