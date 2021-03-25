<?php declare(strict_types=1);

namespace Nadybot\Modules\EXPORT_MODULE;

use Nadybot\Core\{
	AccessManager,
	BuddylistManager,
	CommandReply,
	DB,
	DBSchema\Alt,
	DBSchema\Member,
	Modules\PREFERENCES\Preferences,
	Nadybot,
	ProxyCapabilities,
};
use Nadybot\Core\DBSchema\Admin;
use Nadybot\Modules\{
	COMMENT_MODULE\Comment,
	COMMENT_MODULE\CommentCategory,
	GUILD_MODULE\OrgMember,
	NEWS_MODULE\News,
	NOTES_MODULE\Link,
	NOTES_MODULE\Note,
	QUOTE_MODULE\Quote,
	RAID_MODULE\RaidBlock,
	RAID_MODULE\RaidLog,
	RAID_MODULE\RaidMember,
	RAID_MODULE\RaidPoints,
	RAID_MODULE\RaidPointsLog,
	TIMERS_MODULE\Timer,
	TRACKER_MODULE\TrackedUser,
	TRACKER_MODULE\Tracking,
	VOTE_MODULE\Poll,
	VOTE_MODULE\Vote,
};
use Nadybot\Modules\MASSMSG_MODULE\MassMsgController;
use Nadybot\Modules\RAID_MODULE\RaidRank;
use stdClass;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'export',
 *		accessLevel = 'superadmin',
 *		description = 'Export the bot configuration and data',
 *		help        = 'export.txt'
 *	)
 */
class ExportController {

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

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public BuddylistManager $buddylistManager;

	/** @Inject */
	public Preferences $preferences;

	/**
	 * @HandlesCommand("export")
	 * @Matches("/^export (.+)$/i")
	 */
	public function exportCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$dataPath = $this->chatBot->vars["datafolder"] ?? "./data";
		$fileName = "{$dataPath}/export/" . basename($args[1]);
		if ((pathinfo($fileName)["extension"] ?? "") !== "json") {
			$fileName .= ".json";
		}
		if (!@file_exists("{$dataPath}/export")) {
			@mkdir("{$dataPath}/export", 0700);
		}
		if (($this->chatBot->vars["use_proxy"] ?? 0) == 1) {
			if (!$this->chatBot->proxyCapabilities->supportsBuddyMode(ProxyCapabilities::SEND_BY_WORKER)) {
				$sendto->reply(
					"You are using an unsupported proxy version. ".
					"Please upgrade to the latest AOChatProxy and try again."
				);
				return;
			}
		}
		$sendto->reply("Starting export...");
		$exports = new stdClass();
		$exports->alts = $this->exportAlts();
		$exports->auctions = $this->exportAuctions();
		$exports->banlist = $this->exportBanlist();
		$exports->cityCloak = $this->exportCloak();
		$exports->commentCategories = $this->exportCommentCategories();
		$exports->comments = $this->exportComments();
		$exports->links = $this->exportLinks();
		$exports->members = $this->exportMembers();
		$exports->news = $this->exportNews();
		$exports->notes = $this->exportNotes();
		$exports->polls = $this->exportPolls();
		$exports->quotes = $this->exportQuotes();
		$exports->raffleBonus = $this->exportRaffleBonus();
		$exports->raidBlocks = $this->exportRaidBlocks();
		$exports->raids = $this->exportRaidLogs();
		$exports->raidPoints = $this->exportRaidPoints();
		$exports->raidPointsLog = $this->exportRaidPointsLog();
		$exports->timers = $this->exportTimers();
		$exports->trackedCharacters = $this->exportTrackedCharacters();
		$output = @json_encode($exports, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
		if ($output === false) {
			$sendto->reply("There was an error exporting the data: " . error_get_last()["message"]);
			return;
		}
		if (!@file_put_contents($fileName, $output)) {
			$sendto->reply(substr(strstr(error_get_last()["message"], "): "), 3));
			return;
		}
		$sendto->reply("The export was successfully saved in {$fileName}.");
	}

	protected function toChar(?string $name, ?int $uid=null): Character {
		$char = new Character();
		if (isset($name)) {
			$char->name = $name;
		}
		$id = $uid ?? $this->chatBot->get_uid($name);
		if (isset($id) && is_int($id)) {
			$char->id = $id;
		}
		return $char;
	}

