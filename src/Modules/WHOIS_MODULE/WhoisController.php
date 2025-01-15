<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOIS_MODULE;

use function Amp\async;
use function Amp\Future\await;

use AO\Package;
use Illuminate\Support\Collection;
use Nadybot\Core\DB\DBType;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	Config\BotConfig,
	DB,
	DBSchema\Audit,
	DBSchema\Player,
	Events\Event,
	Events\PackageEvent,
	Events\TimerEvent,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Modules\BAN\BanController,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PCharacter,
	Safe,
	Text,
	Util,
};
use Nadybot\Modules\COMMENT_MODULE\CommentController;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'whois',
		accessLevel: 'guest',
		description: 'Show character info, online status, and name history',
		alias: ['w', 'is'],
	),
	NCA\DefineCommand(
		command: 'lookup',
		accessLevel: 'guest',
		description: 'Find the charId for a character',
	)
]
class WhoisController extends ModuleInstance {
	/** Add link to comments if found */
	#[NCA\Setting\Boolean]
	public bool $whoisAddComments = true;

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private BanController $banController;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private BuddylistManager $buddylistManager;

	#[NCA\Inject]
	private CommentController $commentController;

	/** @var list<CharData> */
	private array $nameHistoryCache = [];

	#[NCA\Event(
		name: 'timer(1min)',
		description: 'Save cache of names and charIds to database'
	)]
	public function saveCharIds(Event $eventObj): void {
		if (!count($this->nameHistoryCache) || $this->db->inTransaction()) {
			return;
		}
		$this->db->awaitBeginTransaction();
		try {
			foreach ($this->nameHistoryCache as $entry) {
				if ($this->db->getType() === DBType::MSSQL) {
					if ($this->db->table(NameHistory::getTable())
						->where('name', $entry->name)
						->where('charid', $entry->charid)
						->where('dimension', $this->db->getDim())
						->exists()
					) {
						continue;
					}
					$this->db->insert(new NameHistory(
						name: $entry->name,
						charid: $entry->charid,
						dimension: $this->db->getDim(),
						dt: time(),
					));
				} else {
					$this->db->table(NameHistory::getTable())
						->insertOrIgnore([
							'name' => $entry->name,
							'charid' => $entry->charid,
							'dimension' => $this->db->getDim(),
							'dt' => time(),
						]);
				}
			}
		} catch (Throwable $e) {
			$this->logger->error('Error saving lookup-cache: {error}', [
				'error' => $e->getMessage(),
				'exception' => $e,
			]);
			$this->db->rollback();
			return;
		}
		$this->db->commit();

		$this->nameHistoryCache = [];
	}

	#[
		NCA\Event(
			name: [
				'packet(20)',
				'packet(21)',
			],
			description: 'Records names and charIds'
		)
	]
	public function recordCharIds(PackageEvent $eventObj): void {
		$packet = $eventObj->packet->package;
		assert(
			($packet instanceof Package\In\CharacterName)
			|| ($packet instanceof Package\In\CharacterLookupResult),
			'Cannot record UIDs or names for packates other than lookup-results or caches'
		);
		if ($packet->charId > 0 && $packet->charId >= 4_294_967_295) {
			return;
		}
		$this->nameHistoryCache []= new CharData(
			charid: $packet->charId,
			name: $packet->name,
		);
	}

	/** Show the name(s) for a character id */
	#[NCA\HandlesCommand('lookup')]
	public function lookupIdCommand(CmdContext $context, int $charID): void {
		$name = $this->chatBot->getName($charID);
		if (isset($name)) {
			$this->saveCharIds(new TimerEvent(60));
		}

		$players = $this->db->table(NameHistory::getTable())
			->where('charid', $charID)
			->where('dimension', $this->db->getDim())
			->orderByDesc('dt')
			->asObj(NameHistory::class);
		$count = count($players);

		$blob = "<header2>Known names for {$charID}<end>\n";
		if ($count === 0) {
			$msg = "No history available for character id <highlight>{$charID}<end>. ".
				"Either that character is currently inactive, or doesn't exist.";
			$context->reply($msg);
			return;
		}
		foreach ($players as $player) {
			$link = Text::makeChatcmd($player->name, "/tell <myname> lookup {$player->name}");
			$blob .= "<tab>{$link} " . Util::date($player->dt) . "\n";
		}
		$msg = Text::makeBlob("Name History for {$charID} ({$count})", $blob);

		$context->reply($msg);
	}

	/** Show the character id for a character */
	#[NCA\HandlesCommand('lookup')]
	public function lookupNameCommand(CmdContext $context, PCharacter $char): void {
		$name = $char();

		$players = $this->db->table(NameHistory::getTable())
			->where('name', $name)
			->where('dimension', $this->db->getDim())
			->orderByDesc('dt')
			->asObj(NameHistory::class);
		$count = count($players);

		$blob = "<header2>Known IDs of {$name}<end>\n";
		if ($count === 0) {
			$msg = "No history available for character <highlight>{$name}<end>.";
			$context->reply($msg);
			return;
		}
		foreach ($players as $player) {
			$link = Text::makeChatcmd((string)$player->charid, "/tell <myname> lookup {$player->charid}");
			$blob .= "<tab>{$link} " . Util::date($player->dt) . "\n";
		}
		$msg = Text::makeBlob("Character Ids for {$name} ({$count})", $blob);

		$context->reply($msg);
	}

	public function getNameHistory(int $charID, int $dimension): string {
		$data = $this->db->table(NameHistory::getTable())
			->where('charid', $charID)
			->where('dimension', $dimension)
			->orderByDesc('dt')
			->asObj(NameHistory::class);

		$blob = "<header2>Name History<end>\n";
		if (count($data) > 0) {
			foreach ($data as $row) {
				$blob .= "<tab><highlight>{$row->name}<end> " . Util::date($row->dt) . "\n";
			}
		} else {
			$blob .= "<tab>No name history available\n";
		}

		return $blob;
	}

	/** Show character info, online status, and name history for a character */
	#[NCA\HandlesCommand('whois')]
	public function whoisNameCommand(CmdContext $context, PCharacter $char, ?int $dimension): void {
		$name = $char();
		$dimension ??= $this->config->main->dimension;
		$uid = null;
		if ($dimension === $this->config->main->dimension) {
			$uid = $this->chatBot->getUid($name);
		}
		if (isset($uid)) {
			/**
			 * @var bool    $online
			 * @var ?Player $player
			 */
			[$online, $player] = await([
				async($this->buddylistManager->checkIsOnline(...), $uid),
				async($this->playerManager->byName(...), $name, $dimension),
			]);
			$msg = $this->playerToWhois($player, $name, $online);
			$context->reply($msg);
			return;
		}
		$player = $this->playerManager->lookup($name, $dimension);
		if (!isset($player)) {
			$context->reply("Character <highlight>{$name}<end> does not exist.");
			return;
		}
		$msg = $this->playerToWhois($player, $name, false);
		$context->reply($msg);
	}

	/** Show character info, online status, and name history for a character */
	#[NCA\HandlesCommand('whois')]
	public function whoisIdCommand(CmdContext $context, int $uid): void {
		$name = $this->chatBot->getName($uid);
		if (!isset($name)) {
			$context->reply("The user ID {$uid} does not exist.");
			return;
		}

		/**
		 * @var bool    $online
		 * @var ?Player $player
		 */
		[$online, $player] = await([
			async($this->buddylistManager->checkIsOnline(...), $uid),
			async($this->playerManager->byName(...), $name),
		]);
		$msg = $this->playerToWhois($player, $name, $online);
		$context->reply($msg);
	}

	public function getFullName(Player $whois): string {
		$msg = '';

		if (isset($whois->firstname) && strlen($whois->firstname)) {
			$msg .= $whois->firstname . ' ';
		}

		$msg .= "\"{$whois->name}\"";

		if (isset($whois->lastname) && strlen($whois->lastname)) {
			$msg .= ' ' . $whois->lastname;
		}

		return $msg;
	}

	/**
	 * Determine the breakpoints where the audits indicate a new member was added/removed
	 *
	 * @param Collection<int,Audit> $audits
	 *
	 * @return Collection<int,Audit>
	 */
	private function getAuditBreakpoints(Collection $audits): Collection {
		/** @var Collection<string,Collection<int,Audit>> */
		$auditGroups = $audits->groupBy(static function (Audit $audit): string {
			return (string)$audit->time->getTimestamp();
		});
		$rank = [];

		/** @var Collection<int,Audit> */
		$result = new Collection();
		foreach ($auditGroups as $time => $audits2) {
			$wasMember = count($rank) > 0;
			$addAction = null;
			$delAction = null;
			foreach ($audits2 as $audit) {
				if (!isset($audit->value)) {
					continue;
				}
				if (!count($matches = Safe::pregMatch("/\((.+?)\)/", $audit->value))) {
					continue;
				}
				if ($audit->action === AccessManager::ADD_RANK) {
					$rank[$matches[1]] = true;
					$addAction = $audit;
				} elseif ($audit->action === AccessManager::DEL_RANK) {
					unset($rank[$matches[1]]);
					$delAction = $audit;
				}
			}
			$isMember = count($rank) > 0;
			if (!$wasMember && $isMember && isset($addAction)) {
				$result->push($addAction);
			} elseif ($wasMember && !$isMember && isset($delAction)) {
				$result->push($delAction);
			}
		}
		return $result;
	}

	private function playerToWhois(?Player $whois, string $name, bool $online): string {
		$charID = $this->chatBot->getUid($name);
		$lookupNameLink = Text::makeChatcmd('lookup', "/tell <myname> lookup {$name}");
		$historyNameLink = Text::makeChatcmd('history', "/tell <myname> history {$name}");
		$history1NameLink = Text::makeChatcmd('RK1', "/tell <myname> history {$name} 1");
		$history2NameLink = Text::makeChatcmd('RK2', "/tell <myname> history {$name} 2");
		$lookupCharIdLink = null;
		if ($charID !== null) {
			$lookupCharIdLink = Text::makeChatcmd('lookup', "/tell <myname> lookup {$charID}");
		}

		if ($whois === null) {
			$blob = "<orange>Note: Could not retrieve detailed info for character.<end>\n\n";
			$blob .= "Name: <highlight>{$name}<end> [{$lookupNameLink}] [{$historyNameLink}] [{$history1NameLink}] [{$history2NameLink}]\n";
			if (isset($lookupCharIdLink)) {
				$blob .= "Character ID: <highlight>{$charID}<end> [{$lookupCharIdLink}]\n\n";
			}
			if (is_int($charID)) {
				$blob .= $this->getNameHistory($charID, $this->config->main->dimension);
			}

			$msg = Text::makeBlob("Basic Info for {$name}", $blob);
			return $msg;
		}
		$altInfo = $this->altsController->getAltInfo($name);

		$blob = 'Name: <highlight>' . $this->getFullName($whois) . "<end> [{$lookupNameLink}] [{$historyNameLink}] [{$history1NameLink}] [{$history2NameLink}]\n";
		$nick = $altInfo->getNick();
		if (isset($nick)) {
			$blob .= "Nickname: <highlight>{$nick}<end>\n";
		}
		if (isset($whois->guild) && $whois->guild !== '') {
			$orglistLink = Text::makeChatcmd('see members', "/tell <myname> orglist {$whois->guild_id}");
			$orginfoLink = Text::makeChatcmd('info', "/tell <myname> whoisorg {$whois->guild_id}");
			$blob .= "Org: <highlight>{$whois->guild}<end> (<highlight>{$whois->guild_id}<end>) [{$orginfoLink}] [{$orglistLink}]\n";
			$blob .= "Org Rank: <highlight>{$whois->guild_rank}<end> (<highlight>{$whois->guild_rank_id}<end>)\n";
		}
		$blob .= "Breed: <highlight>{$whois->breed}<end>\n";
		$blob .= "Gender: <highlight>{$whois->gender}<end>\n";
		$blob .= "Profession: <highlight>{$whois->profession?->value}<end> (<highlight>" . trim($whois->prof_title) . "<end>)\n";
		$blob .= "Level: <highlight>{$whois->level}<end>\n";
		$blob .= "AI Level: <green>{$whois->ai_level}<end> (<highlight>{$whois->ai_rank}<end>)\n";
		$blob .= 'Faction: ' . $whois->faction->inColor() . "\n";
		$blob .= "Head Id: <highlight>{$whois->head_id}<end>\n";
		// $blob .= "PVP Rating: <highlight>{$whois->pvp_rating}<end>\n";
		// $blob .= "PVP Title: <highlight>{$whois->pvp_title}<end>\n";
		if ($whois->dimension === $this->config->main->dimension) {
			$blob .= 'Status: ';
			if ($online) {
				$blob .= "<on>Online<end>\n";
			} elseif ($charID === null) {
				$blob .= "<off>Inactive<end>\n";
			} else {
				$blob .= "<off>Offline<end>\n";
			}
		} else {
			$blob .= "Dimension: <highlight>{$whois->dimension}<end>\n";
		}
		if ($charID !== null) {
			$blob .= "Character ID: <highlight>{$charID}<end> [{$lookupCharIdLink}]\n\n";
		}

		$blob .= "Source: <highlight>{$whois->source}<end>\n\n";

		if ($charID !== null) {
			$blob .= $this->getNameHistory($charID, $this->config->main->dimension);
		}
		$main = $this->altsController->getMainOf($name);
		if ($main === $name) {
			$audits = $this->db->table(Audit::getTable())
				->where('actee', $name)
				->whereIn('action', [
					AccessManager::ADD_RANK,
					AccessManager::DEL_RANK,
				])
				->orderBy('time')
				->orderBy('id')
				->asObj(Audit::class);
			$breakPoints = $this->getAuditBreakpoints($audits);
			if ($breakPoints->isNotEmpty()) {
				/** @var Audit */
				$lastAction = $breakPoints->last();
				$blob .= "\n".
					(
						($lastAction->action === AccessManager::ADD_RANK)
						? 'Added to bot'
						: 'Removed from bot'
					) . ': <highlight>' . Util::date($lastAction->time->getTimestamp()).
					"<end> by <highlight>{$lastAction->actor}<end>";
			}
		}

		if (isset($charID)) {
			$isBanned = $this->banController->isOnBanlist($charID);
			if ($isBanned) {
				if (isset($whois->guild_id) && $this->banController->orgIsBanned($whois->guild_id)) {
					$blob .= "\n".
						"<red>{$whois->guild} is banned on this bot<end>";
				} else {
					$blob .= "\n".
						"<red>{$whois->name} is banned on this bot<end>";
				}
			}
		}

		$msg = $this->playerManager->getInfo($whois);
		if ($whois->dimension === $this->config->main->dimension) {
			if ($online) {
				$msg .= ' :: <on>Online<end>';
			} elseif ($charID === null) {
				$msg .= ' :: <off>Inactive<end>';
			} else {
				$msg .= ' :: <off>Offline<end>';
			}
		}
		$msg .= ' :: ' . Text::makeBlob('More Info', $blob, "Detailed Info for {$name}");
		if ($this->whoisAddComments) {
			$numComments = $this->commentController->countComments(null, $whois->name);
			if ($numComments) {
				$comText = ($numComments > 1) ? "{$numComments} Comments" : '1 Comment';
				$blob = Text::makeChatcmd("Read {$comText}", "/tell <myname> comments get {$whois->name}").
					' if you have the necessary access level.';
				$msg .= ' :: ' . Text::makeBlob($comText, $blob);
			}
		}

		if (count($altInfo->getAllValidatedAlts()) === 0) {
			return $msg;
		}
		$altsBlob = $altInfo->getAltsBlob(true);
		return "{$msg} :: {$altsBlob}";
	}
}
