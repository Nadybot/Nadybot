<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\{
	AccessManager,
	CommandAlias,
	CommandReply,
	DB,
	Event,
	EventManager,
	LoggerWrapper,
	MessageEmitter,
	MessageHub,
	Nadybot,
	SettingManager,
	Text,
	Timer,
	Util,
};
use Nadybot\Core\Routing\RoutableMessage;
use Nadybot\Core\Routing\Source;

/**
 * @author Nadyita (RK5)
 * @author Lucier (RK1)
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'vote',
 *		accessLevel = 'all',
 *		description = 'Vote in polls',
 *		help        = 'vote.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'poll',
 *		accessLevel = 'all',
 *		description = 'Create, view or delete polls',
 *		help        = 'vote.txt'
 *	)
 *	@ProvidesEvent("poll(start)")
 *	@ProvidesEvent("poll(end)")
 *	@ProvidesEvent("poll(del)")
 *	@ProvidesEvent("vote(cast)")
 *	@ProvidesEvent("vote(del)")
 *	@ProvidesEvent("vote(change)")
 */
class VoteController implements MessageEmitter {

	public const DB_POLLS = "polls_<myname>";
	public const DB_VOTES = "votes_<myname>";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public Timer $timer;

	/** @Inject */
	public MessageHub $messageHub;

