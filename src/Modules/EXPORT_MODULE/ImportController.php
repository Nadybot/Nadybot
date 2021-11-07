<?php declare(strict_types=1);

namespace Nadybot\Modules\EXPORT_MODULE;

use Swaggest\JsonSchema\Schema;

use Nadybot\Core\{
	AccessManager,
	AdminManager,
	CommandReply,
	DB,
	LoggerWrapper,
	Modules\BAN\BanController,
	Modules\PREFERENCES\Preferences,
	Nadybot,
	Registry,
	SettingManager,
};
use Nadybot\Modules\{
	CITY_MODULE\CloakController,
	COMMENT_MODULE\Comment,
	COMMENT_MODULE\CommentCategory,
	COMMENT_MODULE\CommentController,
	GUILD_MODULE\GuildController,
	MASSMSG_MODULE\MassMsgController,
	NOTES_MODULE\Note,
	PRIVATE_CHANNEL_MODULE\PrivateChannelController,
	RAFFLE_MODULE\RaffleController,
	RAID_MODULE\AuctionController,
	RAID_MODULE\Raid,
	RAID_MODULE\RaidBlockController,
	RAID_MODULE\RaidController,
	RAID_MODULE\RaidLog,
	RAID_MODULE\RaidMember,
	RAID_MODULE\RaidMemberController,
	RAID_MODULE\RaidPoints,
	RAID_MODULE\RaidPointsController,
	RAID_MODULE\RaidRankController,
	TIMERS_MODULE\Alert,
	TIMERS_MODULE\Timer,
	TIMERS_MODULE\TimerController,
	TRACKER_MODULE\TrackerController,
	VOTE_MODULE\VoteController,
};
use Exception;
use Throwable;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'import',
 *		accessLevel = 'superadmin',
 *		description = 'Import bot data and replace the current one',
 *		help        = 'export.txt'
 *	)
 */
