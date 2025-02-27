<?php declare(strict_types=1);

namespace Nadybot\Core\Routing;

use Nadybot\Core\{Config\BotConfig, Registry};

/** A full representation of an AO character. With name, ID and dimension */
class Character {
	/** The dimension this character belongs to */
	public int $dimension;

	/**
	 * @param string   $name      Name of the character
	 * @param null|int $id        UID of the character of `null` if unknown/not applicable
	 * @param null|int $dimension The dimension of this character or `null` if unknown
	 */
	public function __construct(
		public string $name,
		public ?int $id=null,
		?int $dimension=null
	) {
		$config = Registry::getInstance(BotConfig::class);
		$dimension ??= $config->main->dimension;
		$this->dimension = $dimension;
	}
}
