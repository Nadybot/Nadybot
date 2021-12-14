<?php declare(strict_types=1);

namespace Nadybot\Core\Routing;

use Nadybot\Core\Nadybot;
use Nadybot\Core\Registry;

class Character {
	public ?int $id = null;

	public string $name;

	public int $dimension;

	public function __construct(string $name, ?int $id=null, ?int $dimension=null) {
		$this->name = $name;
		$this->id = $id;
		/** @var Nadybot */
		$chatBot = Registry::getInstance(Nadybot::class);
		$dimension ??= (int)$chatBot->vars["dimension"];
		$this->dimension = $dimension;
	}
}
