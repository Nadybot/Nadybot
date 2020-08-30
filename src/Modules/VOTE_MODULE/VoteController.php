<?php declare(strict_types=1);

namespace Nadybot\Modules\VOTE_MODULE;

use Nadybot\Core\{
	AccessManager,
	CommandAlias,
	CommandReply,
	DB,
	Event,
	LoggerWrapper,
	Nadybot,
	SettingManager,
	Text,
	Timer,
	Util,
};

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
 */
class VoteController {

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
	public Timer $timer;

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
		$this->db->loadSQLFile($this->moduleName, 'vote');
		
		$this->settingManager->add(
			$this->moduleName,
			"vote_channel_spam",
			"Showing Vote status messages in",
			"edit",
			"options",
			"2",
			"Private Channel;Guild;Private Channel and Guild;Neither",
			"0;1;2;3",
			"mod",
			"votesettings.txt"
		);
		$this->commandAlias->register($this->moduleName, "poll", "polls");

		if ($this->db->tableExists("vote_<myname>")) {
			$this->convertDBfromV1toV2();
		}

		/** @var Poll[] */
		$topics = $this->db->fetchAll(
			Poll::class,
			"SELECT * FROM polls_<myname> WHERE `status` != ?",
			self::STATUS_ENDED
		);
		foreach ($topics as $topic) {
			$topic->answers = json_decode($topic->possible_answers, false);
			$this->polls[$topic->id] = $topic;
		}
	}

	public function convertDBfromV1toV2(): void {
		if ($this->db->inTransaction()) {
			$this->timer->callLater(0, [$this, __FUNCTION__]);
			return;
		}
		$this->logger->log("INFO", "Converting old vote format into poll format");
		$oldPolls = $this->db->query("SELECT * FROM vote_<myname> WHERE duration IS NOT NULL");
		foreach ($oldPolls as $oldPoll) {
			$this->db->beginTransaction();
			if (!$this->db->exec(
				"INSERT INTO polls_<myname> ".
				"(author, question, possible_answers, started, duration, status) ".
				"VALUES (?,?,?,?,?,?)",
				$oldPoll->author,
				$oldPoll->question,
				json_encode(explode(self::DELIMITER, $oldPoll->answer)),
				(int)$oldPoll->started,
				(int)$oldPoll->duration,
				(int)$oldPoll->status
			)) {
				$this->logger->log("ERROR", "Cannot convert old polls into new format.");
				$this->db->rollback();
				return;
			}
			$id = $this->db->lastInsertId();
			$oldVotes = $this->db->query(
				"SELECT * FROM vote_<myname> WHERE question = ? AND duration IS NULL",
				$oldPoll->question
			);
			foreach ($oldVotes as $oldVote) {
				if (!$this->db->exec(
					"INSERT INTO votes_<myname> ".
					"(`poll_id`, `author`, `answer`) VALUES (?, ?, ?)",
					$id,
					$oldVote->author,
					$oldVote->answer
				)) {
					$this->logger->log("ERROR", "Cannot convert old votes into new format.");
					$this->db->rollback();
					return;
				}
			}
			$this->db->exec(
				"DELETE FROM vote_<myname> WHERE question = ?",
				$oldPoll->question
			);
			$this->db->commit();
			$this->logger->log("INFO", "Poll \"{$oldPoll->question}\" converted to new poll system");
		}
		$this->db->exec("DROP TABLE vote_<myname>");
		$this->logger->log("INFO", "Conversion completed");
	}

	public function getPoll(int $id, string $creator=null): ?Poll {
		$where = "";
		$sqlArgs = [];
		if ($creator !== null) {
			$where = " AND owner = ?";
			$sqlArgs []= $creator;
		}
		/** @var ?Poll */
		$topic = $this->db->fetch(
			Poll::class,
			"SELECT * FROM polls_<myname> WHERE id = ?{$where}",
			$id,
			...$sqlArgs
		);
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
				$this->db->exec(
					"UPDATE polls_<myname> SET `status` = ? WHERE `id` = ?",
					self::STATUS_ENDED,
					$poll->id
				);
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
			if ($this->settingManager->getInt("vote_channel_spam") === 0
				|| $this->settingManager->getInt("vote_channel_spam") === 2) {
				$this->chatBot->sendGuild(join("\n", $msg), true);
			}
			if ($this->settingManager->getInt("vote_channel_spam") === 1
				|| $this->settingManager->getInt("vote_channel_spam") === 2) {
				$this->chatBot->sendPrivate(join("\n", $msg), true);
			}
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
		$topics = $this->db->fetchAll(
			Poll::class,
			"SELECT * FROM polls_<myname> ORDER BY `started`"
		);
		$running = "";
		$over = "";
		$blob = "";
		if (count($topics) === 0) {
			$msg = "There are currently no votes to view.";
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
	public function voteKillCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
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
		$this->db->exec("DELETE FROM votes_<myname> WHERE `poll_id` = ?", $topic->id);
		$this->db->exec("DELETE FROM polls_<myname> WHERE `id` = ?", $topic->id);
		unset($this->polls[$topic->id]);
		$msg = "The poll <highlight>{$topic->question}<end> has been removed.";
		$sendto->reply($msg);
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
		$deleted = $this->db->exec(
			"DELETE FROM votes_<myname> WHERE `poll_id` = ? AND `author` = ?",
			$id,
			$sender
		);
		if ($deleted > 0) {
			$msg = "Your vote for <highlight>{$topic->question}<end> has been removed.";
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
			$this->db->exec(
				"UPDATE polls_<myname> SET `duration` = ? WHERE `id` = ?",
				$topic->duration,
				$topic->id
			);
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
		$vote = $this->db->fetch(
			Vote::class,
			"SELECT * FROM votes_<myname> WHERE `poll_id` = ? AND `author` = ?",
			$topic->id,
			$sender,
		);
		$timeleft = $topic->getTimeLeft();
		if (isset($vote) && $vote->answer && $timeleft > 0) {
			$privmsg = "You voted: <highlight>{$vote->answer}<end>.";
		} elseif ($timeleft > 0) {
			$privmsg = "You have not voted on this yet.";
		}

		$msg = $this->text->makeBlob("Poll Nr. {$topic->id}", $blob);
		if ($privmsg) {
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
		$oldVote = $this->db->fetch(
			Vote::class,
			"SELECT * FROM votes_<myname> WHERE `poll_id` = ? AND `author` = ?",
			$topic->id,
			$sender
		);
		if ($oldVote) {
			$this->db->exec(
				"UPDATE votes_<myname> ".
				"SET `answer` = ?, `time` = ? WHERE `author` = ? AND `poll_id` = ?",
				$answer,
				time(),
				$sender,
				$topic->id
			);
			$msg = "You have changed your vote to ".
				"<highlight>{$answer}<end> for \"{$topic->question}\".";
		} else {
			$this->db->exec(
				"INSERT INTO votes_<myname> ".
				"(`author`, `answer`, `time`, `poll_id`) ".
				"VALUES (?, ?, ?, ?)",
				$sender,
				$answer,
				time(),
				$topic->id
			);
			$msg = "You have voted <highlight>{$answer}<end> for \"{$topic->question}\".";
		}
		$sendto->reply($msg);
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

		$this->db->exec(
			"INSERT INTO polls_<myname> ".
			"(`question`, `author`, `possible_answers`, `started`, `duration`, `status`) ".
			"VALUES (?, ?, ?, ?, ?, ?)",
			$topic->question,
			$topic->author,
			$topic->possible_answers,
			$topic->started,
			$topic->duration,
			$topic->status
		);
		$topic->id = $this->db->lastInsertId();
		$this->polls[$topic->id] = $topic;
		$msg = "Voting topic <highlight>{$topic->id}<end> has been created.";

		$sendto->reply($msg);
	}
	
	public function getPollBlob(Poll $topic, ?string $sender=null) {
		/** @var Vote[] */
		$votes = $this->db->fetchAll(
			Vote::class,
			"SELECT * FROM votes_<myname> WHERE `poll_id` = ?",
			$topic->id
		);
		
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
}
