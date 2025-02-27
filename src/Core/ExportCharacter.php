<?php declare(strict_types=1);

namespace Nadybot\Core;

use EventSauce\ObjectHydrator\DoNotSerialize;
use InvalidArgumentException;
use Nadybot\Core\Attributes\Hydrator\{Min, StrLength};

/** A character, as used by the importer/exporter module */
class ExportCharacter {
	/**
	 * @param ?string $name The name of this character
	 * @param ?int    $id   The user Id of this character
	 */
	public function __construct(
		#[StrLength(min: 4, max: 12)] public ?string $name=null,
		#[Min(1)] public ?int $id=null,
	) {
		if (!isset($id) && isset($name)) {
			$this->id = $id = Registry::getInstance(Nadybot::class)->getUid($name);
		}
		if (!isset($name) && !isset($id)) {
			throw new InvalidArgumentException('A character consists of at least a UID or a name');
		}
	}

	/** Try to get this character's name, return `null` if not possible */
	#[DoNotSerialize]
	public function tryGetName(): ?string {
		if (isset($this->name)) {
			return $this->name;
		}
		if (!isset($this->id)) {
			return null;
		}
		$chatBot = Registry::getInstance(Nadybot::class);
		return $chatBot->getName($this->id);
	}

	/** Try to get this character's UID, return `null` if not possible */
	#[DoNotSerialize]
	public function tryGetID(): ?int {
		if (isset($this->id)) {
			return $this->id;
		}
		if (!isset($this->name)) {
			return null;
		}
		$chatBot = Registry::getInstance(Nadybot::class);
		return $chatBot->getUid($this->name);
	}
}
