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
	SettingManager,
	SQLException,
};
use Nadybot\Modules\{
	NOTES_MODULE\Note,
	COMMENT_MODULE\CommentCategory,
	COMMENT_MODULE\CommentController,
	RAID_MODULE\Raid,
	RAID_MODULE\RaidLog,
	RAID_MODULE\RaidMember,
	RAID_MODULE\RaidPoints,
	RAID_MODULE\RaidPointsLog,
	RAID_MODULE\RaidRankController,
	TIMERS_MODULE\Alert,
	TIMERS_MODULE\Timer,
	VOTE_MODULE\VoteController,
};
use Exception;
use Nadybot\Modules\COMMENT_MODULE\Comment;
use Nadybot\Modules\MASSMSG_MODULE\MassMsgController;
use Nadybot\Modules\RAID_MODULE\RaidRank;
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
			$this->db->exec("DELETE FROM `alts`");
			foreach ($alts as $altData) {
				$mainName = $this->characterToName($altData->main);
				if (!isset($mainName)) {
					continue;
				}
				foreach ($altData->alts as $alt) {
					$numImported += $this->importAlt($mainName, $alt);
				}
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
		$this->db->exec(
			"INSERT INTO `alts`(`alt`, `main`, `validated_by_main`, `validated_by_alt`, `added_via`) " .
			"VALUES (?, ?, ?, ?, '<Myname>')",
			$altName,
			$mainName,
			$alt->validatedByMain ?? true,
			$alt->validatedByAlt ?? true
		);
		return 1;
	}

	public function importAuctions(array $auctions): void {
		$this->logger->log("INFO", "Importing " . count($auctions) . " auction(s)");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all auctions");
			$this->db->exec("DELETE FROM `auction_<myname>`");
			foreach ($auctions as $auction) {
				$this->db->exec(
					"INSERT INTO `auction_<myname>`(`raid_id`, `item`, `auctioneer`, `cost`, `winner`, `end`, `reimbursed`) ".
					"VALUES (?, ?, ?, ?, ?, ?, ?)",
					$auction->raidId ?? null,
					$auction->item,
					$this->characterToName($auction->startedBy??null) ?? $this->chatBot->vars["name"],
					($auction->cost ?? null) ? (int)round($auction->cost, 0) : null,
					$this->characterToName($auction->winner??null),
					$auction->timeEnd ?? time(),
					$auction->reimbursed ?? false
				);
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `banlist_<myname>`");
			foreach ($banlist as $ban) {
				$id = $ban->character->id ?? $this->chatBot->get_uid($ban->character->name);
				if (!isset($id)) {
					continue;
				}
				$this->db->exec(
					"INSERT INTO `banlist_<myname>`(`charid`, `admin`, `time`, `reason`, `banend`) ".
					"VALUES (?, ?, ?, ?, ?)",
					$id,
					$this->characterToName($ban->bannedBy??null) ?? $this->chatBot->vars["name"],
					$ban->banStart ?? time(),
					$ban->banReason ?? "None given",
					$ban->banEnd ?? 0,
				);
				$numImported++;
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `org_city_<myname>`");
			foreach ($cloakActions as $action) {
				$this->db->exec(
					"INSERT INTO `org_city_<myname>`(`time`, `action`, `player`) ".
					"VALUES (?, ?, ?)",
					$action->time ?? null,
					$action->cloakOn ? "on" : "off",
					$this->characterToName($action->character??null) ?? $this->chatBot->vars["name"],
				);
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `links`");
			foreach ($links as $link) {
				$this->db->exec(
					"INSERT INTO `links`(`name`, `website`, `comments`, `dt`) ".
					"VALUES (?, ?, ?, ?)",
					$this->characterToName($link->createdBy??null) ?? $this->chatBot->vars["name"],
					$link->url,
					$link->description ?? "",
					$link->creationTime ?? null,
				);
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `members_<myname>`");
			$this->db->exec("DELETE FROM `org_members_<myname>`");
			$this->db->exec("DELETE FROM `admin_<myname>`");
			$this->db->exec("DELETE FROM `raid_rank_<myname>`");
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
					$this->db->exec(
						"INSERT INTO `members_<myname>`(`name`, `autoinv`) ".
						"VALUES (?, ?)",
						$name,
						$member->autoInvite ?? false
					);
				}
				if (in_array($newRank, ["mod", "admin", "superadmin"], true)) {
					$adminLevel = ($newRank === "mod") ? 3 : 4;
					$this->db->exec(
						"INSERT INTO `admin_<myname>`(`name`, `adminlevel`) ".
						"VALUES (?, ?)",
						$name,
						$adminLevel,
					);
					$this->adminManager->admins[$name] = $adminLevel;
				} elseif (preg_match("/^raid_leader_([123])/", $newRank, $matches)) {
					$this->db->exec(
						"INSERT INTO `raid_rank_<myname>`(`name`, `rank`) ".
						"VALUES (?, ?)",
						$name,
						$matches[1] + 3
					);
				} elseif (preg_match("/^raid_admin_([123])/", $newRank, $matches)) {
					$this->db->exec(
						"INSERT INTO `raid_rank_<myname>`(`name`, `rank`) ".
						"VALUES (?, ?)",
						$name,
						$matches[1] + 6
					);
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
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `news_confirmed`");
			$this->db->exec("DELETE FROM `news`");
			foreach ($news as $item) {
				$this->db->exec(
					"insert into `news`(`time`, `name`, `news`, `sticky`, `deleted`) ".
					"values (?, ?, ?, ?, ?)",
					$item->addedTime ?? time(),
					$this->characterToName($item->author??null) ?? $this->chatbot->vars["name"],
					$item->news,
					$item->pinned ?? false,
					$item->deleted ?? false
				);
				$newsId = $this->db->lastInsertId();
				foreach ($item->confirmedBy??[] as $confirmation) {
					$name = $this->characterToName($confirmation->character??null);
					if (!isset($name)) {
						continue;
					}
					$this->db->exec(
						"INSERT INTO `news_confirmed`(`id`, `player`, `time`) ".
						"VALUES (?, ?, ?)",
						$newsId,
						$name,
						$confirmation->confirmationTime ?? time(),
					);
				}
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `notes`");
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
				$this->db->exec(
					"INSERT INTO `notes`(`owner`, `added_by`, `note`, `dt`, `reminder`) ".
					"VALUES (?, ?, ?, ?, ?)",
					$owner,
					$this->characterToName($note->author??null) ?? $owner,
					$note->text,
					$note->creationTime ?? null,
					$reminderInt
				);
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `votes_<myname>`");
			$this->db->exec("DELETE FROM `polls_<myname>`");
			foreach ($polls as $poll) {
				$this->db->exec(
					"INSERT INTO `polls_<myname>`(`author`, `question`, `possible_answers`, `started`, `duration`, `status`) ".
					"VALUES (?, ?, ?, ?, ?, ?)",
					$this->characterToName($poll->author??null) ?? $this->chatbot->vars["name"],
					$poll->question,
					json_encode(
						array_map(
							function(object $answer): string {
								return $answer->answer;
							},
							$poll->answers??[]
						),
					),
					$poll->startTime ?? time(),
					($poll->endTime ?? time()) - ($poll->startTime ?? time()),
					VoteController::STATUS_STARTED
				);
				$pollId = $this->db->lastInsertId();
				foreach ($poll->answers??[] as $answer) {
					foreach ($answer->votes??[] as $vote) {
						$this->db->exec(
							"INSERT INTO `votes_<myname>`(`poll_id`, `author`, `answer`, `time`) ".
							"VALUES (?, ?, ?, ?)",
							$pollId,
							$this->characterToName($vote->character??null) ?? "Unknown",
							$answer->answer,
							$vote->voteTime ?? time()
						);
					}
				}
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `quote`");
			foreach ($quotes as $quote) {
				$this->db->exec(
					"INSERT INTO `quote`(`poster`, `dt`, `msg`) ".
					"VALUES (?, ?, ?)",
					$this->characterToName($quote->contributor??null) ?? $this->chatBot->vars["name"],
					$quote->time??time(),
					$quote->quote
				);
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `raffle_bonus_<myname>`");
			foreach ($bonuses as $bonus) {
				$name = $this->characterToName($bonus->character??null);
				if (!isset($name)) {
					continue;
				}
				$this->db->exec(
					"INSERT INTO `raffle_bonus_<myname>`(`name`, `bonus`) ".
					"VALUES (?, ?, ?)",
					$name,
					$bonus->raffleBonus
				);
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `raid_block_<myname>`");
			foreach ($blocks as $block) {
				$name = $this->characterToName($block->character??null);
				if (!isset($name)) {
					continue;
				}
				$this->db->exec(
					"INSERT INTO `raid_block_<myname>`(`player`, `blocked_from`, `blocked_by`, `reason`, `time`, `expiration`) ".
					"VALUES (?, ?, ?, ?, ? ,?)",
					$name,
					$block->blockedFrom,
					$this->characterToName($block->blockedBy??null) ?? $this->chatBot->vars["name"],
					$block->blockedReason ?? "No reason given",
					$block->blockStart ?? time(),
					$block->blockEnd ?? null
				);
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `raid_<myname>`");
			$this->db->exec("DELETE FROM `raid_log_<myname>`");
			$this->db->exec("DELETE FROM `raid_member_<myname>`");
			foreach ($raids as $raid) {
				$entry = new Raid();
				$historyEntry = new RaidLog();
				if (isset($raid->raidId)) {
					$entry->raid_id = $raid->raidId;
				}
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
				$entry->started_by = $this->chatBot->vars["name"];
				$entry->stopped = $lastEntry ? $lastEntry->time : $entry->started;
				$entry->stopped_by = $this->chatBot->vars["name"];
				$this->db->insert("raid_<myname>", $entry);
				$historyEntry->raid_id = $raidId = $entry->raid_id ?? $this->db->lastInsertId();
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
					$this->db->insert("raid_member_<myname>", $raiderEntry);
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
					$this->db->insert("raid_log_<myname>", $historyEntry);
				}
				if (!count($history)) {
					$this->db->insert("raid_log_<myname>", $historyEntry);
				}
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `raid_points_<myname>`");
			foreach ($points as $point) {
				$name = $this->characterToName($point->character??null);
				if (!isset($name)) {
					continue;
				}
				$entry = new RaidPoints();
				$entry->username = $name;
				$entry->points = $point->raidPoints;
				$this->db->insert("raid_points_<myname>", $entry);
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `raid_points_log_<myname>`");
			foreach ($points as $point) {
				$name = $this->characterToName($point->character??null);
				if (!isset($name) || $point->raidPoints === 0) {
					continue;
				}
				$entry = new RaidPointsLog();
				$entry->username = $name;
				$entry->delta = $point->raidPoints;
				$entry->time = $point->time ?? time();
				$entry->changed_by = $this->characterToName($point->givenBy ??null) ?? $this->chatBot->vars["name"];
				$entry->individual = $point->givenIndividually ?? true;
				$entry->raid_id = $point->raidId ?? null;
				$entry->reason = $point->reason ?? "Raid participation";
				$entry->ticker = $point->givenByTick ?? false;
				$this->db->insert("raid_points_log_<myname>", $entry);
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
		$this->logger->log("INFO", "Importing " . count($timers) . " timers");
		$this->db->beginTransaction();
		try {
			$this->logger->log("INFO", "Deleting all timers");
			$this->db->exec("DELETE FROM `timers_<myname>`");
			$timerNum = 1;
			foreach ($timers as $timer) {
				$entry = new Timer();
				$entry->owner = $this->characterToName($timer->createdBy??null) ?? $this->chatBot->vars["name"];
				$entry->data = $timer->repeatInterval ? (string)$timer->repeatInterval : null;
				$entry->mode = $this->channelsToMode($timer->channels??[]);
				$entry->name = $timer->timerName ?? $this->characterToName($timer->createdBy??null) ?? $this->chatBot->vars["name"] . "-{$timerNum}";
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
				$sql = "INSERT INTO `timers_<myname>` (`name`, `owner`, `mode`, `endtime`, `settime`, `callback`, `data`, `alerts`) ".
					"VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
				$this->db->exec(
					$sql,
					$entry->name,
					$entry->owner,
					$entry->mode,
					$entry->endtime,
					$timer->startTime ?? time(),
					$entry->callback,
					$entry->data,
					json_encode($entry->alerts)
				);
				$timerNum++;
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `tracked_users_<myname>`");
			foreach ($trackedUsers as $trackedUser) {
				$name = $this->characterToName($trackedUser->character??null);
				if (!isset($name)) {
					continue;
				}
				$id = $trackedUser->character->id ? $trackedUser->character->id : $this->chatBot->get_uid($name);
				if (!isset($id) || $id === false) {
					continue;
				}
				$this->db->exec(
					"INSERT INTO `tracked_users_<myname>`(`uid`, `name`, `added_by`, `added_dt`) ".
					"VALUES (?, ?, ?, ?)",
					$id,
					$name,
					$this->characterToName($trackedUser->addedBy??null) ?? $this->chatBot->vars["name"],
					$trackedUser->addedTime ?? time()
				);
				foreach ($trackedUser->events??[] as $event) {
					$this->db->exec(
						"INSERT INTO `tracking_<myname>`(`uid`, `dt`, `event`) ".
						"VALUES (?, ?, ?)",
						$id,
						$event->time,
						$event->event,
					);
				}
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `<table:comment_categories>` WHERE user_managed IS TRUE");
			foreach ($categories as $category) {
				$oldEntry = $this->commentController->getCategory($category->name);
				$entry = new CommentCategory();
				$entry->name = $category->name;
				$entry->created_by = $this->characterToName($category->createdBy ??null) ?? $this->chatBot->vars["name"];
				$entry->created_at = $category->createdAt ?? time();
				$entry->min_al_read = $this->getMappedRank($rankMap, $category->minRankToRead) ?? "mod";
				$entry->min_al_write = $this->getMappedRank($rankMap, $category->minRankToWrite) ?? "admin";
				$entry->user_managed = isset($oldEntry) ? $oldEntry->user_managed : !($category->systemEntry ?? false);
				if (isset($oldEntry)) {
					$this->db->update("<table:comment_categories>", "name", $entry);
				} else {
					$this->db->insert("<table:comment_categories>", $entry);
				}
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
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
			$this->db->exec("DELETE FROM `<table:comments>`");
			foreach ($comments as $comment) {
				$name = $this->characterToName($comment->targetCharacter);
				if (!isset($name)) {
					continue;
				}
				$entry = new Comment();
				$entry->comment = $comment->comment;
				$entry->character = $name;
				$entry->created_by = $this->characterToName($comment->createdBy ??null) ?? $this->chatBot->vars["name"];
				$entry->created_at = $comment->createdAt ?? time();
				$entry->category = $comment->category ?? "admin";
				if ($this->commentController->getCategory($entry->category) === null) {
					$cat = new CommentCategory();
					$cat->name = $entry->category;
					$cat->created_by = $this->chatBot->vars["name"];
					$cat->created_at = time();
					$cat->min_al_read = "mod";
					$cat->min_al_write = "admin";
					$cat->user_managed = true;
					$this->db->insert("<table:comment_categories>", $cat);
				}
				$this->db->insert("<table:comments>", $entry);
			}
		} catch (SQLException $e) {
			$this->logger->log("ERROR", $e->getMessage());
			$this->logger->log("INFO", "Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->log("INFO", "All comments imported");
	}
}