class ImportController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 * @var string $moduleName
	 */
	public string $moduleName;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DB $db;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Inject */
	public Preferences $preferences;

	/** @Inject */
	public AdminManager $adminManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public BanController $banController;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public CommentController $commentController;

	/** @Inject */
	public RaidRankController $raidRankController;

	protected function loadAndParseExportFile(string $fileName, CommandReply $sendto): ?object {
		if (!@file_exists($fileName)) {
			$sendto->reply("No export file <highlight>{$fileName}<end> found.");
			return null;
		}
		$this->logger->log("INFO", "Decoding the JSON data");
		try {
			$import = json_decode(file_get_contents($fileName), false, 512, JSON_THROW_ON_ERROR);
		} catch (Throwable $e) {
			$sendto->reply("Error decoding <highlight>{$fileName}<end>.");
			return null;
		}
		if (!is_object($import)) {
			$sendto->reply("The file <highlight>{$fileName}<end> is not a valid export file.");
			return null;
		}
		$this->logger->log("INFO", "Loading schema data");
		$schema = Schema::import("https://hodorraid.org/export-schema.json");
		$this->logger->log("INFO", "Validating import data against the schema");
		$sendto->reply("Validating the import data. This could take a while.");
		try {
			$schema->in($import);
		} catch (Exception $e) {
			$sendto->reply("The import data is not valid: <highlight>" . $e->getMessage() . "<end>.");
			return null;
		}
		return $import;
	}

	/**
	 * @HandlesCommand("import")
	 * @Matches("/^import (.+?)((?: \w+=\w+)*)$/i")
	 */
	public function importCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$dataPath = $this->chatBot->vars["datafolder"] ?? "./data";
		$fileName = "{$dataPath}/export/" . basename($args[1]);
		if ((pathinfo($fileName)["extension"] ?? "") !== "json") {
			$fileName .= ".json";
		}
		if (!@file_exists($fileName)) {
			$sendto->reply("No export file <highlight>{$fileName}<end> found.");
			return;
		}
		$import = $this->loadAndParseExportFile($fileName, $sendto);
		if (!isset($import)) {
			return;
		}
		$usedRanks = $this->getRanks($import);
		$rankMapping = $this->parseRankMapping($args[2]);
		foreach ($usedRanks as $rank) {
			if (!isset($rankMapping[$rank])) {
				$sendto->reply("Please define a mapping for <highlight>{$rank}<end> by appending '{$rank}=&lt;rank&gt;' to your command");
				return;
			} else {
				try {
					$rankMapping[$rank] = $this->accessManager->getAccessLevel($rankMapping[$rank]);
				} catch (Exception $e) {
					$sendto->reply("<highlight>{$rankMapping[$rank]}<end> is not a valid access level");
					return;
				}
			}
		}
		$this->logger->log("INFO", "Starting import");
		$sendto->reply("Starting import...");
		$importMap = $this->getImportMapping();
		foreach ($importMap as $key => $func) {
			if (!isset($import->{$key})) {
				continue;
			}
			$func($import->{$key}, $rankMapping);
		}
		$this->logger->log("INFO", "Import done");
		$sendto->reply("The import finished successfully.");
	}

	protected function getImportMapping(): array {
		return [
			"members"           => [$this, "importMembers"],
			"alts"              => [$this, "importAlts"],
			"auctions"          => [$this, "importAuctions"],
			"banlist"           => [$this, "importBanlist"],
			"cityCloak"         => [$this, "importCloak"],
			"commentCategories" => [$this, "importCommentCategories"],
			"comments"          => [$this, "importComments"],
			"links"             => [$this, "importLinks"],
			"news"              => [$this, "importNews"],
			"notes"             => [$this, "importNotes"],
			"polls"             => [$this, "importPolls"],
			"quotes"            => [$this, "importQuotes"],
			"raffleBonus"       => [$this, "importRaffleBonus"],
			"raidBlocks"        => [$this, "importRaidBlocks"],
			"raids"             => [$this, "importRaids"],
			"raidPoints"        => [$this, "importRaidPoints"],
			"raidPointsLog"     => [$this, "importRaidPointsLog"],
			"timers"            => [$this, "importTimers"],
			"trackedCharacters" => [$this, "importTrackedCharacters"],
		];
	}

	protected function parseRankMapping(string $input): array {
		$mapping = [];
		$input = trim($input);
		$parts = preg_split("/\s+/", $input);
		foreach ($parts as $part) {
			[$key, $value] = explode("=", $part);
			$mapping[$key] = $value;
		}
		return $mapping;
	}

	/**
	 * @return string[]
	 */
	protected function getRanks(object $import): array {
		$ranks = [];
		foreach ($import->members??[] as $member) {
			$ranks[$member->rank] = true;
		}
		foreach ($import->commentCategories??[] as $category) {
			if (isset($category->minRankToRead)) {
				$ranks[$category->minRankToRead] = true;
			}
			if (isset($category->minRankToWrite)) {
				$ranks[$category->minRankToWrite] = true;
			}
		}
		foreach ($import->polls??[] as $poll) {
			if (isset($poll->minRankToVote)) {
				$ranks[$poll->minRankToVote] = true;
			}
		}
		return array_keys($ranks);
	}

	protected function characterToName(?object $char): ?string {
		if (!isset($char)) {
			return null;
		}
		$name = $char->name ?? $this->chatBot->lookupID($char->id);
		if (!isset($name)) {
			$this->logger->log("INFO", "Unable to find a name for UID {$char->id}");
		}
		return $name;
	}

	public function importAlts(array $alts): void {
		$this->logger->log("INFO", "Importing alts for " . count($alts) . " character(s)");
		$numImported = 0;
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all alts");
			$this->db->table("alts")->truncate();
			foreach ($alts as $altData) {
				$mainName = $this->characterToName($altData->main);
				if (!isset($mainName)) {
					continue;
				}
				foreach ($altData->alts as $alt) {
					$numImported += $this->importAlt($mainName, $alt);
				}
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "{$numImported} alt(s) imported");
	}

	protected function importAlt(string $mainName, object $alt): int {
		$altName = $this->characterToName($alt->alt);
		if (!isset($altName)) {
			return 0;
		}
		$this->db->table("alts")
			->insert([
				"alt" => $altName,
				"main" => $mainName,
				"validated_by_main" => $alt->validatedByMain ?? true,
				"validated_by_alt" => $alt->validatedByAlt ?? true,
				"added_via" => $this->db->getMyname(),
			]);
		return 1;
	}

	public function importAuctions(array $auctions): void {
		$this->logger->log("INFO", "Importing " . count($auctions) . " auction(s)");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all auctions");
			$this->db->table(AuctionController::DB_TABLE)->truncate();
			foreach ($auctions as $auction) {
				$this->db->table(AuctionController::DB_TABLE)
					->insert([
						"raid_id" => $auction->raidId ?? null,
						"item" => $auction->item,
						"auctioneer" => $this->characterToName($auction->startedBy??null) ?? $this->chatBot->char->name,
						"cost" => ($auction->cost ?? null) ? (int)round($auction->cost, 0) : null,
						"winner" => $this->characterToName($auction->winner??null),
						"end" => $auction->timeEnd ?? time(),
						"reimbursed" => $auction->reimbursed ?? false,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All auctions imported");
	}

	public function importBanlist(array $banlist): void {
		$numImported = 0;
		$this->logger->log("INFO", "Importing " . count($banlist) . " ban(s)");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all bans");
			$this->db->table(BanController::DB_TABLE)->truncate();
			foreach ($banlist as $ban) {
				$id = $ban->character->id ?? $this->chatBot->get_uid($ban->character->name);
				if (!isset($id)) {
					continue;
				}
				$this->db->table(BanController::DB_TABLE)
				->insert([
					"charid" => $id,
					"admin" => $this->characterToName($ban->bannedBy ?? null) ?? $this->chatBot->char->name,
					"time" => $ban->banStart ?? time(),
					"reason" => $ban->banReason ?? "None given",
					"banend" => $ban->banEnd ?? 0,
				]);
				$numImported++;
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->banController->uploadBanlist();
		$this->logger->log("INFO", "{$numImported} bans successfully imported");
	}

	public function importCloak(array $cloakActions): void {
		$this->logger->log("INFO", "Importing " . count($cloakActions) . " cloak action(s)");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all cloak actions");
			$this->db->table(CloakController::DB_TABLE)->truncate();
			foreach ($cloakActions as $action) {
				$this->db->table(CloakController::DB_TABLE)
					->insert([
						"time" => $action->time ?? null,
						"action" => $action->cloakOn ? "on" : "off",
						"player" => $this->characterToName($action->character??null) ?? $this->chatBot->char->name,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All cloak actions imported");
	}

	public function importLinks(array $links): void {
		$this->logger->log("INFO", "Importing " . count($links) . " links");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all links");
			$this->db->table("links")->truncate();
			foreach ($links as $link) {
				$this->db->table("links")
					->insert([
						"name" => $this->characterToName($link->createdBy??null) ?? $this->chatBot->char->name,
						"website" => $link->url,
						"comments" => $link->description ?? "",
						"dt" => $link->creationTime ?? null,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All links imported");
	}

	protected function getMappedRank(array $mapping, string $rank): ?string {
		return $mapping[$rank] ?? null;
	}

	public function importMembers(array $members, array $rankMap=[]): void {
		$numImported = 0;
		$this->logger->log("INFO", "Importing " . count($members) . " member(s)");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all members");
			$this->db->table(PrivateChannelController::DB_TABLE)->truncate();
			$this->db->table(GuildController::DB_TABLE)->truncate();
			$this->db->table(AdminManager::DB_TABLE)->truncate();
			$this->db->table(RaidRankController::DB_TABLE)->truncate();
			foreach ($members as $member) {
				$id = $member->character->id ?? $this->chatBot->get_uid($member->character->name);
				$name = $this->characterToName($member->character);
				if (!isset($id) || !isset($name)) {
					continue;
				}
				$this->chatBot->id[$id] = $name;
				$this->chatBot->id[$name] = $id;
				$newRank = $this->getMappedRank($rankMap, $member->rank);
				if (!isset($newRank)) {
					throw new Exception("Cannot find rank {$member->rank} in the mapping");
				}
				$numImported++;
				if (in_array($newRank, ["member", "mod", "admin", "superadmin"], true)
					|| preg_match("/^raid_(leader|admin)_[123]$/", $newRank)
				) {
					$this->db->table(PrivateChannelController::DB_TABLE)
						->insert([
							"name" => $name,
							"autoinv" => $member->autoInvite ?? false,
						]);
				}
				if (in_array($newRank, ["mod", "admin", "superadmin"], true)) {
					$adminLevel = ($newRank === "mod") ? 3 : 4;
					$this->db->table(AdminManager::DB_TABLE)
						->insert([
							"name" => $name,
							"adminlevel" => $adminLevel,
						]);
					$this->adminManager->admins[$name] = ["level" => $adminLevel];
				} elseif (preg_match("/^raid_leader_([123])/", $newRank, $matches)) {
					$this->db->table(RaidRankController::DB_TABLE)
						->insert([
							"name" => $name,
							"rank" => $matches[1] + 3
						]);
				} elseif (preg_match("/^raid_admin_([123])/", $newRank, $matches)) {
					$this->db->table(RaidRankController::DB_TABLE)
						->insert([
							"name" => $name,
							"rank" => $matches[1] + 6
						]);
				} elseif (in_array($newRank, ["rl", "all"])) {
					// Nothing, we just ignore that
				}
				if (isset($member->logonMessage)) {
					$this->preferences->save($name, "logon_msg", $member->logonMessage);
				}
				if (isset($member->logoffMessage)) {
					$this->preferences->save($name, "logoff_msg", $member->logoffMessage);
				}
				if (isset($member->receiveMassInvites)) {
					$this->preferences->save($name, MassMsgController::PREF_INVITES, $member->receiveMassInvites ? "on" : "off");
				}
				if (isset($member->receiveMassMessages)) {
					$this->preferences->save($name, MassMsgController::PREF_MSGS, $member->receiveMassMessages ? "on" : "off");
				}
			}
			$this->raidRankController->uploadRaidRanks();
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "{$numImported} members successfully imported");
	}

	public function importNews(array $news): void {
		$this->logger->log("INFO", "Importing " . count($news) . " news");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all news");
			$this->db->table("news_confirmed")->truncate();
			$this->db->table("news")->truncate();
			foreach ($news as $item) {
				$newsId = $this->db->table("news")
				->insertGetId([
					"time" => $item->addedTime ?? time(),
					"name" => $this->characterToName($item->author ?? null) ?? $this->chatBot->char->name,
					"news" => $item->news,
					"sticky" => $item->pinned ?? false,
					"deleted" => $item->deleted ?? false,
				]);
				foreach ($item->confirmedBy??[] as $confirmation) {
					$name = $this->characterToName($confirmation->character??null);
					if (!isset($name)) {
						continue;
					}
					$this->db->table("news_confirmed")
						->insert([
							"id" => $newsId,
							"player" => $name,
							"time" => $confirmation->confirmationTime ?? time(),
						]);
				}
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All news imported");
	}

	public function importNotes(array $notes): void {
		$this->logger->log("INFO", "Importing " . count($notes) . " notes");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all notes");
			$this->db->table("notes")->truncate();
			foreach ($notes as $note) {
				$owner = $this->characterToName($note->owner??null);
				if (!isset($owner)) {
					continue;
				}
				$reminder = $note->remind ?? null;
				$reminderInt = ($reminder === "all")
					? Note::REMIND_ALL
					: (($reminder === "author")
						? Note::REMIND_SELF
						: Note::REMIND_NONE);
				$this->db->table("notes")
				->insert([
					"owner" => $owner,
					"added_by" => $this->characterToName($note->author ?? null) ?? $owner,
					"note" => $note->text,
					"dt" => $note->creationTime ?? null,
					"reminder" => $reminderInt,
				]);
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All notes imported");
	}

	public function importPolls(array $polls): void {
		$this->logger->log("INFO", "Importing " . count($polls) . " polls");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all polls");
			$this->db->table(VoteController::DB_VOTES)->truncate();
			$this->db->table(VoteController::DB_POLLS)->truncate();
			foreach ($polls as $poll) {
				$pollId = $this->db->table(VoteController::DB_POLLS)
					->insertGetId([
						"author" => $this->characterToName($poll->author??null) ?? $this->chatBot->char->name,
						"question" => $poll->question,
						"possible_answers" => json_encode(
							array_map(
								function(object $answer): string {
									return $answer->answer;
								},
								$poll->answers??[]
							),
						),
						"started" => $poll->startTime ?? time(),
						"duration" => ($poll->endTime ?? time()) - ($poll->startTime ?? time()),
						"status" => VoteController::STATUS_STARTED
					]);
				foreach ($poll->answers??[] as $answer) {
					foreach ($answer->votes??[] as $vote) {
						$this->db->table(VoteController::DB_VOTES)
							->insert([
								"poll_id" => $pollId,
								"author" => $this->characterToName($vote->character??null) ?? "Unknown",
								"answer" => $answer->answer,
								"time" => $vote->voteTime ?? time(),
							]);
					}
				}
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All polls imported");
	}

	public function importQuotes(array $quotes): void {
		$this->logger->log("INFO", "Importing " . count($quotes) . " quotes");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all quotes");
			$this->db->table("quote")->truncate();
			foreach ($quotes as $quote) {
				$this->db->table("quote")
					->insert([
						"poster" => $this->characterToName($quote->contributor??null) ?? $this->chatBot->char->name,
						"dt" => $quote->time??time(),
						"msg" => $quote->quote,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All quotes imported");
	}

	public function importRaffleBonus(array $bonuses): void {
		$this->logger->log("INFO", "Importing " . count($bonuses) . " raffle bonuses");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all raffle bonuses");
			$this->db->table(RaffleController::DB_TABLE)->truncate();
			foreach ($bonuses as $bonus) {
				$name = $this->characterToName($bonus->character??null);
				if (!isset($name)) {
					continue;
				}
				$this->db->table(RaffleController::DB_TABLE)
					->insert([
						"name" => $name,
						"bonus" => $bonus->raffleBonus,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All raffle bonuses imported");
	}

	public function importRaidBlocks(array $blocks): void {
		$this->logger->log("INFO", "Importing " . count($blocks) . " raid blocks");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all raid blocks");
			$this->db->table(RaidBlockController::DB_TABLE)->truncate();
			foreach ($blocks as $block) {
				$name = $this->characterToName($block->character??null);
				if (!isset($name)) {
					continue;
				}
				$this->db->table(RaidBlockController::DB_TABLE)
					->insert([
						"player" => $name,
						"blocked_from" => $block->blockedFrom,
						"blocked_by" => $this->characterToName($block->blockedBy??null) ?? $this->chatBot->char->name,
						"reason" => $block->blockedReason ?? "No reason given",
						"time" => $block->blockStart ?? time(),
						"expiration" => $block->blockEnd ?? null,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All raid blocks imported");
	}

	public function importRaids(array $raids): void {
		$this->logger->log("INFO", "Importing " . count($raids) . " raids");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all raids");
			$this->db->table(RaidController::DB_TABLE)->truncate();
			$this->db->table(RaidController::DB_TABLE_LOG)->truncate();
			$this->db->table(RaidMemberController::DB_TABLE)->truncate();
			foreach ($raids as $raid) {
				$entry = new Raid();
				$historyEntry = new RaidLog();
				$history = $raid->history ?? [];
				usort(
					$history,
					function (object $o1, object $o2): int {
						return $o1->time <=> $o2->time;
					}
				);
				$lastEntry = end($history);
				$historyEntry->description = $entry->description = $raid->raidDescription ?? "No description";
				$historyEntry->seconds_per_point = $entry->seconds_per_point = $raid->raidSecondsPerPoint ?? 0;
				$historyEntry->announce_interval = $entry->announce_interval = $raid->raidAnnounceInterval ?? $this->settingManager->getInt('raid_announcement_interval');
				$historyEntry->locked = $entry->locked = $raid->raidLocked ?? false;
				$entry->started = $raid->time ?? time();
				$entry->started_by = $this->chatBot->char->name;
				$entry->stopped = $lastEntry ? $lastEntry->time : $entry->started;
				$entry->stopped_by = $this->chatBot->char->name;
				$raidId = $this->db->insert(RaidController::DB_TABLE, $entry, "raid_id");
				$historyEntry->raid_id = $raidId;
				foreach ($raid->raiders??[] as $raider) {
					$name = $this->characterToName($raider->character);
					if (!isset($name)) {
						continue;
					}
					$raiderEntry = new RaidMember();
					$raiderEntry->raid_id = $raidId;
					$raiderEntry->player = $name;
					$raiderEntry->joined = $raider->joinTime ?? time();
					$raiderEntry->left = $raider->leaveTime ?? time();
					$this->db->insert(RaidMemberController::DB_TABLE, $raiderEntry, null);
				}
				$historyEntry->time = time();
				foreach ($history as $state) {
					$historyEntry->time = $state->time;
					if (isset($state->raidDescription)) {
						$historyEntry->description = $state->raidDescription;
					}
					if (isset($state->raidLocked)) {
						$historyEntry->locked = $state->raidLocked;
					}
					if (isset($state->raidAnnounceInterval)) {
						$historyEntry->announce_interval = $state->raidAnnounceInterval;
					}
					if (isset($state->raidSecondsPerPoint)) {
						$historyEntry->seconds_per_point = $state->raidSecondsPerPoint;
					}
					$this->db->insert(RaidController::DB_TABLE_LOG, $historyEntry, null);
				}
				if (!count($history)) {
					$this->db->insert(RaidController::DB_TABLE_LOG, $historyEntry, null);
				}
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All raids imported");
	}

	public function importRaidPoints(array $points): void {
		$this->logger->log("INFO", "Importing " . count($points) . " raid points");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all raid points");
			$this->db->table(RaidPointsController::DB_TABLE)->truncate();
			foreach ($points as $point) {
				$name = $this->characterToName($point->character??null);
				if (!isset($name)) {
					continue;
				}
				$entry = new RaidPoints();
				$entry->username = $name;
				$entry->points = $point->raidPoints;
				$this->db->insert(RaidPointsController::DB_TABLE, $entry, null);
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All raid points imported");
	}

	public function importRaidPointsLog(array $points): void {
		$this->logger->log("INFO", "Importing " . count($points) . " raid point logs");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all raid point logs");
			$this->db->table(RaidPointsController::DB_TABLE_LOG)->truncate();
			foreach ($points as $point) {
				$name = $this->characterToName($point->character??null);
				if (!isset($name) || $point->raidPoints === 0) {
					continue;
				}
				$this->db->table(RaidPointsController::DB_TABLE_LOG)
					->insert([
						"username" => $name,
						"delta" => $point->raidPoints,
						"time" => $point->time ?? time(),
						"changed_by" => $this->characterToName($point->givenBy ??null) ?? $this->chatBot->char->name,
						"individual" => $point->givenIndividually ?? true,
						"raid_id" => $point->raidId ?? null,
						"reason" => $point->reason ?? "Raid participation",
						"ticker" => $point->givenByTick ?? false,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All raid point logs imported");
	}

	protected function channelsToMode(array $channels): string {
		$modes = [
			"org" => "guild",
			"tell" => "msg",
			"priv" => "priv",
			"discord" => "discord",
			"irc" => "irc",
		];
		$result = [];
		foreach ($channels as $channel) {
			if (isset($modes[$channel])) {
				$result []= $modes[$channel];
			}
		}
		return join(",", $result);
	}

	public function importTimers(array $timers): void {
		$table = Registry::getInstance("timercontroller")::DB_TABLE;
		$this->logger->log("INFO", "Importing " . count($timers) . " timers");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all timers");
			$this->db->table($table)->truncate();
			$timerNum = 1;
			foreach ($timers as $timer) {
				$entry = new Timer();
				$entry->owner = $this->characterToName($timer->createdBy??null) ?? $this->chatBot->char->name;
				$entry->data = $timer->repeatInterval ? (string)$timer->repeatInterval : null;
				$entry->mode = $this->channelsToMode($timer->channels??[]);
				$entry->name = $timer->timerName ?? $this->characterToName($timer->createdBy??null) ?? $this->chatBot->char->name . "-{$timerNum}";
				$entry->endtime = $timer->endTime;
				$entry->callback = $entry->data ? "timercontroller.repeatingTimerCallback" : "timercontroller.timerCallback";
				$entry->alerts = [];
				foreach ($timer->alerts??[] as $alert) {
					$alertEntry = new Alert();
					$alertEntry->message = $alert->message ?? "Timer <highlight>{$entry->name}<end> has gone off.";
					$alertEntry->time = $alert->time;
					$entry->alerts []= $alertEntry;
				}
				if (!count($entry->alerts)) {
					$alertEntry = new Alert();
					$alertEntry->message = "Timer <highlight>{$entry->name}<end> has gone off.";
					$alertEntry->time = $entry->endtime;
					$entry->alerts []= $alertEntry;
				}
				$this->db->table($table)
					->insert([
						"name" => $entry->name,
						"owner" => $entry->owner,
						"mode" => $entry->mode,
						"endtime" => $entry->endtime,
						"settime" => $timer->startTime ?? time(),
						"callback" => $entry->callback,
						"data" => $entry->data,
						"alerts" => json_encode($entry->alerts)
					]);
				$timerNum++;
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All timers imported");
	}

	public function importTrackedCharacters(array $trackedUsers): void {
		$this->logger->log("INFO", "Importing " . count($trackedUsers) . " tracked users");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all tracked users");
			$this->db->table(TrackerController::DB_TABLE)->truncate();
			foreach ($trackedUsers as $trackedUser) {
				$name = $this->characterToName($trackedUser->character??null);
				if (!isset($name)) {
					continue;
				}
				$id = $trackedUser->character->id ? $trackedUser->character->id : $this->chatBot->get_uid($name);
				if (!isset($id) || $id === false) {
					continue;
				}
				$this->db->table(TrackerController::DB_TABLE)
					->insert([
						"uid" => $id,
						"name" => $name,
						"added_by" => $this->characterToName($trackedUser->addedBy??null) ?? $this->chatBot->char->name,
						"added_dt" => $trackedUser->addedTime ?? time(),
					]);
				foreach ($trackedUser->events??[] as $event) {
					$this->db->table(TrackerController::DB_TRACKING)
						->insert([
							"uid" => $id,
							"dt" => $event->time,
							"event" => $event->event,
						]);
				}
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All raid blocks imported");
	}

	public function importCommentCategories(array $categories, array $rankMap): void {
		$this->logger->log("INFO", "Importing " . count($categories) . " comment categories");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all user-managed comment categories");
			$this->db->table("<table:comment_categories>")
				->where("user_managed", true)
				->delete();
			foreach ($categories as $category) {
				$oldEntry = $this->commentController->getCategory($category->name);
				$entry = new CommentCategory();
				$entry->name = $category->name;
				$entry->created_by = $this->characterToName($category->createdBy ??null) ?? $this->chatBot->char->name;
				$entry->created_at = $category->createdAt ?? time();
				$entry->min_al_read = $this->getMappedRank($rankMap, $category->minRankToRead) ?? "mod";
				$entry->min_al_write = $this->getMappedRank($rankMap, $category->minRankToWrite) ?? "admin";
				$entry->user_managed = isset($oldEntry) ? $oldEntry->user_managed : !($category->systemEntry ?? false);
				if (isset($oldEntry)) {
					$this->db->update("<table:comment_categories>", "name", $entry);
				} else {
					$this->db->insert("<table:comment_categories>", $entry, null);
				}
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All comment categories imported");
	}

	public function importComments(array $comments): void {
		$this->logger->log("INFO", "Importing " . count($comments) . " comment(s)");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all comments");
			$this->db->table("<table:comments>")->truncate();
			foreach ($comments as $comment) {
				$name = $this->characterToName($comment->targetCharacter);
				if (!isset($name)) {
					continue;
				}
				$entry = new Comment();
				$entry->comment = $comment->comment;
				$entry->character = $name;
				$entry->created_by = $this->characterToName($comment->createdBy ??null) ?? $this->chatBot->char->name;
				$entry->created_at = $comment->createdAt ?? time();
				$entry->category = $comment->category ?? "admin";
				if ($this->commentController->getCategory($entry->category) === null) {
					$cat = new CommentCategory();
					$cat->name = $entry->category;
					$cat->created_by = $this->chatBot->char->name;
					$cat->created_at = time();
					$cat->min_al_read = "mod";
					$cat->min_al_write = "admin";
					$cat->user_managed = true;
					$this->db->insert("<table:comment_categories>", $cat, null);
				}
				$this->db->insert("<table:comments>", $entry);
			}
		} catch (Throwable $e) {
			$this->logger->log("ERROR", $e->getMessage(), $e);
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All comments imported");
	}
}
