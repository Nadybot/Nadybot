<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	LoggerWrapper,
	ModuleInstance,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PWord,
	SettingManager,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "reputation",
		accessLevel: "guild",
		description: "Allows people to see and add reputation of other players",
	)
]
class ReputationController extends ModuleInstance {
	public const CAT_REPUTATION = "reputation";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public CommentController $commentController;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public function getReputationCategory(): CommentCategory {
		$repCat = $this->commentController->getCategory(static::CAT_REPUTATION);
		if ($repCat !== null) {
			return $repCat;
		}
		$repCat = new CommentCategory();
		$repCat->name = static::CAT_REPUTATION;
		$repCat->created_by = $this->config->main->character;
		$repCat->min_al_read = "guild";
		$repCat->min_al_write = "guild";
		$repCat->user_managed = false;
		$this->commentController->saveCategory($repCat);
		return $repCat;
	}

	/** See a list of characters that have reputation */
	#[NCA\HandlesCommand("reputation")]
	public function reputationListCommand(CmdContext $context): void {
		$cat = $this->getReputationCategory();
		$comments = $this->commentController->readCategoryComments($cat);

		$count = count($comments);

		if ($count === 0) {
			$msg = "There are no characters on the reputation list.";
			$context->reply($msg);
			return;
		}

		$blob = '';

		/** @var array<string,\stdClass> */
		$charReputation = [];
		foreach ($comments as $comment) {
			if (!array_key_exists($comment->character, $charReputation)) {
				$charReputation[$comment->character] = (object)['total' => 0, 'comments' => []];
			}
			$charReputation[$comment->character]->comments []= $comment;
			$charReputation[$comment->character]->total += preg_match("/^\+1/", $comment->comment) ? 1 : -1;
		}
		$count = 0;
		$blobs = [];
		foreach ($charReputation as $char => $charData) {
			$count++;
			$blob = "<pagebreak><header2>{$char}<end>" . " (" . sprintf('%+d', $charData->total) . ")";
			$comments = array_slice($charData->comments, 0, 3);
			foreach ($comments as $comment) {
				$color = preg_match("/^\+1/", $comment->comment) ? 'green' : 'red';
				$blob .= "\n<tab><{$color}>{$comment->comment}<end> ".
					"(<highlight>{$comment->created_by}<end>, ".
					$this->util->date($comment->created_at) . ")";
			}
			if (count($charData->comments) > 3 && count($comments) > 0) {
				$details_link = $this->text->makeChatcmd('see all', "/tell <myname> reputation {$comments[0]->character} all");
				$blob .= "\n<tab>[{$details_link}]";
			}
			$blobs []= $blob;
		}
		$msg = $this->text->makeBlob("Reputation List ({$count})", join("\n\n", $blobs));
		$context->reply($msg);
	}

	/** Add positive or negative reputation to a character */
	#[NCA\HandlesCommand("reputation")]
	public function reputationAddCommand(
		CmdContext $context,
		PCharacter $char,
		#[NCA\StrChoice("+1", "-1")]
		string $action,
		string $comment
	): void {
		$this->commentController->addCommentCommand(
			$context,
			"add",
			$char,
			new PWord($this->getReputationCategory()->name),
			"{$action} {$comment}"
		);
	}

	/**
	 * See the reputation for a character
	 * If 'all' is given, print more than just the last 10 entries
	 */
	#[NCA\HandlesCommand("reputation")]
	public function reputationViewCommand(
		CmdContext $context,
		PCharacter $char,
		#[NCA\Str("all")]
		?string $all
	): void {
		$name = $char();
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
		if (!isset($all)) {
			$comments = array_slice($comments, 0, 10);
		}

		/** @var Comment[] $comments */

		if (!count($comments)) {
			$msg = "<highlight>{$name}<end> has no reputation.";
			$context->reply($msg);
			return;
		}

		$blob = "Positive reputation:  <green>{$numPositive}<end>\n";
		$blob .= "Negative reputation: <red>{$numNegative}<end>\n\n";
		if (!isset($all)) {
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

		if (!isset($all) && $numComments > count($comments)) {
			$blob .= "\n" . $this->text->makeChatcmd("Show all comments", "/tell <myname> reputation {$name} all");
		}

		$msg = $this->text->makeBlob("Reputation for {$name} (+{$numPositive} -{$numNegative})", $blob);

		$context->reply($msg);
	}
}
