<?php declare(strict_types=1);

namespace Nadybot\Modules\EXPORT_MODULE;

use function Amp\call;
use function Safe\{file_put_contents, json_decode, json_encode};
use Amp\Promise;
use Generator;
use Nadybot\Core\{
	AccessManager,
	AdminManager,
	Attributes as NCA,
	BuddylistManager,
	CmdContext,
	Config\BotConfig,
	DB,
	DBSchema\Admin,
	DBSchema\Alt,
	DBSchema\BanEntry,
	DBSchema\Member,
	ModuleInstance,
	Modules\BAN\BanController,
	Modules\PREFERENCES\Preferences,
	Nadybot,
	ProxyCapabilities,
};
use Nadybot\Modules\EVENTS_MODULE\EventModel;
use Nadybot\Modules\NOTES_MODULE\{OrgNote, OrgNotesController};
use Nadybot\Modules\{
	CITY_MODULE\CloakController,
	CITY_MODULE\OrgCity,
	COMMENT_MODULE\Comment,
	COMMENT_MODULE\CommentCategory,
	GUILD_MODULE\GuildController,
	GUILD_MODULE\OrgMember,
	MASSMSG_MODULE\MassMsgController,
	NEWS_MODULE\News,
	NEWS_MODULE\NewsConfirmed,
	NOTES_MODULE\Link,
	NOTES_MODULE\Note,
	PRIVATE_CHANNEL_MODULE\PrivateChannelController,
	QUOTE_MODULE\Quote,
	RAFFLE_MODULE\RaffleBonus,
	RAFFLE_MODULE\RaffleController,
	RAID_MODULE\AuctionController,
	RAID_MODULE\DBAuction,
	RAID_MODULE\RaidBlock,
	RAID_MODULE\RaidBlockController,
	RAID_MODULE\RaidController,
	RAID_MODULE\RaidLog,
	RAID_MODULE\RaidMember,
	RAID_MODULE\RaidMemberController,
	RAID_MODULE\RaidPoints,
	RAID_MODULE\RaidPointsController,
	RAID_MODULE\RaidPointsLog,
	RAID_MODULE\RaidRank,
	RAID_MODULE\RaidRankController,
	TIMERS_MODULE\TimerController,
	TRACKER_MODULE\TrackedUser,
	TRACKER_MODULE\TrackerController,
	TRACKER_MODULE\Tracking,
	VOTE_MODULE\Poll,
	VOTE_MODULE\Vote,
	VOTE_MODULE\VoteController,
};
use Safe\Exceptions\{FilesystemException, JsonException};
use stdClass;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "export",
		accessLevel: "superadmin",
		description: "Export the bot configuration and data",
	)
]
class ExportController extends ModuleInstance {
	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public TimerController $timerController;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public BuddylistManager $buddylistManager;

	#[NCA\Inject]
	public Preferences $preferences;

	#[NCA\Inject]
	public BotConfig $config;

