<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

use Closure;
use DateInterval;
use DateTime;
use DateTimeZone;
use Safe\Exceptions\JsonException;
use Nadybot\Core\{
	Attributes as NCA,
	CacheManager,
	CacheResult,
	ConfigFile,
	DB,
	DBSchema\Player,
	EventManager,
	ModuleInstance,
	Nadybot,
};

/**
 * @author Tyrence (RK2)
 */
#[NCA\Instance]
class GuildManager extends ModuleInstance {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public CacheManager $cacheManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	protected function getJsonValidator(): Closure {
		return function(?string $data): bool {
			try {
				if ($data === null) {
					return false;
				}
				$result = \Safe\json_decode($data);
				return $result !== null;
			} catch (JsonException $e) {
				return false;
			}
		};
	}

	/**
	 * @psalm-param callable(?Guild, mixed...) $callback
	 */
	public function getByIdAsync(int $guildID, ?int $dimension, bool $forceUpdate, callable $callback, mixed ...$args): void {
		// if no server number is specified use the one on which the bot is logged in
		$dimension ??= $this->config->dimension;

		$url = "http://people.anarchy-online.com/org/stats/d/$dimension/name/$guildID/basicstats.xml?data_type=json";
		$maxCacheAge = 86400;
		if ($this->isMyGuild($guildID)) {
			$maxCacheAge = 21600;
		}

		$this->cacheManager->asyncLookup(
			$url,
			"guild_roster",
			"$guildID.$dimension.json",
			$this->getJsonValidator(),
			$maxCacheAge,
			$forceUpdate,
			[$this, "handleGuildLookup"],
			$guildID,
			$dimension,
			$callback,
			...$args
		);
	}

	/** @deprecated */
	public function getById(int $guildID, int $dimension=null, bool $forceUpdate=false): ?Guild {
		// if no server number is specified use the one on which the bot is logged in
		$dimension ??= $this->config->dimension;

		$url = "http://people.anarchy-online.com/org/stats/d/$dimension/name/$guildID/basicstats.xml?data_type=json";
		$groupName = "guild_roster";
		$filename = "$guildID.$dimension.json";
		$maxCacheAge = 86400;
		if ($this->isMyGuild($guildID)) {
			$maxCacheAge = 21600;
		}
		$cb = $this->getJsonValidator();

		$cacheResult = $this->cacheManager->lookup($url, $groupName, $filename, $cb, $maxCacheAge, $forceUpdate);
		$result = null;
		$this->handleGuildLookup(
			$cacheResult,
			$guildID,
			$dimension,
			function(?Guild $guild) use (&$result): void {
				$result = $guild;
			}
		);
		return $result;
	}

	/**
	 * Check if $guildId is the bot's guild id
	 */
	public function isMyGuild(int $guildId): bool {
		return isset($this->config->orgId)
			&& $this->config->orgId === $guildId;
	}

	/** @psalm-param callable(?Guild, mixed...) $callback */
	public function handleGuildLookup(CacheResult $cacheResult, int $guildID, int $dimension, callable $callback, mixed ...$args): void {

		// if there is still no valid data available give an error back
		if ($cacheResult->success !== true) {
			$callback(null, ...$args);
			return;
		}

		[$orgInfo, $members, $lastUpdated] = \Safe\json_decode($cacheResult->data??"");

		if ($orgInfo->NAME === null) {
			$callback(null, ...$args);
			return;
		}

		// parsing of the member data
		$guild = new Guild();
		$guild->guild_id = $guildID;
		$guild->governing_form = $orgInfo->GOVERNINGNAME;
		$guild->orgname = $orgInfo->NAME;
		$guild->orgside = $orgInfo->SIDE_NAME;
		$luDateTime = DateTime::createFromFormat("Y/m/d H:i:s", $lastUpdated, new DateTimeZone("UTC"));
		if ($luDateTime && $this->isMyGuild($guild->guild_id)) {
			$guild->last_update = $luDateTime->getTimestamp();
			// Try to time the next rosterupdate to occur 1 day and 10m after the last export
			$key = $this->eventManager->getKeyForCronEvent(24*3600, 'guildcontroller.downloadOrgRosterEvent');
			if (isset($key)) {
				$nextTime = $luDateTime->add(new DateInterval("P1DT10M"));
				if ($nextTime->getTimestamp() > time()) {
					$this->eventManager->setCronNextEvent($key, $nextTime->getTimestamp());
				}
			}
		}

		// pre-fetch the charids...this speeds things up immensely
		foreach ($members as $member) {
			$name = $member->NAME;
			if (!isset($this->chatBot->id[$name]) && !isset($member->CHAR_INSTANCE)) {
				$this->chatBot->sendLookupPacket($name);
			}
		}

		foreach ($members as $member) {
			/** @var string */
			$name = $member->NAME;
			$charid = $member->CHAR_INSTANCE ?? $this->chatBot->get_uid($name);
			if ($charid === null || $charid === false) {
				$charid = 0;
			}

			$guild->members[$name]                = new Player();
			$guild->members[$name]->charid        = $charid;
			$guild->members[$name]->firstname     = trim($member->FIRSTNAME);
			$guild->members[$name]->name          = $name;
			$guild->members[$name]->lastname      = trim($member->LASTNAME);
			$guild->members[$name]->level         = $member->LEVELX;
			$guild->members[$name]->breed         = $member->BREED;
			$guild->members[$name]->gender        = $member->SEX;
			$guild->members[$name]->faction       = $guild->orgside;
			$guild->members[$name]->profession    = $member->PROF;
			$guild->members[$name]->prof_title    = $member->PROF_TITLE;
			$guild->members[$name]->ai_rank       = $member->DEFENDER_RANK_TITLE;
			$guild->members[$name]->ai_level      = $member->ALIENLEVEL;
			$guild->members[$name]->guild_id      = $guild->guild_id;
			$guild->members[$name]->guild         = $guild->orgname;
			$guild->members[$name]->guild_rank    = $member->RANK_TITLE;
			$guild->members[$name]->guild_rank_id = $member->RANK;
			$guild->members[$name]->dimension     = $dimension;
			$guild->members[$name]->source        = 'org_roster';

			$guild->members[$name]->head_id       = $member->HEADID;
			$guild->members[$name]->pvp_rating    = $member->PVPRATING;
			$guild->members[$name]->pvp_title     = $member->PVPTITLE;
		}

		// this is done separately from the loop above to prevent nested transaction errors from occurring
		// when looking up charids for characters
		if ($cacheResult->usedCache === false) {
			$this->db->beginTransaction();

			$this->db->table("players")
				->where("guild_id", $guild->guild_id)
				->where("dimension", $dimension)
				->update([
					"guild_id" => 0,
					"guild" => ""
				]);

			foreach ($guild->members as $member) {
				$this->playerManager->update($member);
			}

			$this->db->commit();
		}

		$callback($guild, ...$args);
	}
}
