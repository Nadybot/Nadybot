<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;

/** Settings for the paths the bot uses */
class Paths {
	/**
	 * @param string   $cache   Path to store cache files
	 * @param string   $html    Path to store HTML files
	 * @param string   $data    Path to store data
	 * @param string   $logs    Path for the logs
	 * @param string[] $modules A list of paths where modules are
	 *
	 * @psalm-param list<string> $modules
	 */
	public function __construct(
		public string $cache='./cache/',
		public string $html='./html/',
		public string $data='./data/',
		public string $logs='./logs/',
		#[CastListToType('string')] public array $modules=['./src/Modules', './extras']
	) {
	}
}
