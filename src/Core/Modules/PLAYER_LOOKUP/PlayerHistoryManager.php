<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Safe\json_decode;
use Amp\Http\Client\{HttpClientBuilder, Request};
use Nadybot\Core\{
	Attributes as NCA,
	ModuleInstance,
};
use Safe\Exceptions\JsonException;

#[NCA\Instance]
class PlayerHistoryManager extends ModuleInstance {
	#[NCA\Inject]
	private HttpClientBuilder $builder;

	public function lookup(string $name, int $dimension): ?PlayerHistory {
		$name = ucfirst(strtolower($name));
		$url = "https://pork.jkbff.com/pork/history.php?server={$dimension}&name={$name}";
		// $groupName = "player_history";
		// $cacheKey = "{$name}.{$dimension}.history";
		// $cache = new FileCache(
		// 	$this->config->paths->cache . "/{$groupName}",
		// 	new LocalKeyedMutex(),
		// );
		// if (null !== $body = yield $cache->get($cacheKey)) {
		// 	return $this->parsePlayerHistory($body, $name);
		// }
		$client = $this->builder->build();

		$response = $client->request(new Request($url));
		if ($response->getStatus() !== 200) {
			return null;
		}
		$body = $response->getBody()->buffer();
		if ($body === '' || $body === '[]') {
			return null;
		}
		// $cache->set($cacheKey, $body, 24 * 3600);
		return $this->parsePlayerHistory($body, $name);
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
