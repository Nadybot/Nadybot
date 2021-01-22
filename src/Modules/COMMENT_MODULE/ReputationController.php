<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	LoggerWrapper,
	Nadybot,
	SettingManager,
	SQLException,
	Text,
	Timer,
	Util,
};

/**
 * @author Tyrence (RK2)
 * @author Nadyita (RK5)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'reputation',
 *		accessLevel = 'guild',
 *		description = 'Allows people to see and add reputation of other players',
 *		help        = 'reputation.txt'
 *	)
 */
class ReputationController {
	public const CAT_REPUTATION = "reputation";

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public CommentController $commentController;

	/** @Inject */
	public Timer $timer;

	/** @Logger */
	public LoggerWrapper $logger;

	/** @Setup */
	public function setup(): void {
		if ($this->db->tableExists("reputation")) {
			$this->logger->log("INFO", "Old reputation table found in database");
			$this->timer->callLater(0, [$this, "migrateReputationTable"]);
		}
	}

	/**
	 * Migrate the old "reputation" table into "comments_<myname>"
	 */
	public function migrateReputationTable(): void {
		if ($this->db->inTransaction()) {
			$this->timer->callLater(1, [$this, "migrateReputationTable"]);
			return;
		}
		$oldData = $this->db->query("SELECT * FROM `reputation`");
		if (count($oldData) > 0) {
			$this->logger->log(
				"INFO",
				"Converting " . count($oldData) . " DB entries from reputation to comments"
			);
			$cat = $this->getReputationCategory();
			$this->db->beginTransaction();
			try {
				foreach ($oldData as $row) {
					$comment = new Comment();
					$comment->category = $cat->name;
					$comment->character = $row->name;
					$comment->comment = "{$row->reputation} {$row->comment}";
					$comment->created_at = $row->dt;
					$comment->created_by = $row->by;
					$this->commentController->saveComment($comment);
				}
			} catch (SQLException $e) {
				$this->logger->log(
					"WARNING",
					"Error during the conversion of the reputation table: ".
					$e->getMessage() . " - rolling back"
				);
				$this->db->rollback();
				return;
			}
			$this->db->commit();
		}
		$this->logger->log(
			"INFO",
			"Conversion of reputation table finished successfully, removing old table"
		);
		$this->db->exec("DROP TABLE `reputation`");
	}

	public function getReputationCategory(): CommentCategory {
		$repCat = $this->commentController->getCategory(static::CAT_REPUTATION);
		if ($repCat !== null) {
			return $repCat;
		}
		$repCat = new CommentCategory();
		$repCat->name = static::CAT_REPUTATION;
		$repCat->created_by = $this->chatBot->vars["name"];
		$repCat->min_al_read = "guild";
		$repCat->min_al_write = "guild";
		$repCat->user_managed = false;
		$this->commentController->saveCategory($repCat);
		return $repCat;
	}

	/**
	 * @HandlesCommand("reputation")
	 * @Matches("/^reputation$/i")
	 */
	public function reputationListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$cat = $this->getReputationCategory();
		$comments = $this->commentController->readCategoryComments($cat);

		$count = count($comments);

		if ($count === 0) {
			$msg = "There are no characters on the reputation list.";
			$sendto->reply($msg);
			return;
		}

		$blob = '';
		/** @var array<string,stdClass> */
		$charReputation = [];
		foreach ($comments as $comment) {
			if (!array_key_exists($comment->character, $charReputation)) {
				$charReputation[$comment->character] = (object)['total' => 0, 'comments' => []];
			}
			$charReputation[$comment->character]->comments[] = $comment;
			$charReputation[$comment->character]->total += preg_match("/^\+1/", $comment->comment) ? 1 : -1;
		}
		$count = 0;
		foreach ($charReputation as $char => $charData) {
			$count++;
			$blob .= "<header2>$char<end>" . " (" . sprintf('%+d', $charData->total) . ")\n";
			$comments = array_slice($charData->comments, 0, 3);
			foreach ($comments as $comment) {
				$color = preg_match("/^\+1/", $comment->comment) ? 'green' : 'red';
				$blob .= "<tab><$color>{$comment->comment}<end> ".
					"(<highlight>{$comment->created_by}<end>, ".
					$this->util->date($comment->created_at) . ")\n";
			}
			if (count($charData->comments) > 3) {
				$details_link = $this->text->makeChatcmd('see all', "/tell <myname> reputation {$comment->character} all");
				$blob .= "  $details_link\n";
			}
			$blob .= "\n<pagebreak>";
		}
		$msg = $this->text->makeBlob("Reputation List ($count)", $blob);
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("reputation")
	 * @Matches("/^reputation ([a-z][a-z0-9-]+) (\+1|\-1) (.+)$/i")
	 */
	public function reputationAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$args = [$args[0], $args[1], $this->getReputationCategory()->name, "{$args[2]} {$args[3]}"];
		$this->commentController->addCommentCommand(...func_get_args());
	}

	/**
	 * @HandlesCommand("reputation")
	 * @Matches("/^reputation ([a-z][a-z0-9-]+) (all)$/i")
	 * @Matches("/^reputation ([a-z][a-z0-9-]+)$/i")
	 */
	public function reputationViewCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$name = ucfirst(strtolower($args[1]));
		$comments = $this->commentController->getComments($this->getReputationCategory(), $name);
		$numComments = count($comments);
		$numPositive = 0;
		$numNegative = 0;
		foreach ($comments as $comment) {
			if (preg_match("/^\+1/", $comment->comment)) {
				$numPositive++;
			} else {
				$numNegative++;
			}
		}

		$comments = array_slice($comments, 0, 1000);
		if (count($args) < 3) {
			$comments = array_slice($comments, 0, 10);
		}
		/** @var Comment[] $comments */

		if (!count($comments)) {
			$msg = "<highlight>{$name}<end> has no reputation.";
			$sendto->reply($msg);
			return;
		}

		$blob = "Positive reputation:  <green>{$numPositive}<end>\n";
		$blob .= "Negative reputation: <red>{$numNegative}<end>\n\n";
		if (count($args) < 3) {
			$blob .= "<header2>Last " . count($comments) . " comments about {$name}<end>\n";
		} else {
			$blob .= "<header>All comments about {$name}<end>\n";
		}

		foreach ($comments as $comment) {
			if (preg_match("/^\+1/", $comment->comment)) {
				$blob .= "<green>";
			} else {
				$blob .= "<red>";
			}

			$time = $this->util->unixtimeToReadable(time() - $comment->created_at, false);
			$blob .= "<tab>{$comment->comment}<end> (<highlight>{$comment->created_by}<end>, {$time} ago)\n";
		}

		if (count($args) < 3 && $numComments > count($comments)) {
			$blob .= "\n" . $this->text->makeChatcmd("Show all comments", "/tell <myname> reputation $name all");
		}

		$msg = $this->text->makeBlob("Reputation for {$name} (+$numPositive -$numNegative)", $blob);

		$sendto->reply($msg);
	}
}
