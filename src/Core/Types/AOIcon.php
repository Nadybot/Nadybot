<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This interface allows accessing an icon in an interface way */
interface AOIcon {
	/** Get the Icon ID of the item */
	public function getIconID(): int;

	/** Get a URL to the Icon of the item */
	public function getIcon(): string;
}
