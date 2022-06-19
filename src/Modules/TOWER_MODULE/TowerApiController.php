<?php declare(strict_types=1);

namespace Nadybot\Modules\TOWER_MODULE;

use function Amp\{asyncCall, call};
use function Safe\json_decode;
use Amp\Http\Client\Interceptor\SetRequestHeader;
use Amp\Http\Client\{HttpClientBuilder, Request, Response};
use Amp\Promise;
use Exception;
use Generator;
use League\Uri\Http;
use Nadybot\Core\{
	Attributes as NCA,
	BotRunner,
	ModuleInstance,
};

use Throwable;

#[NCA\Instance]
class TowerApiController extends ModuleInstance {
	public const TOWER_API = "tower_api";
	public const API_TYRENCE = "https://tower-api.jkbff.com/v1/api/towers";
	public const API_NONE = "none";

	#[NCA\Inject]
	public HttpClientBuilder $builder;

	#[NCA\Inject]
	public TowerController $towerController;

	/** Which API to use for querying tower infos */
	#[NCA\Setting\Text(options: [self::API_NONE, self::API_TYRENCE])]
	public string $towerApi = self::API_TYRENCE;

	/** How long to cache data from the Tower API */
	#[NCA\Setting\Options(options: [
		'1 min' => 60,
		'5 min' => 300,
		'10 min' => 600,
		'15 min' => 900,
		'30 min' => 1800,
		'1 hour' => 3600,
		'2 hours' => 7200,
	])]
	public int $towerCacheDuration = 10 * 60; // 10 mins

	/** @var array<string,ApiCache> */
	protected array $cache = [];

	public function isActive(): bool {
		return $this->towerApi !== static::API_NONE;
	}

	#[NCA\SettingChangeHandler(self::TOWER_API)]
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
	 *
	 * @return Promise<?ApiResult>
	 */
	public function call2(array $params): Promise {
		return call(function () use ($params): Generator {
			$roundTo = $this->towerCacheDuration;
			if (isset($params["min_close_time"])) {
				$params["min_close_time"] -= $params["min_close_time"] % $roundTo;
			}
			if (isset($params["max_close_time"])) {
				$params["max_close_time"] -= $params["max_close_time"] % $roundTo;
			}
			ksort($params);
			$cacheKey = md5($query = http_build_query($params));
			$cacheEntry = $this->cache[$cacheKey]??null;
			if ($cacheEntry !== null) {
				if ($cacheEntry->validUntil >= time()) {
					return $cacheEntry->result;
				}
			}
			$apiURL = $this->towerApi;
			if ($apiURL === static::API_NONE) {
				$apiURL = static::API_TYRENCE;
			}
			$client = $this->builder
				->intercept(new SetRequestHeader("User-Agent", "Naughtybot " . BotRunner::getVersion()))
				->build();
			$uri = Http::createFromString($apiURL)->withQuery($query);

			/** @var Response */
			$response = yield $client->request(new Request($uri));
			if ($response->getStatus() !== 200) {
				return null;
			}
			$body = yield $response->getBody()->buffer();
			if ($body === '') {
				return null;
			}
			try {
				$data = json_decode($body, true);
				$result = new ApiResult($data);
			} catch (Throwable $e) {
				return null;
			}
			$apiCache = new ApiCache();
			$apiCache->validUntil = time() + $this->towerCacheDuration;
			$apiCache->result = $result;
			$this->cache[$cacheKey] = $apiCache;
			return $result;
		});
	}

	/**
	 * @param array<string,mixed> $params
	 * @psalm-param callable(?ApiResult, mixed...) $callback
	 */
	public function call(array $params, callable $callback, mixed ...$args): void {
		asyncCall(function () use ($params, $callback, $args): Generator {
			$callback(yield $this->call2($params), ...$args);
		});
	}
}
