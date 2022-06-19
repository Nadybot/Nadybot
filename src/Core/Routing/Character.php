<?php declare(strict_types=1);

namespace Nadybot\Core\Routing;

use Nadybot\Core\{ConfigFile, Registry};

class Character {
	public ?int $id = null;

	public string $name;

	public int $dimension;

	public function __construct(string $name, ?int $id=null, ?int $dimension=null) {
		$this->name = $name;
		$this->id = $id;

		/** @var ConfigFile */
		$config = Registry::getInstance(ConfigFile::class);
		$dimension ??= $config->dimension;
		$this->dimension = $dimension;
	}
}
