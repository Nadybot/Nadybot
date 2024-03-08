<?php declare(strict_types=1);

namespace Nadybot\Modules\EXPORT_MODULE;

use function Safe\{file_put_contents, json_decode, json_encode, mkdir};
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
			mkdir("{$dataPath}/export", 0700);
		}
		$context->reply("Starting export...");
		$exports = new stdClass();
		$exports->alts = $this->exportAlts();
		$exports->auctions = $this->exportAuctions();
		$exports->banlist = $this->exportBanlist();
		$exports->cityCloak = $this->exportCloak();
		$exports->commentCategories = $this->exportCommentCategories();
		$exports->comments = $this->exportComments();
		$exports->events = $this->exportEvents();
		$exports->links = $this->exportLinks();
		$exports->members = $this->exportMembers();
		$exports->news = $this->exportNews();
		$exports->notes = $this->exportNotes();
		$exports->orgNotes = $this->exportOrgNotes();
		$exports->polls = $this->exportPolls();
		$exports->quotes = $this->exportQuotes();
		$exports->raffleBonus = $this->exportRaffleBonus();
		$exports->raidBlocks = $this->exportRaidBlocks();
		$exports->raids = $this->exportRaidLogs();
		$exports->raidPoints = $this->exportRaidPoints();
		$exports->raidPointsLog = $this->exportRaidPointsLog();
		$exports->timers = $this->exportTimers();
		$exports->trackedCharacters = $this->exportTrackedCharacters();
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

	protected function toChar(?string $name, ?int $uid=null): Character {
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
	}

	/** @return stdClass[] */
	protected function exportAlts(): array {
		$alts = $this->db->table("alts")->asObj(Alt::class);

		/** @var array<string,stdClass[]> */
		$data = [];
		foreach ($alts as $alt) {
			if ($alt->main === $alt->alt) {
				continue;
			}
			$data[$alt->main] ??= [];
			$data[$alt->main] []= $this->toClass([
				"alt" => $this->toChar($alt->alt),
				"validatedByMain" => $alt->validated_by_main ?? true,
				"validatedByAlt" => $alt->validated_by_alt ?? true,
			]);
		}

		/** @var stdClass[] */
		$result = [];
		foreach ($data as $main => $altInfo) {
			$result []= $this->toClass([
				"main" => $this->toChar($main),
				"alts" => $altInfo,
			]);
		}

		return $result;
	}

	/** @return stdClass[] */
	protected function exportMembers(): array {
		$exported = [];

		/** @var stdClass[] */
		$result = [];

		/** @var Member[] */
		$members = $this->db->table(PrivateChannelController::DB_TABLE)
			->asObj(Member::class);
		foreach ($members as $member) {
			$result []= $this->toClass([
				"character" => $this->toChar($member->name),
				"autoInvite" => (bool)$member->autoinv,
				"joinedTime" => $member->joined,
			]);
			$exported[$member->name] = true;
		}

		/** @var RaidRank[] */
		$members = $this->db->table(RaidRankController::DB_TABLE)
			->asObj(RaidRank::class);
		foreach ($members as $member) {
			if (isset($exported[$member->name])) {
				continue;
			}
			$result []= $this->toClass([
				"character" => $this->toChar($member->name),
			]);
			$exported[$member->name] = true;
		}
		$members = $this->db->table(GuildController::DB_TABLE)
			->where("mode", "!=", "del")
			->asObj(OrgMember::class);
		foreach ($members as $member) {
			if (isset($exported[$member->name])) {
				continue;
			}
			$result []= $this->toClass([
				"character" => $this->toChar($member->name),
				"autoInvite" => false,
			]);
			$exported[$member->name] = true;
		}

		/** @var Admin[] */
		$members = $this->db->table(AdminManager::DB_TABLE)
			->asObj(Admin::class);
		foreach ($members as $member) {
			if (isset($exported[$member->name])) {
				continue;
			}
			$result []= $this->toClass([
				"character" => $this->toChar($member->name),
				"autoInvite" => false,
			]);
			$exported[$member->name] = true;
		}
		foreach ($this->config->general->superAdmins as $superAdmin) {
			if (!isset($exported[$superAdmin])) {
				$result []= $this->toClass([
					"character" => $this->toChar($superAdmin),
					"autoInvite" => false,
					"rank" => "superadmin",
				]);
			}
		}
		foreach ($result as &$datum) {
			$datum->rank ??= $this->accessManager->getSingleAccessLevel($datum->character->name);
			$logonMessage = $this->preferences->get($datum->character->name, "logon_msg");
			$logoffMessage = $this->preferences->get($datum->character->name, "logoff_msg");
			$massMessages = $this->preferences->get($datum->character->name, MassMsgController::PREF_MSGS);
			$massInvites = $this->preferences->get($datum->character->name, MassMsgController::PREF_INVITES);
			if (isset($logonMessage) && strlen($logonMessage)) {
				$datum->logonMessage ??= $logonMessage;
			}
			if (isset($logoffMessage) && strlen($logoffMessage)) {
				$datum->logoffMessage ??= $logoffMessage;
			}
			if (isset($massMessages) && strlen($massMessages)) {
				$datum->receiveMassMessages ??= $massMessages === "on";
			}
			if (isset($massInvites) && strlen($massInvites)) {
				$datum->receiveMassInvites ??= $massInvites === "on";
			}
		}
		return $result;
	}

	/** @return stdClass[] */
	protected function exportQuotes(): array {
		return $this->db->table("quote")
			->orderBy("id")
			->asObj(Quote::class)
			->map(function (Quote $quote): stdClass {
				return $this->toClass([
					"quote" => $quote->msg,
					"time" => $quote->dt,
					"contributor" => $this->toChar($quote->poster),
				]);
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportBanlist(): array {
		return $this->db->table(BanController::DB_TABLE)
			->asObj(BanEntry::class)
			->map(function (BanEntry $banEntry): stdClass {
				$name = $this->chatBot->getName($banEntry->charid);
				$ban = $this->toClass([
					"character" => $this->toChar($name, $banEntry->charid),
					"bannedBy" => $this->toChar($banEntry->admin),
					"banReason" => $banEntry->reason,
					"banStart" => $banEntry->time,
				]);
				if (isset($banEntry->banend) && $banEntry->banend > 0) {
					$ban->banEnd = $banEntry->banend;
				}
				return $ban;
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportCloak(): array {
		return $this->db->table(CloakController::DB_TABLE)
			->asObj(OrgCity::class)
			->map(function (OrgCity $cloakEntry): stdClass {
				return $this->toClass([
					"character" => $this->toChar(preg_replace("/\*$/", "", $cloakEntry->player)),
					"manualEntry" => (bool)preg_match("/\*$/", $cloakEntry->player),
					"cloakOn" => ($cloakEntry->action === "on"),
					"time" => $cloakEntry->time,
				]);
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportPolls(): array {
		return $this->db->table(VoteController::DB_POLLS)
			->asObj(Poll::class)
			->map(function (Poll $poll): stdClass {
				$export = $this->toClass([
					"author" => $this->toChar($poll->author),
					"question" => $poll->question,
					"answers" => [],
					"startTime" => $poll->started,
					"endTime" => $poll->started + $poll->duration,
				]);
				$answers = [];
				foreach (json_decode($poll->possible_answers, false) as $answer) {
					$answers[$answer] ??= $this->toClass([
						"answer" => $answer,
						"votes" => [],
					]);
				}

				/** @var Vote[] */
				$votes = $this->db->table(VoteController::DB_VOTES)
					->where("poll_id", $poll->id)
					->asObj(Vote::class);
				foreach ($votes as $vote) {
					if (!isset($vote->answer)) {
						continue;
					}
					$answers[$vote->answer] ??= $this->toClass([
						"answer" => $vote->answer,
						"votes" => [],
					]);
					$answer = $this->toClass([
						"character" => $this->toChar($vote->author),
					]);
					if (isset($vote->time)) {
						$answer->voteTime = $vote->time;
					}
					$answers[$vote->answer]->votes []= $answer;
				}
				$export->answers = array_values($answers);
				return $export;
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportRaffleBonus(): array {
		return $this->db->table(RaffleController::DB_TABLE)
			->orderBy("name")
			->asObj(RaffleBonus::class)
			->map(function (RaffleBonus $bonus): stdClass {
				return $this->toClass([
					"character" => $this->toChar($bonus->name),
					"raffleBonus" => $bonus->bonus,
				]);
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportRaidBlocks(): array {
		return $this->db->table(RaidBlockController::DB_TABLE)
			->orderBy("player")
			->asObj(RaidBlock::class)
			->map(function (RaidBlock $block): stdClass {
				$entry = $this->toClass([
					"character" => $this->toChar($block->player),
					"blockedFrom" => $block->blocked_from,
					"blockedBy" => $this->toChar($block->blocked_by),
					"blockedReason" => $block->reason,
					"blockStart" => $block->time,
				]);
				if (isset($block->expiration)) {
					$entry->blockEnd = $block->expiration;
				}
				return $entry;
			})->toArray();
	}

	protected function nullIf(int $value, int $nullvalue=0): ?int {
		return ($value === $nullvalue) ? null : $value;
	}

	/** @return stdClass[] */
	protected function exportRaidLogs(): array {
		/** @var RaidLog[] */
		$data = $this->db->table(RaidController::DB_TABLE_LOG)
			->orderBy("raid_id")
			->asObj(RaidLog::class)
			->toArray();

		/** @var stdClass[] */
		$raids = [];
		foreach ($data as $raid) {
			$raids[$raid->raid_id] ??= $this->toClass([
				"raidId" => $raid->raid_id,
				"time" => $raid->time,
				"raidDescription" => $raid->description,
				"raidLocked" => $raid->locked,
				"raidAnnounceInterval" => $raid->announce_interval,
				"raiders" => [],
				"history" => [],
			]);
			if ($raid->seconds_per_point > 0) {
				$raids[$raid->raid_id]->raidSecondsPerPoint = $raid->seconds_per_point;
			}
			$raids[$raid->raid_id]->history[] = $this->toClass([
				"time" => $raid->time,
				"raidDescription" => $raid->description,
				"raidLocked" => $raid->locked,
				"raidAnnounceInterval" => $raid->announce_interval,
				"raidSecondsPerPoint" => $this->nullIf($raid->seconds_per_point),
			]);
		}

		/** @var RaidMember[] */
		$data = $this->db->table(RaidMemberController::DB_TABLE)
			->asObj(RaidMember::class)
			->toArray();
		foreach ($data as $raidMember) {
			$raider = $this->toClass([
				"character" => $this->toChar($raidMember->player),
				"joinTime" => $raidMember->joined,
			]);
			if (isset($raidMember->left)) {
				$raider->leaveTime = $raidMember->left;
			}
			$raids[$raidMember->raid_id]->raiders []= $raider;
		}
		return array_values($raids);
	}

	/** @return stdClass[] */
	protected function exportRaidPoints(): array {
		return $this->db->table(RaidPointsController::DB_TABLE)
			->orderBy("username")
			->asObj(RaidPoints::class)
			->map(function (RaidPoints $datum): stdClass {
				return $this->toClass([
					"character" => $this->toChar($datum->username),
					"raidPoints" => $datum->points,
				]);
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportRaidPointsLog(): array {
		return $this->db->table(RaidPointsController::DB_TABLE_LOG)
			->orderBy("time")
			->orderBy("username")
			->asObj(RaidPointsLog::class)
			->map(function (RaidPointsLog $datum): stdClass {
				$raidLog = $this->toClass([
					"character" => $this->toChar($datum->username),
					"raidPoints" => (float)$datum->delta,
					"time" => $datum->time,
					"givenBy" => $this->toChar($datum->changed_by),
					"reason" => $datum->reason,
					"givenByTick" => $datum->ticker,
					"givenIndividually" => $datum->individual,
				]);
				if (isset($datum->raid_id)) {
					$raidLog->raidId = $datum->raid_id;
				}
				return $raidLog;
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportTimers(): array {
		$timers = $this->timerController->getAllTimers();
		$result = [];
		foreach ($timers as $timer) {
			$data = $this->toClass([
				"startTime" => $timer->settime,
				"timerName" => $timer->name,
				"endTime" => $timer->endtime,
				"createdBy" => $this->toChar($timer->owner),
				"channels" => array_diff(explode(",", str_replace(["guild", "both", "msg"], ["org", "priv,org", "tell"], $timer->mode??"")), [""]),
				"alerts" => [],
			]);
			if (isset($timer->data) && (int)$timer->data > 0) {
				$data->repeatInterval = (int)$timer->data;
			}
			foreach ($timer->alerts as $alert) {
				$data->alerts []= $this->toClass([
					"time" => $alert->time,
					"message" => $alert->message,
				]);
			}
			$result []= $data;
		}
		return $result;
	}

	/** @return stdClass[] */
	protected function exportTrackedCharacters(): array {
		/** @var TrackedUser[] */
		$users = $this->db->table(TrackerController::DB_TABLE)
			->orderBy("added_dt")
			->asObj(TrackedUser::class)
			->toArray();
		$result = [];
		foreach ($users as $user) {
			$result[$user->uid] = $this->toClass([
				"character" => $this->toChar($user->name, $user->uid),
				"addedTime" => $user->added_dt,
				"addedBy" => $this->toChar($user->added_by),
				"events" => [],
			]);
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
			$result[$event->uid]->events []= $this->toClass([
				"time" => $event->dt,
				"event" => $event->event,
			]);
		}
		return array_values($result);
	}

	/** @return stdClass[] */
	protected function exportAuctions(): array {
		/** @var DBAuction[] */
		$auctions = $this->db->table(AuctionController::DB_TABLE)
			->orderBy("id")
			->asObj(DBAuction::class)
			->toArray();
		$result = [];
		foreach ($auctions as $auction) {
			$auctionObj = (object)[
				"item" => $auction->item,
				"startedBy" => $this->toChar($auction->auctioneer),
				"timeEnd" => $auction->end,
				"reimbursed" => $auction->reimbursed,
			];
			if (isset($auction->winner)) {
				$auctionObj->winner = $this->toChar($auction->winner);
			}
			if (isset($auction->cost)) {
				$auctionObj->cost = (float)$auction->cost;
			}
			$result []= $auctionObj;
		}

		/** @var stdClass[] $result */
		return $result;
	}

	/** @return stdClass[] */
	protected function exportNews(): array {
		return $this->db->table("news")
			->asObj(News::class)
			->map(function (News $topic): stdClass {
				$data = $this->toClass([
					"author" => $this->toChar($topic->name),
					"uuid" => $topic->uuid,
					"addedTime" => $topic->time,
					"news" => $topic->news,
					"pinned" => $topic->sticky,
					"deleted" => $topic->deleted,
					"confirmedBy" => [],
				]);

				/** @var NewsConfirmed[] */
				$confirmations = $this->db->table("news_confirmed")
					->where("id", $topic->id)
					->asObj(NewsConfirmed::class)
					->toArray();
				foreach ($confirmations as $confirmation) {
					$data->confirmedBy []= (object)[
						"character" => $this->toChar($confirmation->player),
						"confirmationTime" => $confirmation->time,
					];
				}
				return $data;
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportNotes(): array {
		return $this->db->table("notes")
			->asObj(Note::class)
			->map(function (Note $note): stdClass {
				$data = $this->toClass([
					"owner" => $this->toChar($note->owner),
					"author" => $this->toChar($note->added_by),
					"creationTime" => $note->dt,
					"text" => $note->note,
				]);
				if ($note->reminder === Note::REMIND_ALL) {
					$data->remind = "all";
				} elseif ($note->reminder === Note::REMIND_SELF) {
					$data->remind = "author";
				}
				return $data;
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportOrgNotes(): array {
		return $this->db->table(OrgNotesController::DB_TABLE)
			->asObj(OrgNote::class)
			->map(function (OrgNote $note): stdClass {
				return $this->toClass([
					"author" => $this->toChar($note->added_by),
					"creationTime" => $note->added_on,
					"text" => $note->note,
					"uuid" => $note->uuid,
				]);
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportEvents(): array {
		return $this->db->table("events")
			->asObj(EventModel::class)
			->map(function (EventModel $event): stdClass {
				$attendees = array_values(array_diff(explode(",", $event->event_attendees ?? ""), [""]));

				$data = $this->toClass([
					"createdBy" => $this->toChar($event->submitter_name),
					"creationTime" => $event->time_submitted,
					"name" => $event->event_name,
					"attendees" => [],
				]);
				if (isset($event->event_date)) {
					$data->startTime = $event->event_date;
				}
				if (isset($event->event_desc)) {
					$data->description = $event->event_desc;
				}
				foreach ($attendees as $attendee) {
					$data->attendees []= $this->toChar($attendee);
				}

				return $data;
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportLinks(): array {
		return $this->db->table("links")
			->asObj(Link::class)
			->map(function (Link $link): stdClass {
				return $this->toClass([
					"createdBy" => $this->toChar($link->name),
					"creationTime" => $link->dt,
					"url" => $link->website,
					"description" => $link->comments,
				]);
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportCommentCategories(): array {
		return $this->db->table("<table:comment_categories>")
			->asObj(CommentCategory::class)
			->map(function (CommentCategory $category): stdClass {
				return $this->toClass([
					"name" => $category->name,
					"createdBy" => $this->toChar($category->created_by),
					"createdAt" => $category->created_at,
					"minRankToRead" => $category->min_al_read,
					"minRankToWrite" => $category->min_al_write,
					"systemEntry" => !$category->user_managed,
				]);
			})->toArray();
	}

	/** @return stdClass[] */
	protected function exportComments(): array {
		return $this->db->table("<table:comments>")
			->asObj(Comment::class)
			->map(function (Comment $comment): stdClass {
				return $this->toClass([
					"comment" => $comment->comment,
					"targetCharacter" => $this->toChar($comment->character),
					"createdBy" => $this->toChar($comment->created_by),
					"createdAt" => $comment->created_at,
					"category" => $comment->category,
				]);
			})->toArray();
	}

	/** @param array<array-key,mixed> $data */
	private function toClass(array $data): stdClass {
		/** @var stdClass */
		$result = (object)$data;
		return $result;
	}
}
