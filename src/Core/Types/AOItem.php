<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This is an actual item with all attributes that make up an item */
interface AOItem extends AOItemSpec {
	/** Get the QL of this item */
	public function getQL(): int;

	/** Get the item at a given QL */
	public function setQL(int $ql): self;
}
