<?php declare(strict_types=1);

namespace Nadybot\Core\Routing;

use Nadybot\Core\{Config\BotConfig, Registry};

class Character {
	public int $dimension;

	public function __construct(
		public string $name,
		public ?int $id=null,
		?int $dimension=null
	) {
		/** @var BotConfig */
		$config = Registry::getInstance(BotConfig::class);
		$dimension ??= $config->main->dimension;
		$this->dimension = $dimension;
	}
}
