<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Safe\json_decode;

use Amp\File\{FileCache, Filesystem};
use Amp\Http\Client\{HttpClientBuilder, Request};
use Amp\Sync\LocalKeyedMutex;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\{
	Attributes as NCA,
	ModuleInstance,
};
use Safe\Exceptions\JsonException;

#[NCA\Instance]
class PlayerHistoryManager extends ModuleInstance {
	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Setup]
	public function setup(): void {
		$path = $this->getCacheDir();
		if (!$this->fs->exists($path)) {
			$this->fs->createDirectory($path, 0700);
		}
	}

	public function lookup(string $name, int $dimension): ?PlayerHistory {
		$name = ucfirst(strtolower($name));
		$url = "https://pork.jkbff.com/pork/history.php?server={$dimension}&name={$name}";
		$cacheKey = "{$name}.{$dimension}.history";
		$cache = new FileCache(
			$this->getCacheDir(),
			new LocalKeyedMutex(),
			$this->fs
		);
		if (null !== ($body = $cache->get($cacheKey))) {
			return $this->parsePlayerHistory($body, $name);
		}
		$client = $this->builder->build();

		$response = $client->request(new Request($url));
		if ($response->getStatus() !== 200) {
			return null;
		}
		$body = $response->getBody()->buffer();
		if ($body === '' || $body === '[]') {
			return null;
		}
		$cache->set($cacheKey, $body, 12 * 3600);
		return $this->parsePlayerHistory($body, $name);
	}

	private function getCacheDir(): string {
		return $this->config->paths->cache . "/player_history";
	}

	/** @psalm-param callable(?PlayerHistory, mixed...) $callback */
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