	protected function exportAlts(): array {
		/** @var Alt[] */
		$alts = $this->db->fetchAll(Alt::class, "SELECT * FROM `alts`");
		$data = [];
		foreach ($alts as $alt) {
			if ($alt->main === $alt->alt) {
				continue;
			}
			$data[$alt->main] ??= [];
			$data[$alt->main] []= (object)[
				"alt" => $this->toChar($alt->alt),
				"validatedByMain" => (bool)($alt->validated_by_main ?? true),
				"validatedByAlt" => (bool)($alt->validated_by_alt ?? true),
			];
		}
		$result = [];
		foreach ($data as $main => $altInfo) {
			$result []= (object)[
				"main" => $this->toChar($main),
				"alts" => $altInfo
			];
		}
		return $result;
	}

	protected function exportMembers(): array {
		$exported = [];
		$result = [];
		/** @var Member[] */
		$members = $this->db->fetchAll(Member::class, "SELECT * FROM `members_<myname>`");
		foreach ($members as $member) {
			$result []= (object)[
				"character" =>$this->toChar($member->name),
				"autoInvite" => (bool)$member->autoinv,
			];
			$exported[$member->name] = true;
		}
		/** @var RaidRank[] */
		$members = $this->db->fetchAll(RaidRank::class, "SELECT * FROM `raid_rank_<myname>`");
		foreach ($members as $member) {
			if (isset($exported[$member->name])) {
				continue;
			}
			$result []= (object)[
				"character" =>$this->toChar($member->name),
			];
			$exported[$member->name] = true;
		}
		$members = $this->db->fetchAll(OrgMember::class, "SELECT * FROM `org_members_<myname>` WHERE `mode` != 'del'");
		foreach ($members as $member) {
			if (isset($exported[$member->name])) {
				continue;
			}
			$result []= (object)[
				"character" =>$this->toChar($member->name),
				"autoInvite" => false,
			];
			$exported[$member->name] = true;
		}
		/** @var Admin[] */
		$members = $this->db->fetchAll(Admin::class, "SELECT * FROM `admin_<myname>`");
		foreach ($members as $member) {
			if (isset($exported[$member->name])) {
				continue;
			}
			$result []= (object)[
				"character" =>$this->toChar($member->name),
				"autoInvite" => (bool)$member->autoinv,
			];
			$exported[$member->name] = true;
		}
		if (!isset($exported[$this->chatBot->vars["SuperAdmin"]])) {
			$result []= (object)[
				"character" => $this->toChar($this->chatBot->vars["SuperAdmin"]),
				"autoInvite" => false,
				"rank" => "superadmin",
			];
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
	}

	protected function exportQuotes(): array {
		/** @var Quote[] */
		$quotes = $this->db->fetchAll(Quote::class, "SELECT * FROM `quote` ORDER BY `id` ASC");
		$result = [];
		foreach ($quotes as $quote) {
			$result []= (object)[
				"quote" => $quote->msg,
				"time" => $quote->dt,
				"contributor" => $this->toChar($quote->poster),
			];
		}
		return $result;
	}

	protected function exportBanlist(): array {
		$banList = $this->db->query("SELECT * FROM `banlist_<myname>`");
		$result = [];
		foreach ($banList as $banEntry) {
			$ban = (object)[
				"character" => $this->toChar($this->chatBot->lookupID($banEntry->charid), $banEntry->charid),
				"bannedBy" => $this->toChar($banEntry->admin),
				"banReason" => $banEntry->reason,
				"banStart" => $banEntry->time,
			];
			if (isset($banEntry->banend) && $banEntry->banend > 0) {
				$ban->banEnd = $banEntry->banend;
			}
			$result []= $ban;
		}
		return $result;
	}

	protected function exportCloak(): array {
		$cloakList = $this->db->query("SELECT * FROM `org_city_<myname>`");
		$result = [];
		foreach ($cloakList as $cloakEntry) {
			$result []= (object)[
				"character" =>$this->toChar(preg_replace("/\*$/", "", $cloakEntry->player)),
				"manualEntry" => (bool)preg_match("/\*$/", $cloakEntry->player),
				"cloakOn" => ($cloakEntry->action === "on"),
				"time" => $cloakEntry->time,
			];
		}
		return $result;
	}

	protected function exportPolls(): array {
		/** @var Poll[] */
		$polls = $this->db->fetchAll(Poll::class, "SELECT * FROM `polls_<myname>`");
		$result = [];
		foreach ($polls as $poll) {
			$export = (object)[
				"author" =>$this->toChar($poll->author),
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
			$votes = $this->db->fetchAll(Vote::class, "SELECT * FROM `votes_<myname>` WHERE `poll_id`=?", $poll->id);
			foreach ($votes as $vote) {
				$answers[$vote->answer] ??= (object)[
					"answer" => $vote->answer,
					"votes" => [],
				];
				$answers[$vote->answer]->votes []= (object)[
					"character" =>$this->toChar($vote->author),
					"voteTime" => $vote->time,
				];
			}
			$export->answers = array_values($answers);
			$result []= $export;
		}
		return $result;
	}

	protected function exportRaffleBonus(): array {
		$data = $this->db->query("SELECT * FROM `raffle_bonus_<myname>` ORDER BY `name` ASC");
		$result = [];
		foreach ($data as $bonus) {
			$result []= (object)[
				"character" => $this->toChar($bonus->name),
				"raffleBonus" => $bonus->bonus,
			];
		}
		return $result;
	}

	protected function exportRaidBlocks(): array {
		/** @var RaidBlock[] */
		$data = $this->db->fetchAll(RaidBlock::class, "SELECT * FROM `raid_block_<myname>` ORDER BY `player` ASC");
		$result = [];
		foreach ($data as $block) {
			$entry = (object)[
				"character" => $this->toChar($block->player),
				"blockedFrom" => $block->blocked_from,
				"blockedBy" => $this->toChar($block->blocked_by),
				"blockedReason" => $block->reason,
				"blockStart" => $block->time,
			];
			if (isset($block->expiration)) {
				$entry->blockEnd = $block->expiration;
			}
			$result []= $entry;
		}
		return $result;
	}

	protected function nullIf(int $value, int $nullvalue=0): ?int {
		return ($value === $nullvalue )? null : $value;
	}

	protected function exportRaidLogs(): array {
		/** @var RaidLog[] */
		$data = $this->db->fetchAll(RaidLog::class, "SELECT * FROM `raid_log_<myname>` ORDER BY `raid_id` ASC");
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
		$data = $this->db->fetchAll(RaidMember::class, "SELECT * FROM `raid_member_<myname>`");
		foreach ($data as $raidMember) {
			$raids[$raidMember->raid_id]->raiders []= (object)[
				"character" => $this->toChar($raidMember->player),
				"joinTime" => $raidMember->joined,
				"leaveTime" => $raidMember->left,
			];
		}
		return array_values($raids);
	}

	protected function exportRaidPoints(): array {
		/** @var RaidPoints[] */
		$data = $this->db->fetchAll(RaidPoints::class, "SELECT * FROM `raid_points_<myname>` ORDER BY `username` ASC");
		$result = [];
		foreach ($data as $datum) {
			$result []= (object)[
				"character" => $this->toChar($datum->username),
				"raidPoints" => $datum->points,
			];
		}
		return $result;
	}

	protected function exportRaidPointsLog(): array {
		/** @var RaidPointsLog[] */
		$data = $this->db->fetchAll(RaidPointsLog::class, "SELECT * FROM `raid_points_log_<myname>` ORDER BY `time` ASC, `username` ASC");
		$result = [];
		foreach ($data as $datum) {
			$raidLog = (object)[
				"character" => $this->toChar($datum->username),
				"raidPoints" => (float)$datum->delta,
				"time" => $datum->time,
				"givenBy" => $this->toChar($datum->changed_by),
				"reason" => $datum->reason,
				"givenByTick" => (bool)$datum->ticker,
				"givenIndividually" => (bool)$datum->individual,
			];
			if (isset($datum->raid_id)) {
				$raidLog->raidId = $datum->raid_id;
			}
			$result []= $raidLog;
		}
		return $result;
	}

	protected function exportTimers(): array {
		/** @var Timer[] */
		$timers = $this->db->query(
			"SELECT * FROM `timers_<myname>` ".
			"WHERE `callback` LIKE 'timercontroller.%' ".
			"ORDER BY `settime` ASC"
		);
		$result = [];
		foreach ($timers as $timer) {
			$data = (object)[
				"startTime" => $timer->settime,
				"timerName" => $timer->name,
				"endTime" => $timer->endtime,
				"createdBy" => $this->toChar($timer->owner),
				"channels" => explode(",", str_replace(["guild", "both", "msg"], ["org", "priv,org", "tell"], $timer->mode)),
				"alerts" => [],
			];
			if (!empty($timer->data)) {
				$data->repeatInterval = (int)$timer->data;
			}
			$alerts = json_decode($timer->alerts);
			foreach ($alerts as $alert) {
				$data->alerts []= (object)[
					"time" => $alert->time,
					"message" => $alert->message,
				];
			}
			$result []= $data;
		}
		return $result;
	}

	protected function exportTrackedCharacters(): array {
		/** @var TrackedUser[] */
		$users = $this->db->fetchAll(
			TrackedUser::class,
			"SELECT * FROM `tracked_users_<myname>` ORDER BY `added_dt` ASC"
		);
		$result = [];
		foreach ($users as $user) {
			$result[$user->uid] = (object)[
				"character" => $this->toChar($user->name, $user->uid),
				"addedTime" => $user->added_dt,
				"addedBy" => $this->toChar($user->added_by),
				"events" => [],
			];
		}
		/** @var Tracking[] */
		$events = $this->db->fetchAll(
			Tracking::class,
			"SELECT * FROM `tracking_<myname>` ORDER BY `dt` ASC"
		);
		foreach ($events as $event) {
			$result[$event->uid]->events []= (object)[
				"time" => $event->dt,
				"event" => $event->event,
			];
		}
		return array_values($result);
	}

	protected function exportAuctions(): array {
		$auctions = $this->db->query(
			"SELECT * FROM `auction_<myname>` ORDER BY `id` ASC"
		);
		$result = [];
		foreach ($auctions as $auction) {
			$auctionObj = (object)[
				"item" => $auction->item,
				"startedBy" => $this->toChar($auction->auctioneer),
				"timeEnd" => $auction->end,
				"reimbursed" => (bool)$auction->reimbursed
			];
			if (isset($auction->winner)) {
				$auctionObj->winner = $this->toChar($auction->winner);
			}
			if (isset($auction->cost)) {
				$auctionObj->cost = (float)$auction->cost;
			}
			if (isset($auction->raid_id)) {
				$auctionObj->raidId = $auction->raid_id;
			}
			$result []= $auctionObj;
		}
		return $result;
	}

	protected function exportNews(): array {
		/** @var News[] */
		$news = $this->db->fetchAll(News::class, "SELECT * FROM `news`");
		$result = [];
		foreach ($news as $topic) {
			$data = (object)[
				"author" => $this->toChar($topic->name),
				"addedTime" => $topic->time,
				"news" => $topic->news,
				"pinned" => $topic->sticky,
				"deleted" => $topic->deleted,
				"confirmedBy" => [],
			];
			$confirmations = $this->db->query("SELECT * FROM `news_confirmed` WHERE `id`=?", $topic->id);
			foreach ($confirmations as $confirmation) {
				$data->confirmedBy []= (object)[
					"character" => $this->toChar($confirmation->player),
					"confirmationTime" => $confirmation->time,
				];
			}
			$result []= $data;
		}
		return $result;
	}

	protected function exportNotes(): array {
		/** @var Note[] */
		$notes = $this->db->fetchAll(Note::class, "SELECT * FROM `notes`");
		$result = [];
		foreach ($notes as $note) {
			$data = (object)[
				"owner" => $this->toChar($note->owner),
				"author" => $this->toChar($note->added_by),
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
	}

	protected function exportLinks(): array {
		/** @var Link[] */
		$links = $this->db->fetchAll(Link::class, "SELECT * FROM `links`");
		$result = [];
		foreach ($links as $link) {
			$data = (object)[
				"createdBy" => $this->toChar($link->name),
				"creationTime" => $link->dt,
				"url" => $link->website,
				"description" => $link->comments,
			];
			$result []= $data;
		}
		return $result;
	}

	protected function exportCommentCategories(): array {
		/** @var CommentCategory[] */
		$categories = $this->db->fetchAll(CommentCategory::class, "SELECT * FROM `<table:comment_categories>`");
		$result = [];
		foreach ($categories as $category) {
			$data = (object)[
				"name" => $category->name,
				"createdBy" => $this->toChar($category->created_by),
				"createdAt" => $category->created_at,
				"minRankToRead" => $category->min_al_read,
				"minRankToWrite" => $category->min_al_write,
				"systemEntry" => !$category->user_managed,
			];
			$result []= $data;
		}
		return $result;
	}

	protected function exportComments(): array {
		/** @var Comment[] */
		$comments = $this->db->fetchAll(Comment::class, "SELECT * FROM `<table:comments>`");
		$result = [];
		foreach ($comments as $comment) {
			$data = (object)[
				"comment" => $comment->comment,
				"targetCharacter" => $this->toChar($comment->character),
				"createdBy" => $this->toChar($comment->created_by),
				"createdAt" => $comment->created_at,
				"category" => $comment->category,
			];
			$result []= $data;
		}
		return $result;
	}
}
