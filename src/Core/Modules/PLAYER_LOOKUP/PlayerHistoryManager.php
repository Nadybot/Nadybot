<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Amp\asyncCall;
use function Amp\call;
use function Safe\json_decode;

use Amp\Cache\FileCache;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Amp\Promise;
use Amp\Sync\LocalKeyedMutex;
use Safe\Exceptions\JsonException;
use Generator;
use Nadybot\Core\{
	Attributes as NCA,
	CacheManager,
	ConfigFile,
	ModuleInstance,
};

#[NCA\Instance]
class PlayerHistoryManager extends ModuleInstance {
	#[NCA\Inject]
	public HttpClientBuilder $builder;

	#[NCA\Inject]
	public CacheManager $cacheManager;

	#[NCA\Inject]
	public ConfigFile $config;

	public function asyncLookup(string $name, int $dimension, callable $callback, mixed ...$args): void {
		asyncCall(function () use ($name, $dimension, $callback, $args): Generator {
			$result = yield $this->asyncLookup2($name, $dimension);
			$callback($result, ...$args);
		});
	}

	/** @return Promise<?PlayerHistory> */
	public function asyncLookup2(string $name, int $dimension): Promise {
		return call(function () use ($name, $dimension): Generator {
			$name = ucfirst(strtolower($name));
			$url = "https://pork.jkbff.com/pork/history.php?server=${dimension}&name={$name}";
			$groupName = "player_history";
			$cacheKey = "$name.$dimension.history";
			$cache = new FileCache(
				$this->config->cacheFolder . "/{$groupName}",
				new LocalKeyedMutex(),
			);
			if (null !== $body = yield $cache->get($cacheKey)) {
				return $this->parsePlayerHistory($body, $name);
			}
			$client = $this->builder->build();
			/** @var Response */
			$response = yield $client->request(new Request($url));
			if ($response->getStatus() !== 200) {
				return null;
			}
			$body = yield $response->getBody()->buffer();
			if ($body === '' || $body === '[]') {
				return null;
			}
			$cache->set($cacheKey, $body, 24 * 3600);
			return $this->parsePlayerHistory($body, $name);
		});
	}

	/** @deprecated */
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
		return $this->parsePlayerHistory($cacheResult->data??"", $name);
	}

	/**
	 * @psalm-param callable(?PlayerHistory, mixed...) $callback
	 */
	private function parsePlayerHistory(string $data, string $name): ?PlayerHistory {
		$obj = new PlayerHistory();
		$obj->name = $name;
		$obj->data = [];
		try {
			$history = json_decode($data);
		} catch (JsonException $e) {
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
