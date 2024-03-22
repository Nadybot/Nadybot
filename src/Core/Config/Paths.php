<?php declare(strict_types=1);

namespace Nadybot\Core\Config;

class Paths {
	/**
	 * @param string   $cache   Path to store cache files
	 * @param string   $html    Path to store HTML files
	 * @param string   $data    Path to store data
	 * @param string   $logs    Path for the logs
	 * @param string[] $modules A list of paths where modules are
	 */
	public function __construct(
		public string $cache='./cache/',
		public string $html='./html/',
		public string $data='./data/',
		public string $logs='./logs/',
		public array $modules=['./src/Modules', './extras']
	) {
	}
}
