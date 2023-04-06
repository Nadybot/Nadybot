<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use ArrayAccess;
use Exception;
use Iterator;
use Nadybot\Modules\HELPBOT_MODULE\Playfield;
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;

/**
 * @implements ArrayAccess<int,SiteUpdate>
 * @implements Iterator<int,SiteUpdate>
 */
class PlayfieldState implements ArrayAccess, Iterator {
	public int $id;
	public string $shortName;
	public string $longName;

	/** @var array<int,SiteUpdate> */
	private array $sites=[];

	public function __construct(Playfield $playfield) {
		$this->id = $playfield->id;
		$this->shortName = $playfield->short_name;
		$this->longName = $playfield->long_name;
	}

	public function offsetExists(mixed $offset): bool {
		return array_key_exists($offset, $this->sites);
	}

	public function offsetGet(mixed $offset): ?SiteUpdate {
		return $this->sites[$offset] ?? null;
	}

	public function offsetSet(mixed $offset, mixed $value): void {
		$offset = (int)$offset;
		if (!($value instanceof SiteUpdate)) {
			throw new Exception("Invalid data stored in PlayfieldState");
		}
		$this->sites[$offset] = $value;
	}

	public function offsetUnset(mixed $offset): void {
		unset($this->sites[$offset]);
	}

	public function current(): SiteUpdate|bool {
		return current($this->sites);
	}

	public function rewind(): void {
		reset($this->sites);
	}

	public function key(): ?int {
		return key($this->sites);
	}

	public function next(): void {
		next($this->sites);
	}

	public function valid(): bool {
		return key($this->sites) !== null;
	}

	/** @return array<int,SiteUpdate> */
	public function sorted(): array {
		ksort($this->sites);
		return $this->sites;
	}
}
