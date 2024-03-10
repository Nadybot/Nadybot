<?php declare(strict_types=1);

namespace Nadybot\Modules\EXPORT_MODULE;

use function Safe\{json_decode, json_encode, preg_match};

use Amp\File\{Filesystem, FilesystemException};
use Exception;
use Nadybot\Core\{
	AccessManager,
	AdminManager,
	Attributes as NCA,
	CmdContext,
	Config\BotConfig,
	DB,
	ModuleInstance,
	Modules\BAN\BanController,
	Modules\PREFERENCES\Preferences,
	Nadybot,
	ParamClass\PFilename,
	SettingManager,
	Util,
};
use Nadybot\Modules\NOTES_MODULE\OrgNotesController;
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
use Psr\Log\LoggerInterface;
use stdClass;
use Swaggest\JsonSchema\Schema;
use Throwable;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "import",
		accessLevel: "superadmin",
		description: "Import bot data and replace the current one",
	)
]
class ImportController extends ModuleInstance {
	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Filesystem $fs;

	#[NCA\Inject]
	private Preferences $preferences;

	#[NCA\Inject]
	private AdminManager $adminManager;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private BanController $banController;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private CommentController $commentController;

	#[NCA\Inject]
	private RaidRankController $raidRankController;

	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private BotConfig $config;

