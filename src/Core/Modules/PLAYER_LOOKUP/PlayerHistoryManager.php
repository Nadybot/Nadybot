<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\CacheManager;
use Throwable;

/**
 * @Instance
 */
class PlayerHistoryManager {

	/** @Inject */
	public CacheManager $cacheManager;
	
	public function lookup(string $name, int $dimension): ?PlayerHistory {
		$name = ucfirst(strtolower($name));
		$url = "http://pork.budabot.jkbff.com/pork/history.php?server=$dimension&name=$name";
		$groupName = "player_history";
		$filename = "$name.$dimension.history.json";
		$maxCacheAge = 86400;
		$cb = function($data) {
			return isset($data) && $data !== "[]";
		};
		
		$cacheResult = $this->cacheManager->lookup($url, $groupName, $filename, $cb, $maxCacheAge);
		
		if ($cacheResult->success !== true) {
			return null;
		}
		$obj = new PlayerHistory();
		$obj->name = $name;
		$obj->data = [];
		try {
			$history = json_decode($cacheResult->data, false, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			return null;
		}
		foreach ($history as $entry) {
			$historyEntry = new PlayerHistoryData();
			$historyEntry->fromJSON($entry);
			$obj->data []= $historyEntry;
		}
		return $obj;
	}
}
