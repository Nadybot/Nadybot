<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

use function Amp\delay;
use function Safe\parse_url;

use Amp\{CancelledException, TimeoutCancellation};
use Amp\Http\Client\{HttpClientBuilder, Request, TimeoutException};
use DateInterval;
use DateTimeZone;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DBSchema\Player,
	Hydrator,
	Safe,
	Types\Dimension,
	Types\Faction,
	Types\Government,
	Types\Profession,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\{DTOGuild, DTOGuildMember, Guild, PlayerInfo};
use Nadylib\Type;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Safe\DateTimeImmutable;
use Throwable;

/**
 * Funcom's "People of Rubi-Ka" (PORK) and all services using the same
 * URL scheme and data format, like BORK.
 *
 * All instances share their caches, because the data format is identical.
 */
class PorkProvider implements CharacterProvider, OrgRosterProvider, OrgListProvider {
	public const array ORGLIST_CHUNKS = [
		'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
		'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
		'others',
	];

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Cache(prefix: 'players')]
	private CacheInterface $playerCache;

	#[NCA\Cache(prefix: 'guild_roster')]
	private CacheInterface $rosterCache;

	#[NCA\Cache(prefix: 'orglist')]
	private CacheInterface $orglistCache;

	/**
	 * @param string                 $name             Human-readable name for settings
	 * @param string                 $baseUrl          Base URL, also used as the id of this provider
	 * @param list<int>              $dimensions       The dimensions this provider can deliver data for
	 * @param list<DirectoryFeature> $features         Which of the PORK features this instance offers
	 * @param ?float                 $characterTimeout Give up on character lookups after this many seconds and let the next provider try
	 * @param ?float                 $orgTimeout       Give up on org lookups after this many seconds and let the next provider try
	 */
	public function __construct(
		private string $name,
		private string $baseUrl,
		private array $dimensions=Dimension::FUNCOM_DIMENSIONS,
		private array $features=[DirectoryFeature::Character, DirectoryFeature::OrgRoster, DirectoryFeature::OrgList],
		private ?float $characterTimeout=null,
		private ?float $orgTimeout=null,
	) {
	}

	public function getId(): string {
		return $this->baseUrl;
	}

	public function getName(): string {
		return $this->name;
	}

	public function supportsDimension(int $dimension): bool {
		return in_array($dimension, $this->dimensions, true);
	}

	/** {@inheritDoc} */
	public function getFeatures(): array {
		return $this->features;
	}

	public function lookupCharacter(string $name, int $dimension): ?Player {
		$client = $this->builder->build();
		$url = $this->baseUrl . "/character/bio/d/{$dimension}/name/{$name}/bio.xml?data_type=json";
		$cacheKey = "{$name}.{$dimension}";
		$player = null;
		try {
			$try = 0;
			$retries = 5;
			while ($try++ < $retries) {
				try {
					$body = $this->playerCache->get($cacheKey);

					if (isset($body) && is_string($body)) {
						$player = $this->parsePlayerFromBody($body);
						break;
					}

					$start = microtime(true);

					$timeout = isset($this->characterTimeout)
						? new TimeoutCancellation($this->characterTimeout)
						: null;
					$response = $client->request(new Request($url), $timeout);

					if ($response->getStatus() === 200) {
						$body = $response->getBody()->buffer();
						$this->playerCache->set($cacheKey, $body, 60);
						$player = $this->parsePlayerFromBody($body);
					} else {
						$this->logger->debug('Looking up {name}.{dimension}: {code}', [
							'name' => $name,
							'dimension' => $dimension,
							'code' => $response->getStatus(),
						]);
					}
					$end = microtime(true);
					$this->logger->info('Lookup for {name} took {duration}ms', [
						'name' => $name,
						'duration' => $end - $start,
					]);
					break;
				} catch (\Amp\TimeoutException | CancelledException $e) {
					throw new DirectoryUnavailableException("{$this->name} did not answer in time", 0, $e);
				} catch (TimeoutException) {
					/** @psalm-suppress RedundantCast */
					$delay = (int)pow($try, 2); // @phpstan-ignore-line
					$this->logger->info('Lookup for {name}.{dimension} timed out, retrying in {delay}s ({try}/{retries})', [
						'name' => $name,
						'dimension' => $dimension,
						'try' => $try,
						'delay' => $delay,
						'retries' => $retries,
					]);
					if ($try < $retries) {
						delay($delay);
					}
				}
			}
		} catch (DirectoryUnavailableException $e) {
			throw $e;
		} catch (Throwable $e) {
			$this->logger->warning('Error looking up {name}.{dimension}: {error} ({class})', [
				'name' => $name,
				'dimension' => $dimension,
				'error' => $e->getMessage(),
				'class' => $e::class,
				'exception' => $e,
			]);
			throw new DirectoryUnavailableException($e->getMessage(), 0, $e);
		}
		if (isset($player)) {
			/** @var ?string */
			$host = parse_url($url, \PHP_URL_HOST);
			$player->source = $host ?? 'people.anarchy-online.com';
			$player->dimension = $dimension;
		}
		return $player;
	}

	public function lookupOrgRoster(int $orgId, int $dimension, bool $forceUpdate=false): ?OrgRosterResult {
		$body = null;

		$maxCacheAge = new DateInterval('PT24H');
		if (isset($this->config->orgId) && $this->config->orgId === $orgId) {
			$maxCacheAge = new DateInterval('PT6H');
		}

		$cacheKey = "{$orgId}.{$dimension}";
		$fromCache = true;
		if (!$forceUpdate) {
			$body = (string)$this->rosterCache->get($cacheKey);
		}

		$try = 0;
		while ((!isset($body) || $body === '') && $try < 3) {
			try {
				$url = "{$this->baseUrl}/org/stats/d/{$dimension}/name/{$orgId}/basicstats.xml?data_type=json";
				$start = microtime(true);
				$try++;
				$client = $this->builder->build();
				$timeout = isset($this->orgTimeout)
					? new TimeoutCancellation($this->orgTimeout)
					: null;
				$response = $client->request(new Request($url), $timeout);

				$body = $response->getBody()->buffer();
				$end = microtime(true);
				$this->logger->info('Getting {url} took {duration}ms', [
					'url' => $url,
					'duration' => $end - $start,
				]);
				$this->rosterCache->set($cacheKey, $body, $maxCacheAge);
				$fromCache = false;
			} catch (\Amp\TimeoutException | CancelledException $e) {
				throw new DirectoryUnavailableException("{$this->name} did not answer in time", 0, $e);
			} catch (TimeoutException) {
				/** @psalm-suppress RedundantCast */
				$delay = (int)pow($try, 2); // @phpstan-ignore-line
				$this->logger->info('Lookup for ORG {guild} D{dimension} timed out, retrying in {delay}s ({try}/{retries})', [
					'guild' => $orgId,
					'dimension' => $dimension,
					'try' => $try,
					'delay' => $delay,
					'retries' => 3,
				]);
				if ($try < 3) {
					delay($delay);
				}
			}
		}

		if (!isset($body) || $body === '') {
			throw new DirectoryUnavailableException('Empty data received when reading org data');
		}
		$guild = $this->parseOrgRoster($body, $orgId, $dimension);
		if (!isset($guild)) {
			return null;
		}
		// Try to reduce the cache time to the last updated time + 24h
		if (isset($guild->last_update)) {
			$newCacheDuration = max(60, 86_400 - (time() - $guild->last_update->getTimestamp()));
			$this->rosterCache->set($cacheKey, $body, $newCacheDuration);
		}
		return new OrgRosterResult(guild: $guild, fromCache: $fromCache);
	}

	/** {@inheritDoc} */
	public function getOrgListChunks(): array {
		return self::ORGLIST_CHUNKS;
	}

	public function lookupOrgList(string $chunk, int $dimension): OrgListResult {
		$this->logger->info('Downloading orglist for letter {letter}', ['letter' => $chunk]);

		/** @var ?string */
		$body = $this->orglistCache->get($chunk);

		if ($body !== null) {
			return new OrgListResult(
				orgs: $this->parseOrgList($body),
				fromCache: true,
			);
		}
		$body = null;
		$url = $this->baseUrl . '/people/lookup/orgs.html'.
			"?l={$chunk}&dim={$dimension}";
		$client = $this->builder->build();
		$retry = 5;
		do {
			try {
				$request = new Request($url);
				// The gateway timeout of PORK is 60s
				$request->setTransferTimeout(61);

				$response = $client->request($request);

				if ($response->getStatus() !== 200) {
					if (--$retry <= 0) {
						throw new DirectoryUnavailableException("Unable to download orglist for {$chunk}");
					}
					$this->logger->warning(
						'Error downloading orglist for letter {letter}, retrying in {retry}s',
						[
							'letter' => $chunk,
							'dim' => $dimension,
							'retry' => 5,
						]
					);
					delay(5);
				} else {
					$body = $response->getBody()->buffer();
				}
			} catch (DirectoryUnavailableException $e) {
				throw $e;
			} catch (TimeoutException) {
				$this->logger->warning(
					'Timeout downloading orglist for letter {letter}, retrying in {retry}s',
					[
						'letter' => $chunk,
						'dim' => $dimension,
						'retry' => 5,
					]
				);
				delay(5);
			} catch (Throwable $e) {
				$this->logger->warning(
					'Error downloading orglist for letter {letter}: {error}, retrying in {retry}s',
					[
						'letter' => $chunk,
						'error' => $e->getMessage(),
						'dim' => $dimension,
						'retry' => 5,
						'exception' => $e,
					]
				);
				delay(5);
			}
		} while ((!isset($response) || $response->getStatus() !== 200));
		$html = $body ?? '';
		if (!str_contains($html, 'ORGS BEGIN')) {
			throw new DirectoryUnavailableException("Invalid data received from orglist for {$chunk}");
		}

		$this->orglistCache->set($chunk, $html, new DateInterval('PT23H'));

		return new OrgListResult(
			orgs: $this->parseOrgList($html),
			fromCache: false,
		);
	}

	/**
	 * Parse the HTML of PORK's org list
	 *
	 * @return list<OrgListEntry>
	 */
	public function parseOrgList(string $body): array {
		$pattern = '@<tr>\s*'.
			'<td align="left">\s*'.
				'<a href="(?:https?:)?//people.anarchy-online.com/org/stats/d/(\d+)/name/(\d+)">\s*'.
					'([^<]+)'.
				'</a>'.
			'</td>\s*'.
			'<td align="right">(\d+)</td>\s*'.
			'<td align="right">(\d+)</td>\s*'.
			'<td align="left">([^<]+)</td>\s*'.
			'<td align="left">([^<]+)</td>\s*'.
			'<td align="left" class="dim">RK\d+</td>\s*'.
			'</tr>@s';

		$arr = Safe::pregMatchOrderedAll($pattern, $body);
		$result = [];
		foreach ($arr as $match) {
			$result []= new OrgListEntry(
				id: (int)$match[2],
				name: trim($match[3]),
				numMembers: (int)$match[4],
				faction: Faction::from($match[6]),
				governingForm: Government::from($match[7]),
			);
		}
		return $result;
	}

	/** Parse PORK's JSON for an organization and its members */
	public function parseOrgRoster(string $body, int $orgId, int $dimension): ?Guild {
		$validator = Type\shape([
			0 => Type\dict(Type\string(), Type\mixed()),
			1 => Type\vec(Type\dict(Type\string(), Type\mixed())),
			2 => Type\nonEmptyString(),
		]);
		$data = Safe::jsonDecode($body, $validator);

		[$orgInfo, $members, $lastUpdated] = $data;

		/**
		 * @var array<string,mixed>       $orgInfo
		 * @var list<array<string,mixed>> $members
		 * @var non-empty-string          $lastUpdated
		 */

		try {
			$orgInfo = Hydrator::literalHydrate(DTOGuild::class, $orgInfo);
		} catch (Throwable) {
			return null;
		}
		if ($orgInfo->NAME === null) {
			return null;
		}
		try {
			$members = Hydrator::literalHydrateObjects(DTOGuildMember::class, $members)->toArray();
		} catch (Throwable) {
			return null;
		}

		$guild = new Guild(
			guild_id: $orgId,
			governing_form: Government::from($orgInfo->GOVERNINGNAME),
			orgname: $orgInfo->NAME,
			orgside: Faction::tryFrom($orgInfo->SIDE_NAME) ?? Faction::Unknown,
			last_update: DateTimeImmutable::createFromFormat('!Y/m/d H:i:s', $lastUpdated, new DateTimeZone('UTC')),
		);

		foreach ($members as $member) {
			$name = $member->NAME;
			$guild->members[$name] = new Player(
				charid: $member->CHAR_INSTANCE ?? 0,
				firstname: trim($member->FIRSTNAME),
				name: $name,
				lastname: trim($member->LASTNAME),
				level: $member->LEVELX,
				breed: $member->BREED,
				gender: $member->SEX,
				faction: $guild->orgside,
				profession: Profession::tryFrom($member->PROF),
				prof_title: $member->PROF_TITLE,
				ai_rank: $member->DEFENDER_RANK_TITLE,
				ai_level: $member->ALIENLEVEL,
				guild_id: $guild->guild_id,
				guild: $guild->orgname,
				guild_rank: $member->RANK_TITLE,
				guild_rank_id: $member->RANK,
				dimension: $dimension,
				source: 'org_roster',
				head_id: $member->HEADID,
				pvp_rating: $member->PVPRATING,
				pvp_title: $member->PVPTITLE,
			);
		}
		return $guild;
	}

	private function parsePlayerFromBody(string $body): ?Player {
		if ($body === 'null') {
			return null;
		}
		try {
			return Hydrator::hydrateString(PlayerInfo::class, $body)->toPlayer();
		} catch (Exception $e) {
			$this->logger->warning('Error parsing player data: {error} ({class})', [
				'error' => $e->getMessage(),
				'class' => $e::class,
				'exception' => $e,
				'data' => $body,
			]);
			return null;
		}
	}
}