	/** Export all of this bot's data into a portable JSON-file */
	#[NCA\HandlesCommand("export")]
	#[NCA\Help\Example(
		command: "<symbol>export 2021-01-31",
		description: "Export everything into 'data/export/2021-01-31.json'"
	)]
	#[NCA\Help\Prologue(
		"The purpose of this command is to create a portable file containing all the\n".
		"data, not settings, of your bot so it can later be imported into another\n".
		"bot."
	)]
	public function exportCommand(CmdContext $context, string $file): void {
		$dataPath = $this->config->paths->data;
		$fileName = "{$dataPath}/export/" . basename($file);
		if ((pathinfo($fileName)["extension"] ?? "") !== "json") {
			$fileName .= ".json";
		}
		if (!@file_exists("{$dataPath}/export")) {
			\Safe\mkdir("{$dataPath}/export", 0700);
		}
		if ($this->config->proxy?->enabled) {
			if (!$this->chatBot->proxyCapabilities->supportsBuddyMode(ProxyCapabilities::SEND_BY_WORKER)) {
				$context->reply(
					"You are using an unsupported proxy version. ".
					"Please upgrade to the latest AOChatProxy and try again."
				);
				return;
			}
		}
		$context->reply("Starting export...");
		$exports = new stdClass();
		$exports->alts = yield $this->exportAlts();
		$exports->auctions = yield $this->exportAuctions();
		$exports->banlist = yield $this->exportBanlist();
		$exports->cityCloak = yield $this->exportCloak();
		$exports->commentCategories = yield $this->exportCommentCategories();
		$exports->comments = yield $this->exportComments();
		$exports->events = yield $this->exportEvents();
		$exports->links = yield $this->exportLinks();
		$exports->members = yield $this->exportMembers();
		$exports->news = yield $this->exportNews();
		$exports->notes = yield $this->exportNotes();
		$exports->orgNotes = yield $this->exportOrgNotes();
		$exports->polls = yield $this->exportPolls();
		$exports->quotes = yield $this->exportQuotes();
		$exports->raffleBonus = yield $this->exportRaffleBonus();
		$exports->raidBlocks = yield $this->exportRaidBlocks();
		$exports->raids = yield $this->exportRaidLogs();
		$exports->raidPoints = yield $this->exportRaidPoints();
		$exports->raidPointsLog = yield $this->exportRaidPointsLog();
		$exports->timers = yield $this->exportTimers();
		$exports->trackedCharacters = yield $this->exportTrackedCharacters();
		try {
			$output = json_encode($exports, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
		} catch (JsonException $e) {
			$context->reply("There was an error exporting the data: " . $e->getMessage());
			return;
		}
		try {
			file_put_contents($fileName, $output);
		} catch (FilesystemException $e) {
			$context->reply($e->getMessage());
			return;
		}
		$context->reply("The export was successfully saved in {$fileName}.");
	}

	/** @return Promise<Character> */
	protected function toChar(?string $name, ?int $uid=null): Promise {
		return call(function () use ($name, $uid): Generator {
			$char = new Character();
			if (isset($name)) {
				$char->name = $name;
			}
			$id = $uid;
			if (!isset($id) && isset($name)) {
				$id = $this->chatBot->getUid($name);
			}
			if (is_int($id)) {
				$char->id = $id;
			}
			return $char;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportAlts(): Promise {
		return call(function () {
			/** @var Alt[] */
			$alts = $this->db->table("alts")->asObj(Alt::class)->toArray();
			$data = [];
			foreach ($alts as $alt) {
				if ($alt->main === $alt->alt) {
					continue;
				}
				$data[$alt->main] ??= [];
				$data[$alt->main] []= (object)[
					"alt" => yield $this->toChar($alt->alt),
					"validatedByMain" => $alt->validated_by_main ?? true,
					"validatedByAlt" => $alt->validated_by_alt ?? true,
				];
			}

			$result = [];
			foreach ($data as $main => $altInfo) {
				$result []= (object)[
					"main" => yield $this->toChar($main),
					"alts" => $altInfo,
				];
			}

			/** @var stdClass[] $result */
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportMembers(): Promise {
		return call(function (): Generator {
			$exported = [];
			$result = [];

			/** @var Member[] */
			$members = $this->db->table(PrivateChannelController::DB_TABLE)
				->asObj(Member::class)
				->toArray();
			foreach ($members as $member) {
				$result []= (object)[
					"character" => yield $this->toChar($member->name),
					"autoInvite" => (bool)$member->autoinv,
					"joinedTime" => $member->joined,
				];
				$exported[$member->name] = true;
			}

			/** @var RaidRank[] */
			$members = $this->db->table(RaidRankController::DB_TABLE)
				->asObj(RaidRank::class)
				->toArray();
			foreach ($members as $member) {
				if (isset($exported[$member->name])) {
					continue;
				}
				$result []= (object)[
					"character" => yield $this->toChar($member->name),
				];
				$exported[$member->name] = true;
			}
			$members = $this->db->table(GuildController::DB_TABLE)
				->where("mode", "!=", "del")
				->asObj(OrgMember::class)
				->toArray();
			foreach ($members as $member) {
				if (isset($exported[$member->name])) {
					continue;
				}
				$result []= (object)[
					"character" => yield $this->toChar($member->name),
					"autoInvite" => false,
				];
				$exported[$member->name] = true;
			}

			/** @var Admin[] */
			$members = $this->db->table(AdminManager::DB_TABLE)
				->asObj(Admin::class)
				->toArray();
			foreach ($members as $member) {
				if (isset($exported[$member->name])) {
					continue;
				}
				$result []= (object)[
					"character" => yield $this->toChar($member->name),
					"autoInvite" => false,
				];
				$exported[$member->name] = true;
			}
			foreach ($this->config->general->superAdmins as $superAdmin) {
				if (!isset($exported[$superAdmin])) {
					$result []= (object)[
						"character" => yield $this->toChar($superAdmin),
						"autoInvite" => false,
						"rank" => "superadmin",
					];
				}
			}
			foreach ($result as &$datum) {
				$datum->rank ??= $this->accessManager->getSingleAccessLevel($datum->character->name);
				$logonMessage = $this->preferences->get($datum->character->name, "logon_msg");
				$logoffMessage = $this->preferences->get($datum->character->name, "logoff_msg");
				$massMessages = $this->preferences->get($datum->character->name, MassMsgController::PREF_MSGS);
				$massInvites = $this->preferences->get($datum->character->name, MassMsgController::PREF_INVITES);
				if (!empty($logonMessage)) {
					$datum->logonMessage ??= $logonMessage;
				}
				if (!empty($logoffMessage)) {
					$datum->logoffMessage ??= $logoffMessage;
				}
				if (!empty($massMessages)) {
					$datum->receiveMassMessages ??= $massMessages === "on";
				}
				if (!empty($massInvites)) {
					$datum->receiveMassInvites ??= $massInvites === "on";
				}
			}
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportQuotes(): Promise {
		return call(function (): Generator {
			/** @var Quote[] */
			$quotes = $this->db->table("quote")
				->orderBy("id")
				->asObj(Quote::class)
				->toArray();
			$result = [];
			foreach ($quotes as $quote) {
				$result []= (object)[
					"quote" => $quote->msg,
					"time" => $quote->dt,
					"contributor" => yield $this->toChar($quote->poster),
				];
			}
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportBanlist(): Promise {
		return call(function (): Generator {
			/** @var BanEntry[] */
			$banList = $this->db->table(BanController::DB_TABLE)
				->asObj(BanEntry::class)->toArray();
			$result = [];
			foreach ($banList as $banEntry) {
				$name = $this->chatBot->getName($banEntry->charid);
				$ban = (object)[
					"character" => yield $this->toChar($name, $banEntry->charid),
					"bannedBy" => yield $this->toChar($banEntry->admin),
					"banReason" => $banEntry->reason,
					"banStart" => $banEntry->time,
				];
				if (isset($banEntry->banend) && $banEntry->banend > 0) {
					$ban->banEnd = $banEntry->banend;
				}
				$result []= $ban;
			}

			/** @var stdClass[] $result */
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportCloak(): Promise {
		return call(function (): Generator {
			/** @var OrgCity[] */
			$cloakList = $this->db->table(CloakController::DB_TABLE)
				->asObj(OrgCity::class)
				->toArray();
			$result = [];
			foreach ($cloakList as $cloakEntry) {
				$result []= (object)[
					"character" => yield $this->toChar(preg_replace("/\*$/", "", $cloakEntry->player)),
					"manualEntry" => (bool)preg_match("/\*$/", $cloakEntry->player),
					"cloakOn" => ($cloakEntry->action === "on"),
					"time" => $cloakEntry->time,
				];
			}

			/** @var stdClass[] $result */
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportPolls(): Promise {
		return call(function (): Generator {
			/** @var Poll[] */
			$polls = $this->db->table(VoteController::DB_POLLS)
				->asObj(Poll::class)
				->toArray();
			$result = [];
			foreach ($polls as $poll) {
				$export = (object)[
					"author" => yield $this->toChar($poll->author),
					"question" => $poll->question,
					"answers" => [],
					"startTime" => $poll->started,
					"endTime" => $poll->started + $poll->duration,
				];
				$answers = [];
				foreach (json_decode($poll->possible_answers, false) as $answer) {
					$answers[$answer] ??= (object)[
						"answer" => $answer,
						"votes" => [],
					];
				}

				/** @var Vote[] */
				$votes = $this->db->table(VoteController::DB_VOTES)
					->where("poll_id", $poll->id)
					->asObj(Vote::class)
					->toArray();
				foreach ($votes as $vote) {
					if (!isset($vote->answer)) {
						continue;
					}
					$answers[$vote->answer] ??= (object)[
						"answer" => $vote->answer,
						"votes" => [],
					];
					$answer = (object)[
						"character" => yield $this->toChar($vote->author),
					];
					if (isset($vote->time)) {
						$answer->voteTime = $vote->time;
					}
					$answers[$vote->answer]->votes []= $answer;
				}
				$export->answers = array_values($answers);
				$result []= $export;
			}

			/** @var stdClass[] $result */
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportRaffleBonus(): Promise {
		return call(function (): Generator {
			/** @var RaffleBonus[] */
			$data = $this->db->table(RaffleController::DB_TABLE)
				->orderBy("name")
				->asObj(RaffleBonus::class)
				->toArray();
			$result = [];
			foreach ($data as $bonus) {
				$result []= (object)[
					"character" => yield $this->toChar($bonus->name),
					"raffleBonus" => $bonus->bonus,
				];
			}
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportRaidBlocks(): Promise {
		return call(function (): Generator {
			/** @var RaidBlock[] */
			$data = $this->db->table(RaidBlockController::DB_TABLE)
				->orderBy("player")
				->asObj(RaidBlock::class)
				->toArray();
			$result = [];
			foreach ($data as $block) {
				$entry = (object)[
					"character" => yield $this->toChar($block->player),
					"blockedFrom" => $block->blocked_from,
					"blockedBy" => yield $this->toChar($block->blocked_by),
					"blockedReason" => $block->reason,
					"blockStart" => $block->time,
				];
				if (isset($block->expiration)) {
					$entry->blockEnd = $block->expiration;
				}
				$result []= $entry;
			}
			return $result;
		});
	}

	protected function nullIf(int $value, int $nullvalue=0): ?int {
		return ($value === $nullvalue) ? null : $value;
	}

	/** @return Promise<stdClass[]> */
	protected function exportRaidLogs(): Promise {
		return call(function (): Generator {
			/** @var RaidLog[] */
			$data = $this->db->table(RaidController::DB_TABLE_LOG)
				->orderBy("raid_id")
				->asObj(RaidLog::class)
				->toArray();
			$raids = [];
			foreach ($data as $raid) {
				$raids[$raid->raid_id] ??= (object)[
					"raidId" => $raid->raid_id,
					"time" => $raid->time,
					"raidDescription" => $raid->description,
					"raidLocked" => $raid->locked,
					"raidAnnounceInterval" => $raid->announce_interval,
					"raiders" => [],
					"history" => [],
				];
				if ($raid->seconds_per_point > 0) {
					$raids[$raid->raid_id]->raidSecondsPerPoint = $raid->seconds_per_point;
				}
				$raids[$raid->raid_id]->history[] = (object)[
					"time" => $raid->time,
					"raidDescription" => $raid->description,
					"raidLocked" => $raid->locked,
					"raidAnnounceInterval" => $raid->announce_interval,
					"raidSecondsPerPoint" => $this->nullIf($raid->seconds_per_point),
				];
			}

			/** @var RaidMember[] */
			$data = $this->db->table(RaidMemberController::DB_TABLE)
				->asObj(RaidMember::class)
				->toArray();
			foreach ($data as $raidMember) {
				$raider = (object)[
					"character" => yield $this->toChar($raidMember->player),
					"joinTime" => $raidMember->joined,
				];
				if (isset($raidMember->left)) {
					$raider->leaveTime = $raidMember->left;
				}
				$raids[$raidMember->raid_id]->raiders []= $raider;
			}
			return array_values($raids);
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportRaidPoints(): Promise {
		return call(function (): Generator {
			/** @var RaidPoints[] */
			$data = $this->db->table(RaidPointsController::DB_TABLE)
				->orderBy("username")
				->asObj(RaidPoints::class)
				->toArray();
			$result = [];
			foreach ($data as $datum) {
				$result []= (object)[
					"character" => yield $this->toChar($datum->username),
					"raidPoints" => $datum->points,
				];
			}
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportRaidPointsLog(): Promise {
		return call(function (): Generator {
			/** @var RaidPointsLog[] */
			$data = $this->db->table(RaidPointsController::DB_TABLE_LOG)
				->orderBy("time")
				->orderBy("username")
				->asObj(RaidPointsLog::class)
				->toArray();
			$result = [];
			foreach ($data as $datum) {
				$raidLog = (object)[
					"character" => yield $this->toChar($datum->username),
					"raidPoints" => (float)$datum->delta,
					"time" => $datum->time,
					"givenBy" => yield $this->toChar($datum->changed_by),
					"reason" => $datum->reason,
					"givenByTick" => $datum->ticker,
					"givenIndividually" => $datum->individual,
				];
				if (isset($datum->raid_id)) {
					$raidLog->raidId = $datum->raid_id;
				}
				$result []= $raidLog;
			}
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportTimers(): Promise {
		return call(function (): Generator {
			$timers = $this->timerController->getAllTimers();
			$result = [];
			foreach ($timers as $timer) {
				$data = (object)[
					"startTime" => $timer->settime,
					"timerName" => $timer->name,
					"endTime" => $timer->endtime,
					"createdBy" => yield $this->toChar($timer->owner),
					"channels" => array_diff(explode(",", str_replace(["guild", "both", "msg"], ["org", "priv,org", "tell"], $timer->mode??"")), [""]),
					"alerts" => [],
				];
				if (!empty($timer->data) && (int)$timer->data > 0) {
					$data->repeatInterval = (int)$timer->data;
				}
				foreach ($timer->alerts as $alert) {
					$data->alerts []= (object)[
						"time" => $alert->time,
						"message" => $alert->message,
					];
				}
				$result []= $data;
			}
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportTrackedCharacters(): Promise {
		return call(function (): Generator {
			/** @var TrackedUser[] */
			$users = $this->db->table(TrackerController::DB_TABLE)
				->orderBy("added_dt")
				->asObj(TrackedUser::class)
				->toArray();
			$result = [];
			foreach ($users as $user) {
				$result[$user->uid] = (object)[
					"character" => yield $this->toChar($user->name, $user->uid),
					"addedTime" => $user->added_dt,
					"addedBy" => yield $this->toChar($user->added_by),
					"events" => [],
				];
			}

			/** @var Tracking[] */
			$events = $this->db->table(TrackerController::DB_TRACKING)
				->orderBy("dt")
				->asObj(Tracking::class)
				->toArray();
			foreach ($events as $event) {
				if (!isset($result[$event->uid])) {
					continue;
				}
				$result[$event->uid]->events []= (object)[
					"time" => $event->dt,
					"event" => $event->event,
				];
			}
			return array_values($result);
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportAuctions(): Promise {
		return call(function (): Generator {
			/** @var DBAuction[] */
			$auctions = $this->db->table(AuctionController::DB_TABLE)
				->orderBy("id")
				->asObj(DBAuction::class)
				->toArray();
			$result = [];
			foreach ($auctions as $auction) {
				$auctionObj = (object)[
					"item" => $auction->item,
					"startedBy" => yield $this->toChar($auction->auctioneer),
					"timeEnd" => $auction->end,
					"reimbursed" => $auction->reimbursed,
				];
				if (isset($auction->winner)) {
					$auctionObj->winner = yield $this->toChar($auction->winner);
				}
				if (isset($auction->cost)) {
					$auctionObj->cost = (float)$auction->cost;
				}
				$result []= $auctionObj;
			}

			/** @var stdClass[] $result */
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportNews(): Promise {
		return call(function (): Generator {
			/** @var News[] */
			$news = $this->db->table("news")
				->asObj(News::class)
				->toArray();
			$result = [];
			foreach ($news as $topic) {
				$data = (object)[
					"author" => yield $this->toChar($topic->name),
					"uuid" => $topic->uuid,
					"addedTime" => $topic->time,
					"news" => $topic->news,
					"pinned" => $topic->sticky,
					"deleted" => $topic->deleted,
					"confirmedBy" => [],
				];

				/** @var NewsConfirmed[] */
				$confirmations = $this->db->table("news_confirmed")
					->where("id", $topic->id)
					->asObj(NewsConfirmed::class)
					->toArray();
				foreach ($confirmations as $confirmation) {
					$data->confirmedBy []= (object)[
						"character" => yield $this->toChar($confirmation->player),
						"confirmationTime" => $confirmation->time,
					];
				}
				$result []= $data;
			}
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportNotes(): Promise {
		return call(function (): Generator {
			/** @var Note[] */
			$notes = $this->db->table("notes")
				->asObj(Note::class)
				->toArray();
			$result = [];
			foreach ($notes as $note) {
				$data = (object)[
					"owner" => yield $this->toChar($note->owner),
					"author" => yield $this->toChar($note->added_by),
					"creationTime" => $note->dt,
					"text" => $note->note,
				];
				if ($note->reminder === Note::REMIND_ALL) {
					$data->remind = "all";
				} elseif ($note->reminder === Note::REMIND_SELF) {
					$data->remind = "author";
				}
				$result []= $data;
			}
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportOrgNotes(): Promise {
		return call(function (): Generator {
			/** @var OrgNote[] */
			$notes = $this->db->table(OrgNotesController::DB_TABLE)
				->asObj(OrgNote::class)
				->toArray();
			$result = [];
			foreach ($notes as $note) {
				$data = (object)[
					"author" => yield $this->toChar($note->added_by),
					"creationTime" => $note->added_on,
					"text" => $note->note,
					"uuid" => $note->uuid,
				];
				$result []= $data;
			}
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportEvents(): Promise {
		return call(function (): Generator {
			/** @var EventModel[] */
			$events = $this->db->table("events")
				->asObj(EventModel::class)
				->toArray();
			$result = [];
			foreach ($events as $event) {
				$attendees = array_values(array_diff(explode(",", $event->event_attendees ?? ""), [""]));

				/** @var stdClass */
				$data = (object)[
					"createdBy" => yield $this->toChar($event->submitter_name),
					"creationTime" => $event->time_submitted,
					"name" => $event->event_name,
					"attendees" => [],
				];
				if (isset($event->event_date)) {
					$data->startTime = $event->event_date;
				}
				if (isset($event->event_desc)) {
					$data->description = $event->event_desc;
				}
				foreach ($attendees as $attendee) {
					$data->attendees []= yield $this->toChar($attendee);
				}
				$result []= $data;
			}
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportLinks(): Promise {
		return call(function (): Generator {
			/** @var Link[] */
			$links = $this->db->table("links")
				->asObj(Link::class)
				->toArray();
			$result = [];
			foreach ($links as $link) {
				$data = (object)[
					"createdBy" => yield $this->toChar($link->name),
					"creationTime" => $link->dt,
					"url" => $link->website,
					"description" => $link->comments,
				];
				$result []= $data;
			}
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportCommentCategories(): Promise {
		return call(function (): Generator {
			/** @var CommentCategory[] */
			$categories = $this->db->table("<table:comment_categories>")
				->asObj(CommentCategory::class)
				->toArray();
			$result = [];
			foreach ($categories as $category) {
				$data = (object)[
					"name" => $category->name,
					"createdBy" => yield $this->toChar($category->created_by),
					"createdAt" => $category->created_at,
					"minRankToRead" => $category->min_al_read,
					"minRankToWrite" => $category->min_al_write,
					"systemEntry" => !$category->user_managed,
				];
				$result []= $data;
			}
			return $result;
		});
	}

	/** @return Promise<stdClass[]> */
	protected function exportComments(): Promise {
		return call(function (): Generator {
			/** @var Comment[] */
			$comments = $this->db->table("<table:comments>")
				->asObj(Comment::class)
				->toArray();
			$result = [];
			foreach ($comments as $comment) {
				$data = (object)[
					"comment" => $comment->comment,
					"targetCharacter" => yield $this->toChar($comment->character),
					"createdBy" => yield $this->toChar($comment->created_by),
					"createdAt" => $comment->created_at,
					"category" => $comment->category,
				];
				$result []= $data;
			}
			return $result;
		});
	}
}
