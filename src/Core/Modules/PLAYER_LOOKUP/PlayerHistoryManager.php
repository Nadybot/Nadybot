<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\CacheManager;

/**
 * @Instance
 */
class PlayerHistoryManager {

	/** @Inject */
	public CacheManager $cacheManager;
	
	public function lookup(string $name, int $rk_num): ?PlayerHistory {
		$name = ucfirst(strtolower($name));
		$url = "http://pork.budabot.jkbff.com/pork/history.php?server=$rk_num&name=$name";
		$groupName = "player_history";
		$filename = "$name.$rk_num.history.json";
		$maxCacheAge = 86400;
		$cb = function($data) {
			return $data !== "[]";
		};
		
		$cacheResult = $this->cacheManager->lookup($url, $groupName, $filename, $cb, $maxCacheAge);
		
		if ($cacheResult->success !== true) {
			return null;
		}
		$obj = new PlayerHistory();
		$obj->name = $name;
		$obj->data = json_decode($cacheResult->data);
		return $obj;
	}
}
