<?php

namespace Budabot\Modules\VOTE_MODULE;

use Budabot\Core\Event;
use stdClass;

/**
 * @author Lucier (RK1)
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'vote',
 *		accessLevel = 'all',
 *		description = 'View/participate in votes and polls',
 *		help        = 'vote.txt'
 *	)
 */
class VoteController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public $moduleName;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;
	
	/**
	 * @var \Budabot\Core\Budabot $chatBot
	 * @Inject
	 */
	public $chatBot;
	
	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;
	
	/**
	 * @var \Budabot\Core\SettingManager $settingManager
	 * @Inject
	 */
	public $settingManager;
	
	/**
	 * @var \Budabot\Core\AccessManager $accessManager
	 * @Inject
	 */
	public $accessManager;
	
	private $votes = array();
	private $delimiter = "|";
	private $table = "vote_<myname>";
	
	// status indicates the last alert that happened (not the next alert that will happen)
	const STATUS_CREATED = 0;
	const STATUS_STARTED = 1;
	const STATUS_60_MINUTES_LEFT = 2;
	const STATUS_15_MINUTES_LEFT = 3;
	const STATUS_60_SECONDS_LEFT = 4;
	const STATUS_ENDED = 9;
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup() {
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
		
		$data = $this->db->query("SELECT * FROM vote_<myname> WHERE `status` <> ? AND `duration` IS NOT NULL", self::STATUS_ENDED);
		foreach ($data as $row) {
			$this->votes[$row->question] = $row;
		}
	}
	
	/**
	 * This event handler checks for votes ending.
	 *
	 * @Event("timer(2sec)")
	 * @Description("Checks votes and periodically updates chat with time left")
	 */
	public function checkVote(Event $eventObj) {
		if (count($this->votes) == 0) {
			return;
		}

		foreach ($this->votes as $key => $row) {
			$author = $row->author;
			$question = $row->question;
			$started = $row->started;
			$duration = $row->duration;
			$answer = $row->answer;
			$status = $row->status;

			$timeleft = $started + $duration - time();

			if ($timeleft <= 0) {
				$title = "Finished Vote: $question";
				$this->db->exec("UPDATE $this->table SET `status` = ? WHERE `duration` = ? AND `question` = ?", self::STATUS_ENDED, $duration, $question);
				unset($this->votes[$key]);
			} elseif ($status == self::STATUS_CREATED) {
				$title = "Vote: $question";

				if ($timeleft > 3600) {
					$mstatus = self::STATUS_STARTED;
				} elseif ($timeleft > 900) {
					$mstatus = self::STATUS_60_MINUTES_LEFT;
				} elseif ($timeleft > 60) {
					$mstatus = self::STATUS_15_MINUTES_LEFT;
				} else {
					$mstatus = self::STATUS_60_SECONDS_LEFT;
				}
				$this->votes[$key]->status = $mstatus;
			} elseif ($timeleft <= 60 && $timeleft > 0 && $status != self::STATUS_60_SECONDS_LEFT) {
				$title = "60 seconds left: $question";
				$this->votes[$key]->status = self::STATUS_60_SECONDS_LEFT;
			} elseif ($timeleft <= 900 && $timeleft > 60 && $status != self::STATUS_15_MINUTES_LEFT) {
				$title = "15 minutes left: $question";
				$this->votes[$key]->status = self::STATUS_15_MINUTES_LEFT;
			} elseif ($timeleft <= 3600 && $timeleft > 900 && $status != self::STATUS_60_MINUTES_LEFT) {
				$title = "60 minutes left: $question";
				$this->votes[$key]->status = self::STATUS_60_MINUTES_LEFT;
			} else {
				$title = "";
			}

			if ($title != "") { // Send current results to guest + org chat.
				$blob = $this->getVoteBlob($question);

				$msg = $this->text->makeBlob($title, $blob);

				if ($this->settingManager->get("vote_channel_spam") == 0 || $this->settingManager->get("vote_channel_spam") == 2) {
					$this->chatBot->sendGuild($msg, true);
				}
				if ($this->settingManager->get("vote_channel_spam") == 1 || $this->settingManager->get("vote_channel_spam") == 2) {
					$this->chatBot->sendPrivate($msg, true);
				}
			}
		}
	}

	/**
	 * This command handler shows votes.
	 *
	 * @HandlesCommand("vote")
	 * @Matches("/^vote$/i")
	 */
	public function voteCommand($message, $channel, $sender, $sendto, $args) {
		$data = $this->db->query("SELECT * FROM $this->table WHERE `duration` IS NOT NULL ORDER BY `started`");
		$running = "";
		$over = "";
		$blob = "";
		if (count($data) > 0) {
			foreach ($data as $row) {
				$question = $row->question;
				$started = $row->started;
				$duration = $row->duration;
				$line = "<tab>" . $this->text->makeChatcmd($question, "/tell <myname> vote show $question");

				$timeleft = $started + $duration - time();
				if ($timeleft>0) {
					$running .= $line . "\n(" . $this->util->unixtimeToReadable($timeleft) . " left)\n";
				} else {
					$over .= $line . "\n";
				}
			}
			if ($running) {
				$blob .= " <green>Running:<end>\n" . $running;
			}
			if ($running && $over) {
				$blob .= "\n";
			}
			if ($over) {
				$blob .= " <red>Finshed:<end>\n" . $over;
			}

			$msg = $this->text->makeBlob("Vote Listing", $blob);
		} else {
			$msg = "There are currently no votes to view.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * This command handler deletes votes.
	 *
	 * @HandlesCommand("vote")
	 * @Matches("/^vote kill (.+)$/i")
	 */
	public function voteKillCommand($message, $channel, $sender, $sendto, $args) {
		$question = $args[1];
		if ($this->accessManager->checkAccess($sender, "moderator")) {
			$row = $this->db->queryRow("SELECT * FROM $this->table WHERE `question` = ?", $question);
		} else {
			$row = $this->db->queryRow("SELECT * FROM $this->table WHERE `question` = ? AND `author` = ? AND `duration` IS NOT NULL", $question, $sender);
		}

		if ($row !== null) {
			$this->db->exec("DELETE FROM $this->table WHERE `question` = ?", $row->question);
			unset($this->votes[$row->question]);
			$msg = "'$row->question' has been removed.";
		} else {
			$msg = "Either this vote does not exist, or you did not create it.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * This command handler removes someones vote from a running vote.
	 *
	 * @HandlesCommand("vote")
	 * @Matches("/^vote remove (.+)$/i")
	 */
	public function voteRemoveCommand($message, $channel, $sender, $sendto, $args) {
		$question = $args[1];
		if (!isset($this->votes[$question])) {
			$msg = "Vote <highlight>$question<end> could not be found.";
		} else {
			$data = $this->db->query("SELECT * FROM $this->table WHERE `question` = ? AND `author` = ? AND `duration` IS NULL", $question, $sender);
			if (count($data) > 0) {
				// this needs to be fixed, should not remove the entire vote
				$this->db->exec("DELETE FROM $this->table WHERE `question` = ? AND `author` = ? AND `duration` IS NULL", $question, $sender);
				$msg = "Your vote has been removed.";
			} else {
				$msg = "You have not voted on this topic.";
			}
		}
		$sendto->reply($msg);
	}
	
	/**
	 * This command handler ends a running vote.
	 *
	 * @HandlesCommand("vote")
	 * @Matches("/^vote end (.*)$/i")
	 */
	public function voteEndCommand($message, $channel, $sender, $sendto, $args) {
		$question = $args[1];
		$row = $this->db->queryRow("SELECT * FROM $this->table WHERE `question` = ? AND `author` = ? AND `duration` IS NOT NULL", $question, $sender);

		if ($row === null) {
			$msg = "Either this vote does not exist, or you did not create it.";
			$sendto->reply($msg);
			return;
		}
		$started = $row->started;
		$duration = $row->duration;
		$timeleft = $started + $duration - time();

		if ($timeleft > 60) {
			$duration = (time() - $started) + 61;
			$this->db->exec(
				"UPDATE {$this->table} SET `duration` = ? WHERE `question` = ? AND duration IS NOT NULL",
				$duration,
				$question
			);
			$this->votes[$question]->duration = $duration;
			$msg = "Vote duration reduced to 60 seconds.";
		} elseif ($timeleft <= 0) {
			$msg = "This vote has already finished.";
		} else {
			$msg = "There is only <highlight>$timeleft<end> seconds left.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("vote")
	 * @Matches("/^vote show (.*)$/i")
	 */
	public function voteShowCommand($message, $channel, $sender, $sendto, $args) {
		$question = $args[1];
		
		$blob = $this->getVoteBlob($question, $sender);
	
		$row = $this->db->queryRow(
			"SELECT * FROM $this->table WHERE ".
			"`author` = ? AND `question` = ? AND `duration` IS NULL",
			$sender,
			$question
		);
		$timeleft = $row->started + $row->duration - time();
		if ($row->answer && $timeleft > 0) {
			$privmsg = "You voted: <highlight>(".$row->answer.")<end>.";
		} elseif ($timeleft > 0) {
			$privmsg = "You have not voted on this.";
		}

		$msg = $this->text->makeBlob("Vote: $question", $blob);
		if ($privmsg) {
			$sendto->reply($privmsg);
		}
		
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("vote")
	 * @Matches("/^vote choose (.+)$/i")
	 */
	public function voteChooseCommand($message, $channel, $sender, $sendto, $args) {
		list($question, $choice) = explode($this->delimiter, $args[1], 2);
		
		$row = $this->db->queryRow("SELECT * FROM $this->table WHERE `question` = ? AND `duration` IS NOT NULL", $question);
		$question = $row->question;
		$author = $row->author;
		$started = $row->started;
		$duration = $row->duration;
		$status = $row->status;
		$answer = $row->answer;
		$timeleft = $started + $duration - time();

		if (!$duration) {
			$msg = "Could not find any votes with this topic.";
		} elseif ($timeleft <= 0) {
			$msg = "No longer accepting votes for this topic.";
		} else {
			$data = $this->db->query("SELECT * FROM $this->table WHERE `question` = ? AND `duration` IS NULL AND `author` = ?", $question, $sender);
			if (count($data) > 0) {
				$this->db->exec("UPDATE $this->table SET `answer` = ? WHERE `author` = ? AND `duration` IS NULL AND `question` = ?", $choice, $sender, $question);
				$msg = "You have altered your choice to <highlight>$choice<end> for: $question.";
			} else {
				$this->db->exec("INSERT INTO $this->table (`author`, `answer`, `question`) VALUES (?, ?, ?)", $sender, $choice, $question);
				$msg = "You have selected choice <highlight>$choice<end> for: $question.";
			}
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("vote")
	 * @Matches("/^vote add (.+)$/i")
	 */
	public function voteAddCommand($message, $channel, $sender, $sendto, $args) {
		list($settime, $question, $answers) = explode($this->delimiter, $args[1], 3);

		// !vote 16m|Does this module work?|yes|no

		$newtime = $this->util->parseTime($settime);

		if ($newtime == 0) {
			$msg = "Invalid time entered. Time format should be: 1d2h3m4s";
		} else {
			$answer = explode($this->delimiter, $answers);
			if (count($answer) < 2) {
				$msg = "You must have at least two options for this vote topic.";
			} elseif (!$question) {
				$msg = "You must specify a question for your new vote topic.";
			} else {
				$status = self::STATUS_CREATED;
				$data = $this->db->query("SELECT * FROM $this->table WHERE `question` = ?", $question);
				if (count($data) == 0) {
					$this->db->exec(
						"INSERT INTO $this->table ".
						"(`question`, `author`, `started`, `duration`, `answer`, `status`) ".
						"VALUES ".
						"(?, ?, ?, ?, ?, ?)",
						$question,
						$sender,
						time(),
						$newtime,
						$answers,
						$status
					);
					$obj = new stdClass;
					$obj->question = $question;
					$obj->author = $sender;
					$obj->started = time();
					$obj->duration = $newtime;
					$obj->answer = $answers;
					$obj->status = $status;
					$this->votes[$question] = $obj;
					$msg = "Vote has been added.";
				} else {
					$msg = "There is already a vote topic with this question.";
				}
			}
		}

		$sendto->reply($msg);
	}
	
	public function getVoteBlob($question, $sender=null) {
		$data = $this->db->query("SELECT * FROM $this->table WHERE `question` = ?", $question);
		if (count($data) == 0) {
			return "Could not find any votes with this topic.";
		}
		
		$results = array();
		$totalresults = 0;
		foreach ($data as $row) {
			if ($row->duration) {
				$question = $row->question;
				$author = $row->author;
				$started = $row->started;
				$duration = $row->duration;
				$timeleft = $started + $duration - time();
			}
			$answer = $row->answer;

			if (strpos($answer, $this->delimiter) === false) { // A Vote: $answer = "yes";
				$results[$answer]++;
				$totalresults++;
			} else {
				// Main topic: $answer = "yes;no";
				$ans = explode($this->delimiter, $answer);
				foreach ($ans as $value) {
					if (!isset($results[$value])) {
						$results[$value] = 0;
					}
				}
			}
		}

		$blob = "$author's Vote: <highlight>".$question."<end>\n";
		if ($timeleft > 0) {
			$blob .= $this->util->unixtimeToReadable($timeleft)." till this vote closes!\n\n";
		} else {
			$blob .= "<red>This vote has ended " . $this->util->unixtimeToReadable($timeleft * -1, 1) . " ago.<end>\n\n";
		}

		foreach ($results as $key => $value) {
			if ($totalresults == 0) {
				$val = 0;
			} else {
				$val = number_format(100 * ($value / $totalresults), 0);
			}
			$blob .= $this->text->alignNumber($val, 3) . "% ";

			if ($timeleft > 0) {
				$blob .= $this->text->makeChatcmd($key, "/tell <myname> vote choose $question{$this->delimiter}$key") . " (Votes: $value)\n";
			} else {
				$blob .= "<highlight>$key<end> (Votes: $value)\n";
			}
		}

		if ($timeleft > 0) { // Want this option avaiable for everyone if its run from org/priv chat.
			$blob .= "\n" . $this->text->makeChatcmd('Remove yourself from this vote', "/tell <myname> vote remove $question") . "\n";
		}

		$blob .="\nDon't like these choices?  Add your own:\n<tab>/tell <myname> vote $question{$this->delimiter}<highlight>your choice<end>\n";

		if ($sender === null) {
			$blob .="\nIf you started this vote, you can:\n";
		} elseif ($sender === $author) {
			$blob .="\nAs the creator of this vote, you can:\n";
		}
		if ($sender === null || $sender === $author) {
			$blob .="<tab>" . $this->text->makeChatcmd('Kill the vote completely', "/tell <myname> vote kill $question") . "\n";
			if ($timeleft > 0) {
				$blob .="<tab>" . $this->text->makeChatcmd('End the vote early', "/tell <myname> vote end $question");
			}
		}

		return $blob;
	}
}