	/** Import data from a file, mapping the exported access levels to your own ones */
	#[NCA\HandlesCommand("import")]
	#[NCA\Help\Example("<symbol>import 2021-01-31 superadmin=admin admin=mod leader=member member=member")]
	#[NCA\Help\Prologue(
		"In order to import data from an old export, you should first think about\n".
		"how you want to map access levels between the bots.\n".
		"BeBot or Tyrbot use a totally different access level system than Nadybot."
	)]
	#[NCA\Help\Epilogue(
		"<header2>Warning<end>\n\n".
		"Please note that importing a dump will delete most of the already existing\n".
		"data of your bot, so:\n".
		"<highlight>only do this after you created an export or database backup<end>!\n".
		"This cannot be stressed enough.\n\n".
		"<header2>In detail<end>\n\n".
		"Everything that is included in the dump, will be deleted before importing.\n".
		"So if your dump contains members of the bot, they will all be wiped first.\n".
		"If it does include an empty set of members, they will still be wiped.\n".
		"Only if the members were not exported at all, they won't be touched.\n\n".
		"There is no extra step in-between, so be careful not to delete any\n".
		"data you might want to keep.\n"
	)]
	public function importCommand(
		CmdContext $context,
		PFilename $file,
		#[NCA\Regexp("\w+=\w+", example: "&lt;exported al&gt;=&lt;new al&gt;")]
		?string ...$mappings
	): void {
		$dataPath = $this->config->paths->data;
		$fileName = "{$dataPath}/export/" . basename($file());
		if ((pathinfo($fileName)["extension"] ?? "") !== "json") {
			$fileName .= ".json";
		}
		if (!$this->fs->exists($fileName)) {
			$context->reply("No export file <highlight>{$fileName}<end> found.");
			return;
		}
		$import = $this->loadAndParseExportFile($fileName, $context);
		if (!isset($import)) {
			return;
		}
		$usedRanks = $this->getRanks($import);
		$rankMapping = $this->parseRankMapping(array_filter($mappings));
		foreach ($usedRanks as $rank) {
			if (!isset($rankMapping[$rank])) {
				$context->reply("Please define a mapping for <highlight>{$rank}<end> by appending '{$rank}=&lt;rank&gt;' to your command");
				return;
			}
			try {
				$rankMapping[$rank] = $this->accessManager->getAccessLevel($rankMapping[$rank]);
			} catch (Exception $e) {
				$context->reply("<highlight>{$rankMapping[$rank]}<end> is not a valid access level");
				return;
			}
		}
		$this->logger->notice("Starting import");
		$context->reply("Starting import...");
		$importMap = $this->getImportMapping();
		foreach ($importMap as $key => $func) {
			if (!isset($import->{$key})) {
				continue;
			}
			$func($import->{$key}, $rankMapping);
		}
		$this->logger->notice("Import done");
		$context->reply("The import finished successfully.");
	}

	/** @param stdClass[] $auctions */
	public function importAuctions(array $auctions): void {
		$this->logger->notice("Importing " . count($auctions) . " auction(s)");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all auctions");
			$this->db->table(AuctionController::DB_TABLE)->truncate();
			foreach ($auctions as $auction) {
				$this->db->table(AuctionController::DB_TABLE)
					->insert([
						"raid_id" => $auction->raidId ?? null,
						"item" => $auction->item,
						"auctioneer" => ($this->characterToName($auction->startedBy??null)) ?? $this->config->main->character,
						"cost" => (0 !== ($auction->cost ?? 0)) ? (int)round($auction->cost??0, 0) : null,
						"winner" => $this->characterToName($auction->winner??null),
						"end" => $auction->timeEnd ?? time(),
						"reimbursed" => $auction->reimbursed ?? false,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All auctions imported");
	}

	/** @param stdClass[] $banlist */
	public function importBanlist(array $banlist): void {
		$numImported = 0;
		$this->logger->notice("Importing " . count($banlist) . " ban(s)");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all bans");
			$this->db->table(BanController::DB_TABLE)->truncate();
			foreach ($banlist as $ban) {
				$id = $ban->character->id ?? $this->chatBot->getUid($ban->character->name);
				if (!isset($id)) {
					continue;
				}
				$this->db->table(BanController::DB_TABLE)
				->insert([
					"charid" => $id,
					"admin" => ($this->characterToName($ban->bannedBy ?? null)) ?? $this->config->main->character,
					"time" => $ban->banStart ?? time(),
					"reason" => $ban->banReason ?? "None given",
					"banend" => $ban->banEnd ?? 0,
				]);
				$numImported++;
			}
		} catch (Throwable $e) {
			$this->logger->error("Error importing bans: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->banController->uploadBanlist();
		$this->logger->notice("{num_imported} bans successfully imported", [
			"num_imported" => $numImported,
		]);
	}

	/** @param stdClass[] $cloakActions */
	public function importCloak(array $cloakActions): void {
		$this->logger->notice("Importing " . count($cloakActions) . " cloak action(s)");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all cloak actions");
			$this->db->table(CloakController::DB_TABLE)->truncate();
			foreach ($cloakActions as $action) {
				$this->db->table(CloakController::DB_TABLE)
					->insert([
						"time" => $action->time ?? null,
						"action" => $action->cloakOn ? "on" : "off",
						"player" => ($this->characterToName($action->character??null)) ?? $this->config->main->character,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All cloak actions imported");
	}

	/** @param stdClass[] $links */
	public function importLinks(array $links): void {
		$this->logger->notice("Importing " . count($links) . " links");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all links");
			$this->db->table("links")->truncate();
			foreach ($links as $link) {
				$this->db->table("links")
					->insert([
						"name" => ($this->characterToName($link->createdBy??null)) ?? $this->config->main->character,
						"website" => $link->url,
						"comments" => $link->description ?? "",
						"dt" => $link->creationTime ?? null,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All links imported");
	}

	/**
	 * @param stdClass[]           $members
	 * @param array<string,string> $rankMap
	 */
	public function importMembers(array $members, array $rankMap=[]): void {
		$numImported = 0;
		$this->logger->notice("Importing " . count($members) . " member(s)");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all members");
			$this->db->table(PrivateChannelController::DB_TABLE)->truncate();
			$this->db->table(GuildController::DB_TABLE)->truncate();
			$this->db->table(AdminManager::DB_TABLE)->truncate();
			$this->db->table(RaidRankController::DB_TABLE)->truncate();
			foreach ($members as $member) {
				$id = $member->character->id ?? $this->chatBot->getUid($member->character->name);

				/** @var ?string */
				$name = $this->characterToName($member->character);
				if (!isset($id) || !isset($name)) {
					continue;
				}
				$this->chatBot->cacheUidNameMapping(name: $name, uid: $id);
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
							"joined" => $member->joinedTime ?? time(),
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
							"rank" => (int)$matches[1] + 3,
						]);
				} elseif (preg_match("/^raid_admin_([123])/", $newRank, $matches)) {
					$this->db->table(RaidRankController::DB_TABLE)
						->insert([
							"name" => $name,
							"rank" => (int)$matches[1] + 6,
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
			$this->logger->error("Error importing members: {error}", [
				"error" => $e->getMessage(),
				"exception" => $e,
			]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("{num_imported} members successfully imported", [
			"num_imported" => $numImported,
		]);
	}

	/** @param stdClass[] $events */
	public function importEvents(array $events): void {
		$this->logger->notice("Importing " . count($events) . " events");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all events");
			$this->db->table("events")->truncate();
			foreach ($events as $event) {
				$attendees = [];
				foreach ($event->attendees??[] as $attendee) {
					$name = $this->characterToName($attendee??null);
					if (isset($name)) {
						$attendees []= $name;
					}
				}
				$this->db->table("events")
				->insert([
					"time_submitted" => $event->creationTime ?? time(),
					"submitter_name" => ($this->characterToName($event->createdBy ?? null)) ?? $this->config->main->character,
					"event_name" => $event->name,
					"event_date" => $event->startTime ?? null,
					"event_desc" => $event->description ?? null,
					"event_attendees" => join(",", $attendees),
				]);
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All events imported");
	}

	/** @param stdClass[] $news */
	public function importNews(array $news): void {
		$this->logger->notice("Importing " . count($news) . " news");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all news");
			$this->db->table("news_confirmed")->truncate();
			$this->db->table("news")->truncate();
			foreach ($news as $item) {
				$newsId = $this->db->table("news")
				->insertGetId([
					"time" => $item->addedTime ?? time(),
					"uuid" => $item->uuid ?? $this->util->createUUID(),
					"name" => ($this->characterToName($item->author ?? null)) ?? $this->config->main->character,
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
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All news imported");
	}

	/** @param stdClass[] $notes */
	public function importNotes(array $notes): void {
		$this->logger->notice("Importing " . count($notes) . " notes");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all notes");
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
					"added_by" => ($this->characterToName($note->author ?? null)) ?? $owner,
					"note" => $note->text,
					"dt" => $note->creationTime ?? null,
					"reminder" => $reminderInt,
				]);
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All notes imported");
	}

	/** @param stdClass[] $notes */
	public function importOrgNotes(array $notes): void {
		$this->logger->notice("Importing " . count($notes) . " org notes");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all org notes");
			$this->db->table(OrgNotesController::DB_TABLE)->truncate();
			foreach ($notes as $note) {
				$owner = $this->characterToName($note->owner??null);
				if (!isset($owner)) {
					continue;
				}
				$this->db->table(OrgNotesController::DB_TABLE)
				->insert([
					"added_by" => ($this->characterToName($note->author ?? null)) ?? $owner,
					"note" => $note->text,
					"added_on" => $note->creationTime ?? null,
					"uuid" => $note->uuid ?? $this->util->createUUID(),
				]);
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All org notes imported");
	}

	/** @param stdClass[] $polls */
	public function importPolls(array $polls): void {
		$this->logger->notice("Importing " . count($polls) . " polls");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all polls");
			$this->db->table(VoteController::DB_VOTES)->truncate();
			$this->db->table(VoteController::DB_POLLS)->truncate();
			foreach ($polls as $poll) {
				$pollId = $this->db->table(VoteController::DB_POLLS)
					->insertGetId([
						"author" => ($this->characterToName($poll->author??null)) ?? $this->config->main->character,
						"question" => $poll->question,
						"possible_answers" => json_encode(
							array_map(
								function (stdClass $answer): string {
									return $answer->answer;
								},
								$poll->answers??[]
							),
						),
						"started" => $poll->startTime ?? time(),
						"duration" => ($poll->endTime ?? time()) - ($poll->startTime ?? time()),
						"status" => VoteController::STATUS_STARTED,
					]);
				foreach ($poll->answers??[] as $answer) {
					foreach ($answer->votes??[] as $vote) {
						$this->db->table(VoteController::DB_VOTES)
							->insert([
								"poll_id" => $pollId,
								"author" => ($this->characterToName($vote->character??null)) ?? "Unknown",
								"answer" => $answer->answer,
								"time" => $vote->voteTime ?? time(),
							]);
					}
				}
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All polls imported");
	}

	/** @param stdClass[] $quotes */
	public function importQuotes(array $quotes): void {
		$this->logger->notice("Importing " . count($quotes) . " quotes");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all quotes");
			$this->db->table("quote")->truncate();
			foreach ($quotes as $quote) {
				$this->db->table("quote")
					->insert([
						"poster" => ($this->characterToName($quote->contributor??null)) ?? $this->config->main->character,
						"dt" => $quote->time??time(),
						"msg" => $quote->quote,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All quotes imported");
	}

	/** @param stdClass[] $bonuses */
	public function importRaffleBonus(array $bonuses): void {
		$this->logger->notice("Importing " . count($bonuses) . " raffle bonuses");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all raffle bonuses");
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
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All raffle bonuses imported");
	}

	/** @param stdClass[] $blocks */
	public function importRaidBlocks(array $blocks): void {
		$this->logger->notice("Importing " . count($blocks) . " raid blocks");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all raid blocks");
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
						"blocked_by" => ($this->characterToName($block->blockedBy??null)) ?? $this->config->main->character,
						"reason" => $block->blockedReason ?? "No reason given",
						"time" => $block->blockStart ?? time(),
						"expiration" => $block->blockEnd ?? null,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All raid blocks imported");
	}

	/** @param stdClass[] $raids */
	public function importRaids(array $raids): void {
		$this->logger->notice("Importing " . count($raids) . " raids");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all raids");
			$this->db->table(RaidController::DB_TABLE)->truncate();
			$this->db->table(RaidController::DB_TABLE_LOG)->truncate();
			$this->db->table(RaidMemberController::DB_TABLE)->truncate();
			foreach ($raids as $raid) {
				$entry = new Raid();
				$historyEntry = new RaidLog();
				$history = $raid->history ?? [];
				usort(
					$history,
					function (stdClass $o1, stdClass $o2): int {
						return $o1->time <=> $o2->time;
					}
				);
				$lastEntry = end($history);
				$historyEntry->description = $entry->description = $raid->raidDescription ?? "No description";
				$historyEntry->seconds_per_point = $entry->seconds_per_point = $raid->raidSecondsPerPoint ?? 0;
				$historyEntry->announce_interval = $entry->announce_interval = $raid->raidAnnounceInterval ?? $this->settingManager->getInt('raid_announcement_interval');
				$historyEntry->locked = $entry->locked = $raid->raidLocked ?? false;
				$entry->started = $raid->time ?? time();
				$entry->started_by = $this->config->main->character;
				$entry->stopped = isset($lastEntry) ? $lastEntry->time : $entry->started;
				$entry->stopped_by = $this->config->main->character;
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
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All raids imported");
	}

	/** @param stdClass[] $points */
	public function importRaidPoints(array $points): void {
		$this->logger->notice("Importing " . count($points) . " raid points");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all raid points");
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
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All raid points imported");
	}

	/** @param stdClass[] $points */
	public function importRaidPointsLog(array $points): void {
		$this->logger->notice("Importing " . count($points) . " raid point logs");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all raid point logs");
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
						"changed_by" => ($this->characterToName($point->givenBy ??null)) ?? $this->config->main->character,
						"individual" => $point->givenIndividually ?? true,
						"raid_id" => $point->raidId ?? null,
						"reason" => $point->reason ?? "Raid participation",
						"ticker" => $point->givenByTick ?? false,
					]);
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All raid point logs imported");
	}

	/** @param stdClass[] $timers */
	public function importTimers(array $timers): void {
		$table = TimerController::DB_TABLE;
		$this->logger->notice("Importing " . count($timers) . " timers");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all timers");
			$this->db->table($table)->truncate();
			$timerNum = 1;
			foreach ($timers as $timer) {
				$entry = new Timer();
				$owner = $this->characterToName($timer->createdBy??null);
				$entry->owner = $owner ?? $this->config->main->character;
				$entry->data = (isset($timer->repeatInterval) && is_int($timer->repeatgInterval) && $timer->repeatInterval > 0)
					? (string)$timer->repeatInterval
					: null;
				$entry->mode = $this->channelsToMode($timer->channels??[]);
				$entry->name = $timer->timerName ?? ($this->characterToName($timer->createdBy??null)) ?? $this->config->main->character . "-{$timerNum}";
				$entry->endtime = $timer->endTime;
				$entry->callback = isset($entry->data) ? "timercontroller.repeatingTimerCallback" : "timercontroller.timerCallback";
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
						"alerts" => json_encode($entry->alerts),
					]);
				$timerNum++;
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All timers imported");
	}

	/** @param stdClass[] $trackedUsers */
	public function importTrackedCharacters(array $trackedUsers): void {
		$this->logger->notice("Importing " . count($trackedUsers) . " tracked users");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all tracked users");
			$this->db->table(TrackerController::DB_TABLE)->truncate();
			foreach ($trackedUsers as $trackedUser) {
				$name = $this->characterToName($trackedUser->character??null);
				if (!isset($name)) {
					continue;
				}
				$id = $trackedUser->character->id ?? $this->chatBot->getUid($name);
				if ($id === false) {
					continue;
				}
				$this->db->table(TrackerController::DB_TABLE)
					->insert([
						"uid" => $id,
						"name" => $name,
						"added_by" => ($this->characterToName($trackedUser->addedBy??null)) ?? $this->config->main->character,
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
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All raid blocks imported");
	}

	/**
	 * @param stdClass[]           $categories
	 * @param array<string,string> $rankMap
	 */
	public function importCommentCategories(array $categories, array $rankMap): void {
		$this->logger->notice("Importing " . count($categories) . " comment categories");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all user-managed comment categories");
			$this->db->table("<table:comment_categories>")
				->where("user_managed", true)
				->delete();
			foreach ($categories as $category) {
				$oldEntry = $this->commentController->getCategory($category->name);
				$entry = new CommentCategory();
				$entry->name = $category->name;
				$createdBy = $this->characterToName($category->createdBy ??null);
				$entry->created_by = $createdBy ?? $this->config->main->character;
				$entry->created_at = $category->createdAt ?? time();
				$entry->min_al_read = $this->getMappedRank($rankMap, $category->minRankToRead) ?? "mod";
				$entry->min_al_write = $this->getMappedRank($rankMap, $category->minRankToWrite) ?? "admin";

				/** @psalm-suppress RiskyTruthyFalsyComparison */
				$entry->user_managed = isset($oldEntry) ? $oldEntry->user_managed : !($category->systemEntry ?? false);
				if (isset($oldEntry)) {
					$this->db->update("<table:comment_categories>", "name", $entry);
				} else {
					$this->db->insert("<table:comment_categories>", $entry, null);
				}
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All comment categories imported");
	}

	/** @param stdClass[] $comments */
	public function importComments(array $comments): void {
		$this->logger->notice("Importing " . count($comments) . " comment(s)");
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all comments");
			$this->db->table("<table:comments>")->truncate();
			foreach ($comments as $comment) {
				$name = $this->characterToName($comment->targetCharacter);
				if (!isset($name)) {
					continue;
				}
				$entry = new Comment();
				$entry->comment = $comment->comment;
				$entry->character = $name;
				$createdBy = $this->characterToName($comment->createdBy ??null);
				$entry->created_by = $createdBy ?? $this->config->main->character;
				$entry->created_at = $comment->createdAt ?? time();
				$entry->category = $comment->category ?? "admin";
				if ($this->commentController->getCategory($entry->category) === null) {
					$cat = new CommentCategory();
					$cat->name = $entry->category;
					$cat->created_by = $this->config->main->character;
					$cat->created_at = time();
					$cat->min_al_read = "mod";
					$cat->min_al_write = "admin";
					$cat->user_managed = true;
					$this->db->insert("<table:comment_categories>", $cat, null);
				}
				$this->db->insert("<table:comments>", $entry);
			}
		} catch (Throwable $e) {
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("All comments imported");
	}

	/** @return array<string,callable> */
	protected function getImportMapping(): array {
		return [
			"members"           => [$this, "importMembers"],
			"alts"              => [$this, "importAlts"],
			"auctions"          => [$this, "importAuctions"],
			"banlist"           => [$this, "importBanlist"],
			"cityCloak"         => [$this, "importCloak"],
			"commentCategories" => [$this, "importCommentCategories"],
			"comments"          => [$this, "importComments"],
			"events"            => [$this, "importEvents"],
			"links"             => [$this, "importLinks"],
			"news"              => [$this, "importNews"],
			"notes"             => [$this, "importNotes"],
			"orgNotes"          => [$this, "importOrgNotes"],
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

	/**
	 * @param string[] $mappings
	 *
	 * @return array<string,string>
	 */
	protected function parseRankMapping(array $mappings): array {
		$mapping = [];
		foreach ($mappings as $part) {
			[$key, $value] = explode("=", $part);
			$mapping[$key] = $value;
		}
		return $mapping;
	}

	/** @return string[] */
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
		// @phpstan-ignore-next-line
		return array_keys($ranks);
	}

	protected function characterToName(?stdClass $char): ?string {
		if (!isset($char)) {
			return null;
		}
		$name = $char->name ?? $this->chatBot->getName($char->id);
		if (!isset($name)) {
			$this->logger->notice("Unable to find a name for UID {user_id}", [
				"user_id" => $char->id,
			]);
		}
		return $name;
	}

	/** @param stdClass[] $alts */
	protected function importAlts(array $alts): void {
		$this->logger->notice("Importing alts for " . count($alts) . " character(s)");
		$numImported = 0;
		$this->db->awaitBeginTransaction();
		try {
			$this->logger->notice("Deleting all alts");
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
			$this->logger->error($e->getMessage(), ["exception" => $e]);
			$this->logger->notice("Rolling back changes");
			$this->db->rollback();
			return;
		}
		$this->db->commit();
		$this->logger->notice("{num_imported} alt(s) imported", [
			"num_imported" => $numImported,
		]);
	}

	protected function importAlt(string $mainName, stdClass $alt): int {
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

	/** @param array<string,string> $mapping */
	protected function getMappedRank(array $mapping, string $rank): ?string {
		return $mapping[$rank] ?? null;
	}

	/** @param string[] $channels */
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

	private function loadAndParseExportFile(string $fileName, CmdContext $sendto): ?stdClass {
		if (!$this->fs->exists($fileName)) {
			$sendto->reply("No export file <highlight>{$fileName}<end> found.");
			return null;
		}
		$this->logger->notice("Decoding the JSON data");
		try {
			$import = json_decode($this->fs->read($fileName));
		} catch (FilesystemException $e) {
			$sendto->reply("Error reading <highlight>{$fileName}<end>: ".
				$e->getMessage() . ".");
			return null;
		} catch (Throwable $e) {
			$sendto->reply("Error decoding <highlight>{$fileName}<end>.");
			return null;
		}
		if (!($import instanceof stdClass)) {
			$sendto->reply("The file <highlight>{$fileName}<end> is not a valid export file.");
			return null;
		}
		$this->logger->notice("Loading schema data");
		$schema = Schema::import("https://hodorraid.org/export-schema.json");
		$this->logger->notice("Validating import data against the schema");
		$sendto->reply("Validating the import data. This could take a while.");
		try {
			$schema->in($import);
		} catch (Exception $e) {
			$sendto->reply("The import data is not valid: <highlight>" . $e->getMessage() . "<end>.");
			return null;
		}
		return $import;
	}
}
