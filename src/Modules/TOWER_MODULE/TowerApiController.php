<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\Http;
use Nadybot\Core\HttpResponse;
use Nadybot\Core\SettingManager;
use Throwable;

/**
 * @Instance
 */
class TowerApiController {

	public const TOWER_API = "https://tower-api.jkbff.com/api/towers";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public TowerController $towerController;

	/** @var array<string,ApiCache> */
	protected array $cache = [];

	/**
	 * @Setting("tower_cache_duration")
	 * @Description("How long to cache data from the Tower API")
	 * @Visibility("edit")
	 * @Type("options")
	 * @Options("5 min;10 min;15 min;30 min;1hour")
	 * @Intoptions("300;600;900;1800;3600")
	 * @AccessLevel("mod")
	 */
	public $defaultTowerCacheDuration = 600;

	/**
	 * @Event("timer(5m)")
	 * @Description("Clean API Cache")
	 */
	public function cleanApiCache(): void {
		$keys = array_keys($this->cache);
		foreach ($keys as $key) {
			if (isset($this->cache[$key]) && $this->cache[$key]->validUntil < time()) {
				unset($this->cache[$key]);
			}
		}
	}

	public function call(array $params, callable $callback, ...$args): void {
		$roundTo = $this->settingManager->getInt('tower_cache_duration');
		if (isset($params["min_close_time"])) {
			$params["min_close_time"] -= $params["min_close_time"] % $roundTo;
		}
		if (isset($params["max_close_time"])) {
			$params["max_close_time"] -= $params["max_close_time"] % $roundTo;
		}
		ksort($params);
		$cacheKey = md5(http_build_query($params));
		$cacheEntry = $this->cache[$cacheKey]??null;
		if ($cacheEntry !== null) {
			if ($cacheEntry->validUntil >= time()) {
				$result = $this->mergeInPenaltySites($cacheEntry->result, $params);
				$callback($result, ...$args);
				return;
			}
		}
		$this->http->get(static::TOWER_API)
			->withQueryParams($params)
			->withTimeout(10)
			->withCallback([$this, "handleResult"], $params, $cacheKey, $callback, ...$args);
	}

	public function handleResult(?HttpResponse $response, array $params, string $cacheKey, callable $callback, ...$args): void {
		if ($response === null || ($response->headers["status-code"]??"0") !== "200") {
			$callback(null, ...$args);
			return;
		}
		try {
			$data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
			$result = new ApiResult($data);
		} catch (Throwable $e) {
			$callback(null, ...$args);
			return;
		}
		$apiCache = new ApiCache();
		$apiCache->validUntil = time() + $this->settingManager->getInt('tower_cache_duration');
		$apiCache->result = $result;
		$this->cache[$cacheKey] = $apiCache;
		$result = $this->mergeInPenaltySites($result, $params);
		$callback($result, ...$args);
	}

	protected function mergeInPenaltySites(ApiResult $apiResult, array $params): ApiResult {
		if (isset($params["min_close_time"]) && isset($params["max_close_time"])) {
			return $apiResult;
		}
		/** @var Collection<ApiSite> */
		$penSites = new Collection($this->towerController->getSitesInPenalty()->results);
		if (isset($params["playfield_id"])) {
			$penSites = $penSites->where("playfield_id", $params["playfield_id"]);
		}
		if (isset($params["faction"])) {
			$penSites = $penSites->where("faction", ucfirst(strtolower($params["faction"])));
		}
		if (isset($params["org_id"])) {
			$penSites = $penSites->where("org_id", $params["org_id"]);
		}
		if (isset($params["org_name"])) {
			$penSites = $penSites->where("org_name", $params["org_name"]);
		}
		if (isset($params["min_ql"])) {
			$penSites = $penSites->where("ql", ">=", $params["min_ql"]);
		}
		if (isset($params["max_ql"])) {
			$penSites = $penSites->where("ql", "<=", $params["max_ql"]);
		}

		$result = clone $apiResult;
		foreach ($penSites as $penSite) {
			$found = false;
			foreach ($result->results as $hotSite) {
				if ($penSite->playfield_id === $hotSite->playfield_id
					&& $penSite->site_number === $hotSite->site_number
				) {
					$hotSite->close_time = $penSite->close_time;
					$found = true;
					break;
				}
			}
			if (!$found) {
				$result->results []= $penSite;
				$result->count++;
			}
		}
		return $result;
	}
}