	/** @Logger */
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
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
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
			->each(function (Poll $topic) {
				$topic->answers = json_decode($topic->possible_answers, false);
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
		$topic->answers = json_decode($topic->possible_answers);
		return $topic;
	}

	/**
	 * This event handler checks for polls ending.
	 *
	 * @Event("timer(2sec)")
	 * @Description("Checks polls and periodically updates chat with time left")
	 */
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

			$msg []= $this->text->makeBlob($title, $blob);
		}
		if (count($msg)) {
			$rMsg = new RoutableMessage(join("\n", $msg));
			$rMsg->appendPath(new Source(Source::SYSTEM, "votes"));
			$this->messageHub->handle($rMsg);
		}
	}

	/**
	 * This command handler shows votes.
	 *
	 * @HandlesCommand("poll")
	 * @Matches("/^poll$/i")
	 */
	public function pollCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var Poll[] */
		$topics = $this->db->table(self::DB_POLLS)
			->orderBy("started")
			->asObj(Poll::class)->toArray();
		$running = "";
		$over = "";
		$blob = "";
		if (count($topics) === 0) {
			$msg = "There are currently no polls.";
			$sendto->reply($msg);
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
		$sendto->reply($msg);
	}

	/**
	 * This command handler deletes polls.
	 *
	 * @HandlesCommand("poll")
	 * @Matches("/^poll (?:kill|del|delete|rem|remove|rm) (\d+)$/i")
	 */
	public function pollKillCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];
		$owner = null;
		if (!$this->accessManager->checkAccess($sender, "moderator")) {
			$owner = $sender;
		}
		$topic = $this->getPoll($id, $owner);

		if ($topic === null) {
			$msg = "Either this poll does not exist, or you did not create it.";
			$sendto->reply($msg);
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
		$sendto->reply($msg);
		$this->eventManager->fireEvent($event);
	}

	/**
	 * This command handler removes someones vote from a running vote.
	 *
	 * @HandlesCommand("vote")
	 * @Matches("/^vote (?:rem|remove|del|erase|rm|delete) (\d+)$/i")
	 */
	public function voteRemoveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];
		if (!isset($this->polls[$id])) {
			$msg = "There is no active poll Nr. <highlight>$id<end>.";
			$sendto->reply($msg);
			return;
		}
		$topic = $this->polls[$id];
		$deleted = $this->db->table(self::DB_VOTES)
			->where("poll_id", $id)
			->where("author", $sender)
			->delete();
		if ($deleted > 0) {
			$msg = "Your vote for <highlight>{$topic->question}<end> has been removed.";
			$event = new VoteEvent();
			$event->poll = clone($topic);
			unset($event->poll->possible_answers);
			$event->type = "vote(del)";
			$event->player = $sender;
			$this->eventManager->fireEvent($event);
		} else {
			$msg = "You have not voted on <highlight>{$topic->question}<end>.";
		}
		$sendto->reply($msg);
	}

	/**
	 * This command handler ends a running vote.
	 *
	 * @HandlesCommand("poll")
	 * @Matches("/^poll end (\d+)$/i")
	 */
	public function pollEndCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];
		$topic = $this->getPoll($id);

		if ($topic === null) {
			$msg = "Either this poll does not exist, or you did not create it.";
			$sendto->reply($msg);
			return;
		}
		$timeleft = $topic->getTimeLeft();

		if ($timeleft > 60) {
			$topic->duration = (time() - $topic->started) + 61;
			$this->db->table(self::DB_POLLS)
				->where("id", $topic->id)
				->update(["duration" => $topic->duration]);
			$this->polls[$id]->duration = $topic->duration;
			$msg = "Vote duration reduced to 60 seconds.";
		} elseif ($timeleft <= 0) {
			$msg = "This poll has already finished.";
		} else {
			$msg = "There is only <highlight>$timeleft<end> seconds left.";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("poll")
	 * @Matches("/^poll(?: show| view)? (\d+)$/i")
	 */
	public function voteShowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];
		$topic = $this->getPoll($id);
		if ($topic === null) {
			$sendto->reply("There is no poll Nr. <highlight>{$id}<end>.");
			return;
		}

		$blob = $this->getPollBlob($topic, $sender);

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
			$sendto->reply($privmsg);
		}

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("vote")
	 * @Matches("/^vote (\d+) (.+)$/i")
	 */
	public function voteCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];
		$answer = $args[2];

		$topic = $this->getPoll($id);
		if ($topic === null) {
			$msg = "Poll Nr. <highlight>{$id}<end> does not exist.";
			$sendto->reply($msg);
			return;
		}
		$timeleft = $topic->getTimeLeft();

		if ($timeleft <= 0) {
			$msg = "No longer accepting votes for this poll.";
			$sendto->reply($msg);
			return;
		}
		/** @var ?Vote */
		$oldVote = $this->db->table(self::DB_VOTES)
			->where("poll_id", $topic->id)
			->where("author", $sender)
			->asObj(Vote::class)
			->first();
		$event = new VoteEvent();
		$event->poll = clone($topic);
		unset($event->poll->possible_answers);
		$event->player = $sender;
		$event->vote = $answer;
		if ($oldVote) {
			$this->db->table(self::DB_VOTES)
				->where("author", $sender)
				->where("poll_id", $topic->id)
				->update([
					"answer" => $answer,
					"time" => time(),
				]);
			$msg = "You have changed your vote to ".
				"<highlight>{$answer}<end> for \"{$topic->question}\".";
			$event->type = "vote(change)";
			$event->oldVote = $oldVote->answer;
		} else {
			$this->db->table(self::DB_VOTES)->insert([
				"author" => $sender,
				"answer" => $answer,
				"time" => time(),
				"poll_id" => $topic->id
			]);
			$msg = "You have voted <highlight>{$answer}<end> for \"{$topic->question}\".";
			$event->type = "vote(cast)";
		}
		$sendto->reply($msg);
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("poll")
	 * @Matches("/^poll (?:add|create|new) ([^ ]+)\s+(.+)$/i")
	 */
	public function pollCreateCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args) {
		$answers = preg_split("/\s*\Q" . self::DELIMITER . "\E\s*/", $args[2]);
		$question = array_shift($answers);
		$duration = $this->util->parseTime($args[1]);

		if ($duration === 0) {
			$msg = "Invalid duration entered. Time format should be: 1d2h3m4s";
			$sendto->reply($msg);
			return;
		}
		if (count($answers) < 2) {
			$msg = "You must have at least two options for this poll.";
			$sendto->reply($msg);
			return;
		}
		$topic = new Poll();
		$topic->question = $question;
		$topic->author = $sender;
		$topic->started = time();
		$topic->duration = $duration;
		$topic->answers = $answers;
		$topic->possible_answers = json_encode($answers);
		$topic->status = self::STATUS_CREATED;

		$topic->id = $this->db->insert(self::DB_POLLS, $topic);
		$this->polls[$topic->id] = $topic;
		$msg = "Voting topic <highlight>{$topic->id}<end> has been created.";

		$sendto->reply($msg);
		$event = new PollEvent();
		$event->poll = clone($topic);
		unset($event->poll->possible_answers);
		$event->type = "poll(start)";
		$this->eventManager->fireEvent($event);
	}

	public function getPollBlob(Poll $topic, ?string $sender=null) {
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
			$results[$answer]++;
			$totalresults++;
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

	/**
	 * @NewsTile("polls")
	 * @Description("Shows currently running polls - if any")
	 * @Example("<header2>Polls<end>
	 * <tab>Shall we use startpage instead of news? [<u>show</u>]
	 * <tab>New logo for Discord [<u>show</u>]")
	 */
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
