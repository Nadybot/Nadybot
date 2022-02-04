<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\CacheManager;
use Nadybot\Core\CacheResult;
use Nadybot\Core\ModuleInstance;
use Throwable;

#[NCA\Instance]
class PlayerHistoryManager extends ModuleInstance {
	#[NCA\Inject]
	public CacheManager $cacheManager;

	public function asyncLookup(string $name, int $dimension, callable $callback, mixed ...$args): void {
		$name = ucfirst(strtolower($name));
		$url = "https://pork.jkbff.com/pork/history.php?server=$dimension&name=$name";
		$groupName = "player_history";
		$filename = "$name.$dimension.history.json";
		$maxCacheAge = 86400;
		$cb = function(?string $data): bool {
			return isset($data) && $data !== "[]";
		};

		$this->cacheManager->asyncLookup(
			$url,
			$groupName,
			$filename,
			$cb,
			$maxCacheAge,
			false,
			[$this, "handleCacheResult"],
			$name,
			$callback,
			...$args
		);
	}

	public function lookup(string $name, int $dimension): ?PlayerHistory {
		$name = ucfirst(strtolower($name));
		$url = "https://pork.jkbff.com/pork/history.php?server=$dimension&name=$name";
		$groupName = "player_history";
		$filename = "$name.$dimension.history.json";
		$maxCacheAge = 86400;
		$cb = function(?string $data): bool {
			return isset($data) && $data !== "[]";
		};

		$cacheResult = $this->cacheManager->lookup($url, $groupName, $filename, $cb, $maxCacheAge);
		$playerHistory = null;
		$this->handleCacheResult(
			$cacheResult,
			$name,
			function(?PlayerHistory $obj) use (&$playerHistory): void {
				$playerHistory = $obj;
			}
		);
		return $playerHistory;
	}

	/**
	 * @psalm-param callable(?PlayerHistory, mixed...) $callback
	 */
	public function handleCacheResult(?CacheResult $cacheResult, string $name, callable $callback, mixed ...$args): void {
		if (!isset($cacheResult) || $cacheResult->success !== true) {
			$callback(null, ...$args);
			return;
		}
		$obj = new PlayerHistory();
		$obj->name = $name;
		$obj->data = [];
		try {
			$history = \Safe\json_decode($cacheResult->data??"[]", false, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$callback(null, ...$args);
			return;
		}
		foreach ($history as $entry) {
			$historyEntry = new PlayerHistoryData();
			$historyEntry->fromJSON($entry);
			$obj->data []= $historyEntry;
		}
		$callback($obj, ...$args);
	}
}
