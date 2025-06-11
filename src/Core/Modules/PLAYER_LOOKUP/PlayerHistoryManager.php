<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Amp\async;
use function Safe\json_decode;
use Amp\Http\Client\{HttpClientBuilder, Request};
use Amp\TimeoutCancellation;
use AO\Utils;
use DateInterval;
use Nadybot\Core\{
	Attributes as NCA,
	Hydrator,
	ModuleInstance,
};
use Psr\SimpleCache\CacheInterface;
use Safe\Exceptions\JsonException;
use Throwable;

#[NCA\Instance]
class PlayerHistoryManager extends ModuleInstance {
	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Cache(prefix: 'player_history')]
	private CacheInterface $cache;

	public function lookup(string $name, int $dimension): ?PlayerHistory {
		$name = Utils::normalizeCharacter($name);
		$urls = [
			"https://history.aobots.org/?server={$dimension}&name={$name}",
			$mainUrl = "https://pork.jkbff.com/pork/history.php?server={$dimension}&name={$name}",
		];
		$cacheKey = "{$name}.{$dimension}.history";
		if (null !== ($body = $this->cache->get($cacheKey))) {
			return $this->parsePlayerHistory($body, $name);
		}
		$client = $this->builder->build();

		$triesLeft = 5;
		$response = null;
		$url = $urls[0];
		do {
			$body = null;
			if (count($urls) > 0) {
				$url = array_shift($urls);
			}
			$triesLeft--;
			try {
				$response = $client->request(new Request($url), new TimeoutCancellation(10));
				if ($response->getStatus() !== 200) {
					continue;
				}
				$body = $response->getBody()->buffer();
			} catch (Throwable) {
				continue;
			}
		} while (!isset($body) && $triesLeft > 0);
		if ($url !== $mainUrl && $dimension > 3) {
			async($client->request(...), new Request($mainUrl))->ignore();
		}
		if (!isset($body) || $body === '' || $body === '[]') {
			return null;
		}
		$this->cache->set($cacheKey, $body, new DateInterval('PT12H'));

		/** @psalm-suppress NoValue */
		return $this->parsePlayerHistory($body, $name);
	}

	/** @psalm-param callable(?PlayerHistory, mixed...) $callback */
	private function parsePlayerHistory(string $data, string $name): ?PlayerHistory {
		try {
			$history = json_decode($data, true);
		} catch (JsonException) {
			return null;
		}

		$entries = Hydrator::hydrateObjects(PlayerHistoryData::class, $history)->toArray();
		return new PlayerHistory(name: $name, data: $entries);
	}
}
