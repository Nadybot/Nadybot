<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	DB,
	Event,
	EventManager,
	ModuleInstance,
	LoggerWrapper,
	MessageEmitter,
	MessageHub,
	Nadybot,
	ParamClass\PDuration,
	ParamClass\PRemove,
	Routing\RoutableMessage,
	Routing\Source,
	SettingManager,
	Text,
	Timer,
	Util,
};

/**
 * @author Nadyita (RK5)
 * @author Lucier (RK1)
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "vote",
		accessLevel: "all",
		description: "Vote in polls",
		help: "vote.txt"
	),
	NCA\DefineCommand(
		command: "poll",
		accessLevel: "all",
		description: "Create, view or delete polls",
		help: "vote.txt"
	),
	NCA\ProvidesEvent("poll(start)"),
	NCA\ProvidesEvent("poll(end)"),
	NCA\ProvidesEvent("poll(del)"),
	NCA\ProvidesEvent("vote(cast)"),
	NCA\ProvidesEvent("vote(del)"),
	NCA\ProvidesEvent("vote(change)")
]
class VoteController extends ModuleInstance implements MessageEmitter {

	public const DB_POLLS = "polls_<myname>";
	public const DB_VOTES = "votes_<myname>";

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public MessageHub $messageHub;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	/** @var array<int,Poll> */
	private $polls = [];

	public const DELIMITER = "|";

	// status indicates the last alert that happened (not the next alert that will happen)
	public const STATUS_CREATED = 0;
	public const STATUS_STARTED = 1;
	public const STATUS_60_MINUTES_LEFT = 2;
	public const STATUS_15_MINUTES_LEFT = 3;
	public const STATUS_60_SECONDS_LEFT = 4;
	public const STATUS_ENDED = 9;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->commandAlias->register($this->moduleName, "poll", "polls");
		$this->cacheVotes();
		$this->messageHub->registerMessageEmitter($this);
	}

	public function getChannelName(): string {
		return Source::SYSTEM . "(votes)";
	}

	public function cacheVotes(): void {
		$this->db->table(self::DB_POLLS)
			->where("status", "!=", self::STATUS_ENDED)
			->asObj(Poll::class)
			->each(function (Poll $topic): void {
				$topic->answers = \Safe\json_decode($topic->possible_answers, false);
				$this->polls[$topic->id] = $topic;
			});
	}

	public function getPoll(int $id, string $creator=null): ?Poll {
		$query = $this->db->table(self::DB_POLLS)->where("id", $id);
		if ($creator !== null) {
			$query->where("owner", $creator);
		}
		/** @var ?Poll */
		$topic = $query->asObj(Poll::class)->first();
		if ($topic === null) {
			return null;
		}
		$topic->answers = \Safe\json_decode($topic->possible_answers);
		return $topic;
	}

	/**
	 * This event handler checks for polls ending.
	 */
	#[NCA\Event(
		name: "timer(2sec)",
		description: "Checks polls and periodically updates chat with time left"
	)]
	public function checkVote(Event $eventObj): void {
		if (count($this->polls) === 0) {
			return;
		}

		$msg = [];
		foreach ($this->polls as $id => $poll) {
			$timeleft = $poll->getTimeLeft();

			if ($timeleft <= 0) {
				$title = "Finished Vote: $poll->question";
				$this->db->table(self::DB_POLLS)
					->where("id", $poll->id)
					->update(["status" => self::STATUS_ENDED]);
				$event = new PollEvent();
				$event->poll = clone($poll);
				unset($event->poll->possible_answers);
				$event->votes = $this->db->table(self::DB_VOTES)
					->where("poll_id", $poll->id)
					->asObj(Vote::class)->toArray();
				$event->type = "poll(end)";
				$this->eventManager->fireEvent($event);
				unset($this->polls[$id]);
			} elseif ($poll->status === self::STATUS_CREATED) {
				$title = "Vote: $poll->question";

				if ($timeleft > 3600) {
					$mstatus = self::STATUS_STARTED;
				} elseif ($timeleft > 900) {
					$mstatus = self::STATUS_60_MINUTES_LEFT;
				} elseif ($timeleft > 60) {
					$mstatus = self::STATUS_15_MINUTES_LEFT;
				} else {
					$mstatus = self::STATUS_60_SECONDS_LEFT;
				}
				$this->polls[$id]->status = $mstatus;
			// @phpstan-ignore-next-line
			} elseif ($timeleft <= 60 && $timeleft > 0 && $poll->status !== self::STATUS_60_SECONDS_LEFT) {
				$title = "60 seconds left: $poll->question";
				$this->polls[$id]->status = self::STATUS_60_SECONDS_LEFT;
			} elseif ($timeleft <= 900 && $timeleft > 60 && $poll->status !== self::STATUS_15_MINUTES_LEFT) {
				$title = "15 minutes left: $poll->question";
				$this->polls[$id]->status = self::STATUS_15_MINUTES_LEFT;
			} elseif ($timeleft <= 3600 && $timeleft > 900 && $poll->status !== self::STATUS_60_MINUTES_LEFT) {
				$title = "60 minutes left: $poll->question";
				$this->polls[$id]->status = self::STATUS_60_MINUTES_LEFT;
			} else {
				$title = "";
			}

			if ($title === "") {
				continue;
			}
			$blob = $this->getPollBlob($poll);

			$pages = (array)$this->text->makeBlob($title, $blob);
			foreach ($pages as $page) {
				$msg []= $page;
			}
		}
		if (count($msg)) {
			$rMsg = new RoutableMessage(join("\n", $msg));
			$rMsg->appendPath(new Source(Source::SYSTEM, "votes"));
			$this->messageHub->handle($rMsg);
		}
	}

	/**
	 * This command handler shows votes.
	 */
	#[NCA\HandlesCommand("poll")]
	public function pollCommand(CmdContext $context): void {
		/** @var Poll[] */
		$topics = $this->db->table(self::DB_POLLS)
			->orderBy("started")
			->asObj(Poll::class)->toArray();
		$running = "";
		$over = "";
		$blob = "";
		if (count($topics) === 0) {
			$msg = "There are currently no polls.";
			$context->reply($msg);
			return;
		}
		foreach ($topics as $topic) {
			$line = "<tab>" . $this->text->makeChatcmd(
				$topic->question,
				"/tell <myname> poll show {$topic->id}"
			);

			$timeleft = $topic->getTimeLeft();
			if ($timeleft > 0) {
				$running .= $line . " (" . $this->util->unixtimeToReadable($timeleft) . " left)\n";
			} else {
				$over .= $line . "\n";
			}
		}
		if ($running) {
			$blob .= "<green>Running polls:<end>\n{$running}\n";
		}
		if ($over) {
			$blob .= "<red>Finished polls:<end>\n{$over}";
		}

		$msg = $this->text->makeBlob("All voting topics", $blob);
		$context->reply($msg);
	}

	/**
	 * This command handler deletes polls.
	 */
	#[NCA\HandlesCommand("poll")]
	public function pollKillCommand(CmdContext $context, PRemove $action, int $pollId): void {
		$owner = null;
		if (!$this->accessManager->checkAccess($context->char->name, "moderator")) {
			$owner = $context->char->name;
		}
		$topic = $this->getPoll($pollId, $owner);

		if ($topic === null) {
			$msg = "Either this poll does not exist, or you did not create it.";
			$context->reply($msg);
			return;
		}
		$this->db->table(self::DB_VOTES)->where("poll_id", $topic->id)->delete();
		$this->db->table(self::DB_POLLS)->delete($topic->id);
		$event = new PollEvent();
		$event->poll = clone($topic);
		unset($event->poll->possible_answers);
		$event->type = "poll(del)";
		unset($this->polls[$topic->id]);
		$msg = "The poll <highlight>{$topic->question}<end> has been removed.";
		$context->reply($msg);
		$this->eventManager->fireEvent($event);
	}

	/**
	 * This command handler removes someones vote from a running vote.
	 */
	#[NCA\HandlesCommand("vote")]
	public function voteRemoveCommand(CmdContext $context, PRemove $action, int $pollId): void {
		if (!isset($this->polls[$pollId])) {
			$msg = "There is no active poll Nr. <highlight>{$pollId}<end>.";
			$context->reply($msg);
			return;
		}
		$topic = $this->polls[$pollId];
		$deleted = $this->db->table(self::DB_VOTES)
			->where("poll_id", $pollId)
			->where("author", $context->char->name)
			->delete();
		if ($deleted > 0) {
			$msg = "Your vote for <highlight>{$topic->question}<end> has been removed.";
			$event = new VoteEvent();
			$event->poll = clone($topic);
			unset($event->poll->possible_answers);
			$event->type = "vote(del)";
			$event->player = $context->char->name;
			$this->eventManager->fireEvent($event);
		} else {
			$msg = "You have not voted on <highlight>{$topic->question}<end>.";
		}
		$context->reply($msg);
	}

	/**
	 * This command handler ends a running vote.
	 */
	#[NCA\HandlesCommand("poll")]
	public function pollEndCommand(CmdContext $context, #[NCA\Str("end")] string $action, int $pollId): void {
		$topic = $this->getPoll($pollId);

		if ($topic === null) {
			$msg = "Either this poll does not exist, or you did not create it.";
			$context->reply($msg);
			return;
		}
		$timeleft = $topic->getTimeLeft();

		if ($timeleft > 60) {
			$topic->duration = (time() - $topic->started) + 61;
			$this->db->table(self::DB_POLLS)
				->where("id", $topic->id)
				->update(["duration" => $topic->duration]);
			$this->polls[$pollId]->duration = $topic->duration;
			$msg = "Vote duration reduced to 60 seconds.";
		} elseif ($timeleft <= 0) {
			$msg = "This poll has already finished.";
		} else {
			$msg = "There is only <highlight>$timeleft<end> seconds left.";
		}
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("poll")]
	public function voteShowCommand(CmdContext $context, #[NCA\Regexp("show|view")] ?string $action, int $id): void {
		$topic = $this->getPoll($id);
		if ($topic === null) {
			$context->reply("There is no poll Nr. <highlight>{$id}<end>.");
			return;
		}

		$blob = $this->getPollBlob($topic, $context->char->name);

		/** @var ?Vote */
		$vote = $this->db->table(self::DB_VOTES)
			->where("poll_id", $topic->id)
			->where("author", "sender")
			->asObj(Vote::class)
			->first();
		$timeleft = $topic->getTimeLeft();
		if (isset($vote) && $vote->answer && $timeleft > 0) {
			$privmsg = "You voted: <highlight>{$vote->answer}<end>.";
		} elseif ($timeleft > 0) {
			$privmsg = "You have not voted on this yet.";
		}

		$msg = $this->text->makeBlob("Poll Nr. {$topic->id}", $blob);
		if (isset($privmsg)) {
			$context->reply($privmsg);
		}

		$context->reply($msg);
	}

	#[NCA\HandlesCommand("vote")]
	public function voteCommand(CmdContext $context, int $pollId, string $answer): void {
		$topic = $this->getPoll($pollId);
		if ($topic === null) {
			$msg = "Poll Nr. <highlight>{$pollId}<end> does not exist.";
			$context->reply($msg);
			return;
		}
		$timeleft = $topic->getTimeLeft();

		if ($timeleft <= 0) {
			$msg = "No longer accepting votes for this poll.";
			$context->reply($msg);
			return;
		}
		/** @var ?Vote */
		$oldVote = $this->db->table(self::DB_VOTES)
			->where("poll_id", $topic->id)
			->where("author", $context->char->name)
			->asObj(Vote::class)
			->first();
		$event = new VoteEvent();
		$event->poll = clone($topic);
		unset($event->poll->possible_answers);
		$event->player = $context->char->name;
		$event->vote = $answer;
		if (isset($oldVote)) {
			$this->db->table(self::DB_VOTES)
				->where("author", $context->char->name)
				->where("poll_id", $topic->id)
				->update([
					"answer" => $answer,
					"time" => time(),
				]);
			$msg = "You have changed your vote to ".
				"<highlight>{$answer}<end> for \"{$topic->question}\".";
			$event->type = "vote(change)";
			$event->oldVote = $oldVote->answer??"unknown";
		} else {
			$this->db->table(self::DB_VOTES)->insert([
				"author" => $context->char->name,
				"answer" => $answer,
				"time" => time(),
				"poll_id" => $topic->id
			]);
			$msg = "You have voted <highlight>{$answer}<end> for \"{$topic->question}\".";
			$event->type = "vote(cast)";
		}
		$context->reply($msg);
		$this->eventManager->fireEvent($event);
	}

	#[NCA\HandlesCommand("poll")]
	public function pollCreateCommand(
		CmdContext $context,
		#[NCA\Regexp("add|create|new")] string $action,
		PDuration $duration,
		string $definition
	): void {
		$answers = \Safe\preg_split("/\s*\Q" . self::DELIMITER . "\E\s*/", $definition);
		$question = array_shift($answers);
		$duration = $duration->toSecs();

		if ($duration === 0) {
			$msg = "Invalid duration entered. Time format should be: 1d2h3m4s";
			$context->reply($msg);
			return;
		}
		if (count($answers) < 2) {
			$msg = "You must have at least two options for this poll.";
			$context->reply($msg);
			return;
		}
		$topic = new Poll();
		$topic->question = $question;
		$topic->author = $context->char->name;
		$topic->started = time();
		$topic->duration = $duration;
		$topic->answers = $answers;
		$topic->possible_answers = \Safe\json_encode($answers);
		$topic->status = self::STATUS_CREATED;

		$topic->id = $this->db->insert(self::DB_POLLS, $topic);
		$this->polls[$topic->id] = $topic;
		$msg = "Voting topic <highlight>{$topic->id}<end> has been created.";

		$context->reply($msg);
		$event = new PollEvent();
		$event->poll = clone($topic);
		unset($event->poll->possible_answers);
		$event->type = "poll(start)";
		$this->eventManager->fireEvent($event);
	}

	public function getPollBlob(Poll $topic, ?string $sender=null): string {
		/** @var Vote[] */
		$votes = $this->db->table(self::DB_VOTES)
			->where("poll_id", $topic->id)
			->asObj(Vote::class)
			->toArray();

		$results = [];
		foreach ($topic->answers as $answer) {
			$results[$answer] = 0;
		}
		$totalresults = 0;
		foreach ($votes as $vote) {
			$answer = $vote->answer;
			if (isset($answer)) {
				$results[$answer]++;
				$totalresults++;
			}
		}

		$timeleft = $topic->getTimeLeft();
		$blob = "<header2>{$topic->author}'s Poll<end>\n".
			"<tab><highlight>{$topic->question}<end>\n\n";
		if ($timeleft > 0) {
			$blob .= $this->util->unixtimeToReadable($timeleft)." till this poll closes.\n\n";
		} else {
			$blob .= "<red>This poll has ended " . $this->util->unixtimeToReadable($timeleft * -1, true) . " ago.<end>\n\n";
		}

		$blob .= "<header2>Answers<end>\n";
		foreach ($results as $answer => $votes) {
			if ($totalresults === 0) {
				$val = 0;
			} else {
				$val = (int)round(100 * ($votes / $totalresults), 0);
			}
			$blob .= "<tab>" . $this->text->alignNumber($val, 3) . "% ";

			if ($timeleft > 0) {
				$blob .= $this->text->makeChatcmd($answer, "/tell <myname> vote {$topic->id} {$answer}") . " (Votes: $votes)\n";
			} else {
				$blob .= "<highlight>{$answer}<end> (Votes: {$votes})\n";
			}
		}

		if ($timeleft > 0) {
			$blob .= "\n" .	$this->text->makeChatcmd(
				'Remove yourself from this poll',
				"/tell <myname> vote remove {$topic->id}"
			) . "\n";

			$blob .="\nDon't like these choices? Add your own:\n".
				"<tab>/tell <myname> vote {$topic->id} <highlight>your choice<end>\n";
		}

		if ($sender === null) {
			$blob .="\nIf you started this poll, you can:\n";
		} elseif ($sender === $topic->author) {
			$blob .="\nAs the creator of this poll, you can:\n";
		}
		if ($sender === null || $sender === $topic->author) {
			$blob .="<tab>" . $this->text->makeChatcmd('Delete the poll completely', "/tell <myname> poll delete {$topic->id}") . "\n";
			if ($timeleft > 0) {
				$blob .="<tab>" . $this->text->makeChatcmd('End the poll early', "/tell <myname> poll end {$topic->id}");
			}
		}

		return $blob;
	}

	#[
		NCA\NewsTile(
			name: "polls",
			description: "Shows currently running polls - if any",
			example:
				"<header2>Polls<end>\n".
				"<tab>Shall we use startpage instead of news? [<u>show</u>]\n".
				"<tab>New logo for Discord [<u>show</u>]"
		)
	]
	public function pollsNewsTile(string $sender, callable $callback): void {
		/** @var Poll[] */
		$topics = $this->db->table(self::DB_POLLS)
			->orderBy("started")
			->asObj(Poll::class)->toArray();
		if (count($topics) === 0) {
			$callback(null);
			return;
		}
		$lines = [];
		foreach ($topics as $topic) {
			$lines []= "<tab>{$topic->question} [" . $this->text->makeChatcmd(
				"show",
				"/tell <myname> poll show {$topic->id}"
			) . "]";
		}
		$blob = "<header2>Polls<end>\n".
			join("\n", $lines);
		$callback($blob);
	}
}
