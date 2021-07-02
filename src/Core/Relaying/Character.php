<?php declare(strict_types=1);

namespace Nadybot\Core\Relaying;

class Character {
	public ?int $id = null;

	public string $name;

	public function __construct(string $name, ?int $id=null) {
		$this->name = $name;
		$this->id = $id;
	}
}
