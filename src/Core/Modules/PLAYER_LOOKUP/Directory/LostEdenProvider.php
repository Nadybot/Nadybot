<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP\Directory;

use function Amp\delay;

use Amp\Http\Client\{HttpClient, HttpClientBuilder, Request, Response};
use Amp\Sync\LocalMutex;
use Amp\TimeoutCancellation;
use Nadybot\Core\{
	Attributes as NCA,
	DBSchema\Player,
	Hydrator,
	Safe,
	Types\AlienRank,
	Types\Faction,
	Types\Government,
	Types\Profession,
	Types\TitleLevel,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\LostEden\{Character, Org, OrgMember};
use Nadybot\Core\Modules\PLAYER_LOOKUP\Guild;
use Nadylib\Type;
use Psr\SimpleCache\CacheInterface;
use Safe\DateTimeImmutable;
use Throwable;

/**
 * The directory API of the private server Lost Eden
 *
 * @see https://rubi-ka.org/api/directory/
 */
class LostEdenProvider implements CharacterProvider, OrgRosterProvider {
	public const string BASE_URL = 'https://rubi-ka.org/api/directory';
	public const int DIMENSION = 7;
	public const string SOURCE = 'rubi-ka.org';

	/** The API allows one request per second */
	private const float MIN_REQUEST_INTERVAL = 1.0;

	/** Give up on a request, including waiting for rate-limits, after this many seconds */
	private const int REQUEST_TIMEOUT = 10;

	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Cache(prefix: 'lost_eden')]
	private CacheInterface $cache;

	private LocalMutex $mutex;

	private float $nextRequestAllowedAt = 0;

	public function __construct(
		private string $baseUrl=self::BASE_URL,
	) {
		$this->mutex = new LocalMutex();
	}

	public function getId(): string {
		return $this->baseUrl;
	}

	public function getName(): string {
		return 'rubi-ka.org (Lost Eden)';
	}

	public function supportsDimension(int $dimension): bool {
		return $dimension === self::DIMENSION;
	}

	/** {@inheritDoc} */
	public function getFeatures(): array {
		return [DirectoryFeature::Character, DirectoryFeature::OrgRoster];
	}

	public function lookupCharacter(string $name, int $dimension): ?Player {
		$cacheKey = 'char.' . strtolower($name);
		$body = $this->cache->get($cacheKey);
		if (!is_string($body)) {
			$body = $this->fetch('/characters/' . rawurlencode($name));
			if (!isset($body)) {
				return null;
			}
			$this->cache->set($cacheKey, $body, 60);
		}
		return self::parseCharacter($body);
	}

	public function lookupOrgRoster(int $orgId, int $dimension, bool $forceUpdate=false): ?OrgRosterResult {
		$cacheKey = "org.{$orgId}";
		$body = $forceUpdate ? null : $this->cache->get($cacheKey);
		$fromCache = is_string($body);
		if (!is_string($body)) {
			$body = $this->fetch("/orgs/{$orgId}");
			if (!isset($body)) {
				return null;
			}
			$this->cache->set($cacheKey, $body, 60);
		}
		$guild = self::parseOrg($body);
		if (!isset($guild)) {
			return null;
		}
		return new OrgRosterResult(guild: $guild, fromCache: $fromCache);
	}

	/** Parse the API's answer for a character */
	public static function parseCharacter(string $body): ?Player {
		try {
			$data = Safe::jsonDecode($body, Type\mixedDict());
			$char = Hydrator::literalHydrate(Character::class, $data);
		} catch (Throwable) {
			return null;
		}
		return self::characterToPlayer($char);
	}

	/** Parse the API's answer for an organization */
	public static function parseOrg(string $body): ?Guild {
		try {
			$data = Safe::jsonDecode($body, Type\mixedDict());
			$org = Hydrator::literalHydrate(Org::class, $data);
		} catch (Throwable) {
			return null;
		}
		return self::orgToGuild($org);
	}

	public static function characterToPlayer(Character $char): Player {
		$org = $char->org;
		return new Player(
			charid: $char->id,
			name: $char->name,
			dimension: self::DIMENSION,
			firstname: trim($char->firstName),
			lastname: trim($char->lastName),
			level: $char->level,
			breed: self::breed($char->breed),
			gender: $char->gender ?? '',
			faction: Faction::tryFrom($char->faction ?? '') ?? Faction::Unknown,
			profession: Profession::tryFrom($char->profession ?? '') ?? Profession::Unknown,
			prof_title: self::profTitle($char->profession, $char->titleLevel),
			ai_rank: AlienRank::nameForLevel($char->alienLevel),
			ai_level: $char->alienLevel,
			guild_id: $org->id ?? 0,
			guild: $org->name ?? '',
			guild_rank: $org->rankTitle ?? '',
			guild_rank_id: $org?->rank,
			head_id: $char->headId,
			pvp_rating: $char->pvpRating,
			pvp_title: null,
			source: self::SOURCE,
			last_update: self::timestamp($char->updatedAt),
		);
	}

	public static function orgToGuild(Org $org): Guild {
		$faction = Faction::tryFrom($org->faction ?? '') ?? Faction::Unknown;
		$lastUpdate = null;
		try {
			$lastUpdate = new DateTimeImmutable($org->updatedAt);
		} catch (Throwable) {
		}
		$guild = new Guild(
			guild_id: $org->id,
			orgname: $org->name,
			orgside: $faction,
			governing_form: Government::tryFrom($org->governingForm ?? '') ?? Government::Anarchism,
			last_update: $lastUpdate,
		);
		foreach ($org->members as $member) {
			$guild->members[$member->name] = self::memberToPlayer($member, $guild, $faction);
		}
		return $guild;
	}

	/**
	 * Map the API's breed names to those used by PORK and thus everywhere in the bot
	 *
	 * @see https://rubi-ka.org/api/directory/ "Nano" is "Nanomage"
	 */
	private static function breed(?string $breed): string {
		return match ($breed) {
			null => '',
			'Nanomage' => 'Nano',
			default => $breed,
		};
	}

	private static function profTitle(?string $profession, ?int $titleLevel): string {
		$prof = Profession::tryFrom($profession ?? '');
		$tl = TitleLevel::tryFrom($titleLevel ?? 0);
		if (!isset($prof, $tl)) {
			return '';
		}
		return $prof->title($tl);
	}

	private static function timestamp(string $isoDate): ?int {
		try {
			return (new DateTimeImmutable($isoDate))->getTimestamp();
		} catch (Throwable) {
			return null;
		}
	}

	private static function memberToPlayer(OrgMember $member, Guild $guild, Faction $orgFaction): Player {
		return new Player(
			charid: $member->id,
			name: $member->name,
			dimension: self::DIMENSION,
			firstname: trim($member->firstName),
			lastname: trim($member->lastName),
			level: $member->level,
			breed: self::breed($member->breed),
			gender: $member->gender ?? '',
			faction: Faction::tryFrom($member->faction ?? '') ?? $orgFaction,
			profession: Profession::tryFrom($member->profession ?? '') ?? Profession::Unknown,
			prof_title: self::profTitle($member->profession, $member->titleLevel),
			ai_rank: AlienRank::nameForLevel($member->alienLevel),
			ai_level: $member->alienLevel,
			guild_id: $guild->guild_id,
			guild: $guild->orgname,
			guild_rank: $member->rankTitle ?? '',
			guild_rank_id: $member->rank,
			head_id: $member->headId,
			pvp_rating: $member->pvpRating,
			pvp_title: null,
			source: 'org_roster',
		);
	}

	/**
	 * Request $path from the API, respecting its rate limit
	 *
	 * Answers with code 429 are retried by the HttpRetryRateLimits
	 * interceptor, honoring the Retry-After header
	 *
	 * @return ?string The body, or null if the API says it doesn't exist
	 *
	 * @throws DirectoryUnavailableException
	 */
	private function fetch(string $path): ?string {
		$url = $this->baseUrl . $path;
		$response = $this->throttledRequest($this->builder->build(), $url);
		$status = $response->getStatus();
		if ($status === 404) {
			return null;
		}
		if ($status !== 200) {
			throw new DirectoryUnavailableException("{$url} answered with code {$status}");
		}
		try {
			return $response->getBody()->buffer();
		} catch (Throwable $e) {
			throw new DirectoryUnavailableException($e->getMessage(), 0, $e);
		}
	}

	/** Do a single request, but never more than one per MIN_REQUEST_INTERVAL */
	private function throttledRequest(HttpClient $client, string $url): Response {
		$lock = $this->mutex->acquire();
		try {
			$wait = $this->nextRequestAllowedAt - microtime(true);
			if ($wait > 0) {
				delay($wait);
			}
			return $client->request(new Request($url), new TimeoutCancellation(self::REQUEST_TIMEOUT));
		} catch (Throwable $e) {
			throw new DirectoryUnavailableException($e->getMessage(), 0, $e);
		} finally {
			// Count from the last try, which might have been a retry after a 429
			$this->nextRequestAllowedAt = microtime(true) + self::MIN_REQUEST_INTERVAL;
			$lock->release();
		}
	}
}
