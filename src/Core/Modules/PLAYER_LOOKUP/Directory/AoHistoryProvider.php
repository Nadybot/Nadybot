<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

use function Amp\async;

use Amp\Http\Client\{HttpClientBuilder, Request};
use Amp\TimeoutCancellation;
use DateInterval;
use Exception;
use Nadybot\Core\{Attributes as NCA, Hydrator, Safe};
use Nadybot\Core\Modules\PLAYER_LOOKUP\{PlayerHistory, PlayerHistoryData};
use Nadylib\Type;
use Psr\SimpleCache\CacheInterface;
use Throwable;

/**
 * Character history services like history.aobots.org and pork.jkbff.com
 *
 * All instances share their cache, because the data format is identical.
 */
class AoHistoryProvider implements HistoryProvider {
	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Cache(prefix: 'player_history')]
	private CacheInterface $cache;

	/**
	 * @param string    $name              Human-readable name
	 * @param string    $urlTemplate       URL with {dimension} and {name} placeholders, also used as the id of this provider
	 * @param list<int> $dimensions        The dimensions this provider can deliver data for
	 * @param ?string   $notifyUrlTemplate After a successful lookup on Funcom servers, also request this URL
	 *                                     (with placeholders), so that service learns about the character
	 */
	public function __construct(
		private string $name,
		private string $urlTemplate,
		private array $dimensions=[1, 2, 5, 6],
		private ?string $notifyUrlTemplate=null,
	) {
	}

	public function getId(): string {
		return $this->urlTemplate;
	}

	public function getName(): string {
		return $this->name;
	}

	public function supportsDimension(int $dimension): bool {
		return in_array($dimension, $this->dimensions, true);
	}

	/** {@inheritDoc} */
	public function getFeatures(): array {
		return [DirectoryFeature::History];
	}

	public function lookupHistory(string $name, int $dimension): ?PlayerHistory {
		$cacheKey = "{$name}.{$dimension}.history";
		if (null !== ($body = $this->cache->get($cacheKey)) && is_string($body)) {
			return $this->parsePlayerHistory($body, $name);
		}
		$client = $this->builder->build();
		$url = $this->fillTemplate($this->urlTemplate, $name, $dimension);
		try {
			$response = $client->request(new Request($url), new TimeoutCancellation(10));
		} catch (Throwable $e) {
			throw new DirectoryUnavailableException($e->getMessage(), 0, $e);
		}
		if ($response->getStatus() !== 200) {
			throw new DirectoryUnavailableException("{$this->name} answered with code {$response->getStatus()}");
		}
		try {
			$body = $response->getBody()->buffer();
		} catch (Throwable $e) {
			throw new DirectoryUnavailableException($e->getMessage(), 0, $e);
		}
		if (isset($this->notifyUrlTemplate) && $dimension > 3) {
			$notifyUrl = $this->fillTemplate($this->notifyUrlTemplate, $name, $dimension);
			async($client->request(...), new Request($notifyUrl))->ignore();
		}
		if ($body === '' || $body === '[]') {
			return null;
		}
		$this->cache->set($cacheKey, $body, new DateInterval('PT12H'));

		return $this->parsePlayerHistory($body, $name);
	}

	public function parsePlayerHistory(string $data, string $name): ?PlayerHistory {
		try {
			$history = Safe::jsonDecode($data, Type\vec(Type\dict(Type\string(), Type\mixed())));
		} catch (Exception) {
			return null;
		}

		$entries = Hydrator::hydrateObjects(PlayerHistoryData::class, $history)->toArray();
		return new PlayerHistory(name: $name, data: $entries);
	}

	private function fillTemplate(string $template, string $name, int $dimension): string {
		return str_replace(['{dimension}', '{name}'], [(string)$dimension, $name], $template);
	}
}
