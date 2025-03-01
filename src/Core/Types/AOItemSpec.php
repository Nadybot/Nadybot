<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This is all the different QLs an item can have */
interface AOItemSpec {
	/** Get the low ID of this item */
	public function getLowID(): int;

	/** Get the high ID of this item */
	public function getHighID(): int;

	/** Get the lowest QL this item can have for the low ID and high ID */
	public function getLowQL(): int;

	/** Get the highest QL this item can have for the low ID and high ID */
	public function getHighQL(): int;

	/** Get the name of the item */
	public function getName(): string;

	/** Get a link to the item in a format that open ins AO */
	public function getLink(?int $ql=null, ?string $text=null): string;

	/** Get this item at a given QL */
	public function atQL(int $ql): AOItem;
}
