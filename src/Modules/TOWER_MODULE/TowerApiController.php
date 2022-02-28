<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use Exception;
use Throwable;
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	Http,
	HttpResponse,
	ModuleInstance,
	SettingManager,
};

#[NCA\Instance]
class TowerApiController extends ModuleInstance {
	public const TOWER_API = "tower_api";
	public const API_TYRENCE = "https://tower-api.jkbff.com/v1/api/towers";
	public const API_NONE = "none";

	#[NCA\Inject]
	public Http $http;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public TowerController $towerController;

	/** @var array<string,ApiCache> */
	protected array $cache = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: static::TOWER_API,
			description: "Which API to use for querying tower infos",
			mode: "edit",
			type: "text",
			value: static::API_TYRENCE,
			options: static::API_NONE . ";" . static::API_TYRENCE
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "tower_cache_duration",
			description: "How long to cache data from the Tower API",
			mode: "edit",
			type: "options",
			value: "600",
			options: [
				'1 min' => 60,
				'5 min' => 300,
				'10 min' => 600,
				'15 min' => 900,
				'30 min' => 1800,
				'1 hour' => 3600,
				'2 hours' => 7200,
			]
		);
		$this->settingManager->registerChangeListener(
			static::TOWER_API,
			[$this, "verifyTowerAPI"]
		);
	}

	public function isActive(): bool {
		return $this->settingManager->getString(static::TOWER_API) !== static::API_NONE;
	}

	public function verifyTowerAPI(string $settingName, string $oldValue, string $newValue, mixed $data): void {
		if ($newValue === static::API_NONE) {
			return;
		}
		$parsed = parse_url($newValue);
		if (!is_array($parsed)) {
			throw new Exception("<highlight>{$newValue}<end> is not a valid URL.");
		}
		if (!isset($parsed["scheme"]) ||!isset($parsed["host"])) {
			throw new Exception("<highlight>{$newValue}<end> is not a valid URL.");
		}
		if (!in_array(strtolower($parsed['scheme']), ["http", "https"])) {
			throw new Exception("<highlight>{$parsed['scheme']}<end> is an unsupported scheme.");
		}
	}

	#[NCA\Event(
		name: "timer(5m)",
		description: "Clean API Cache"
	)]
	public function cleanApiCache(): void {
		$keys = array_keys($this->cache);
		foreach ($keys as $key) {
			if (isset($this->cache[$key]) && $this->cache[$key]->validUntil < time()) {
				unset($this->cache[$key]);
			}
		}
	}

	public function wipeApiCache(): void {
		$this->cache = [];
	}

	/**
	 * @param array<string,mixed> $params
	 * @psalm-param callable(?ApiResult, mixed...) $callback
	 */
	public function call(array $params, callable $callback, mixed ...$args): void {
		$roundTo = $this->settingManager->getInt('tower_cache_duration') ?? 600;
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
				$callback($cacheEntry->result, ...$args);
				return;
			}
		}
		$apiURL = $this->settingManager->getString(static::TOWER_API) ?? static::API_TYRENCE;
		if ($apiURL === static::API_NONE) {
			$apiURL = static::API_TYRENCE;
		}
		$this->http->get($apiURL)
			->withQueryParams($params)
			->withTimeout(10)
			->withHeader('User-Agent', 'Naughtybot ' . BotRunner::getVersion())
			->withCallback([$this, "handleResult"], $params, $cacheKey, $callback, ...$args);
	}

	/**
	 * @param array<string,mixed> $params
	 * @psalm-param callable(?ApiResult, mixed...) $callback
	 */
	public function handleResult(HttpResponse $response, array $params, string $cacheKey, callable $callback, mixed ...$args): void {
		if (!isset($response->body) || ($response->headers["status-code"]??"0") !== "200") {
			$callback(null, ...$args);
			return;
		}
		try {
			$data = \Safe\json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);
			$result = new ApiResult($data);
		} catch (Throwable $e) {
			$callback(null, ...$args);
			return;
		}
		$apiCache = new ApiCache();
		$apiCache->validUntil = time() + ($this->settingManager->getInt('tower_cache_duration') ?? 600);
		$apiCache->result = $result;
		$this->cache[$cacheKey] = $apiCache;
		$callback($result, ...$args);
	}
}
