<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use function Amp\async;
use Amp\Http\Client\{HttpClientBuilder, Request};
use Amp\TimeoutCancellation;
use AO\Utils;
use DateInterval;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	Hydrator,
	ModuleInstance,
	Safe,
};
use Nadybot\Core\Attributes\ExposeToAI;
use Nadybot\Core\Config\BotConfig;
use Nadylib\Type;
use Psr\SimpleCache\CacheInterface;
use Throwable;

#[NCA\Instance]
class PlayerHistoryManager extends ModuleInstance {
	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Cache(prefix: 'player_history')]
	private CacheInterface $cache;

	/**
	 * Look up the history of a character's development over time (org membership, level). If the
	 * character is unknown, returns null
	 *
	 * @param string $name      The name of the character to look up
	 * @param ?int   $dimension The dimension (server) for which to get the character's history.
	 *                          Defaults to the one this bot runs on
	 *
	 * @return ?PlayerHistory Detailed character history, or null if the character is unknown
	 */
	#[ExposeToAI(name: 'get_char_history')]
	public function lookup(string $name, ?int $dimension=null): ?PlayerHistory {
		$dimension ??= $this->config->main->dimension;
		$name = Utils::normalizeCharacter($name);
		$urls = [
			"https://history.aobots.org/?server={$dimension}&name={$name}",
			$mainUrl = "https://pork.jkbff.com/pork/history.php?server={$dimension}&name={$name}",
		];
		$cacheKey = "{$name}.{$dimension}.history";
		if (null !== ($body = $this->cache->get($cacheKey)) && is_string($body)) {
			return $this->parsePlayerHistory($body, $name);
		}
		$client = $this->builder->build();

		$response = null;
		$url = $urls[0];
		do {
			$body = null;
			$url = array_shift($urls);
			try {
				$response = $client->request(new Request($url), new TimeoutCancellation(10));
				if ($response->getStatus() !== 200) {
					continue;
				}
				$body = $response->getBody()->buffer();
			} catch (Throwable) {
				continue;
			}
		} while (!isset($body) && count($urls) > 0);
		if ($url !== $mainUrl && $dimension > 3) {
			async($client->request(...), new Request($mainUrl))->ignore();
		}
		if (!isset($body) || $body === '' || $body === '[]') {
			return null;
		}
		$this->cache->set($cacheKey, $body, new DateInterval('PT12H'));

		/** @psalm-suppress MixedArgument */
		return $this->parsePlayerHistory($body, $name);
	}

	private function parsePlayerHistory(string $data, string $name): ?PlayerHistory {
		try {
			$history = Safe::jsonDecode($data, Type\vec(Type\dict(Type\string(), Type\mixed())));
		} catch (Exception) {
			return null;
		}

		$entries = Hydrator::hydrateObjects(PlayerHistoryData::class, $history)->toArray();
		return new PlayerHistory(name: $name, data: $entries);
	}
}
