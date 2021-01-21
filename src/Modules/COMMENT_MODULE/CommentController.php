<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

use Exception;
use Nadybot\Core\{
	AccessManager,
	CommandAlias,
	CommandReply,
	DB,
	Nadybot,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\Modules\ALTS\AltsController;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'comment',
 *		accessLevel = 'member',
 *		description = 'read/write comments about players',
 *		help        = 'comment.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'commentcategories',
 *		accessLevel = 'mod',
 *		description = 'Manage comment categories',
 *		help        = 'comment-categories.txt'
 *	)
 */
class CommentController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	public const ADMIN="admin";

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "comments");
		$this->commandAlias->register($this->moduleName, "commentcategories", "comment categories");
		$this->commandAlias->register($this->moduleName, "commentcategories", "comment category");
		$this->commandAlias->register($this->moduleName, "comment", "comments");
		$this->settingManager->add(
			$this->moduleName,
			"comment_cooldown",
			"How long is the cooldown between leaving 2 comments for the same character",
			"edit",
			"time",
			"6h",
			"1s;1h;6h;24h",
			'',
			"mod"
		);
	}

	/** Read a single category by its name */
	public function getCategory(string $category): ?CommentCategory {
		return $this->db->fetch(
			CommentCategory::class,
			"SELECT * FROM `comment_categories_<myname>` WHERE `name` LIKE ?",
			$category
		);
	}

	/** Create a new category */
	public function saveCategory(CommentCategory $category): int {
		return $this->db->insert("comment_categories_<myname>", $category);
	}

	/**
	 * Delete a single category by its name
	 *
	 * @return int|null Number of deleted comments or null if the category didn't exist
	 */
	public function deleteCategory(string $category): ?int {
		$comments = $this->db->exec("DELETE FROM `comments_<myname>` WHERE `category` LIKE ?", $category);
		$deleted = $this->db->exec("DELETE FROM `comment_categories_<myname>` WHERE `name` LIKE ?", $category);
		return $deleted ? $comments : null;
	}

	/**
	 * Command to list all categories
	 *
	 * @HandlesCommand("commentcategories")
	 * @Matches("/^commentcategories$/i")
	 */
	public function listCategoriesCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var CommentCategory[] */
		$categories = $this->db->fetchAll(
			CommentCategory::class,
			"SELECT * FROM `comment_categories_<myname>`"
		);
		$blob = "";
		foreach ($categories as $category) {
			$blob .= "<pagebreak><header2>{$category->name}<end>\n".
				"<tab>Created: <highlight>" . $this->util->date($category->created_at) . "<end>\n".
				"<tab>Creator: <highlight>{$category->created_by}<end>\n".
				"<tab>Read Access: <highlight>".
				$this->accessManager->getDisplayName($category->min_al_read).
				"<end>\n".
				"<tab>Write Access: <highlight>".
				$this->accessManager->getDisplayName($category->min_al_write).
				"<end>\n\n";
		}
		$msg = $this->text->makeBlob("Comment categories (" . count($categories) . ")", $blob);
		$sendto->reply($msg);
	}

	/**
	 * Command to delete a category
	 *
	 * @HandlesCommand("commentcategories")
	 * @Matches("/^commentcategories (?:delete|del|rem|rm) (.+)$/i")
	 */
	public function deleteCategoryCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$category = $args[1];
		$cat = $this->getCategory($category);
		if (isset($cat)) {
			if ($cat->user_managed === false) {
				$sendto->reply("You cannot delete the built-in category <highlight>{$category}<end>.");
				return;
			}
			$senderAl = $this->accessManager->getAccessLevelForCharacter($sender);
			if ($this->accessManager->compareAccessLevels($senderAl, $cat->min_al_read) <0
				|| $this->accessManager->compareAccessLevels($senderAl, $cat->min_al_write) <0) {
				$sendto->reply(
					"You can only delete categories to which you have read and write access."
				);
				return;
			}
		} else {
			$sendto->reply("The comment category <highlight>{$category}<end> does not exist.");
			return;
		}
		$deleted = $this->deleteCategory($category);
		if ($deleted === null) {
			$sendto->reply("The comment category <highlight>{$category}<end> does not exist.");
			return;
		}
		$msg = "Successfully deleted the comment category <highlight>{$category}<end>";
		if ($deleted === 0) {
			$msg .= ".";
		} elseif ($deleted === 1) {
			$msg .= " and <highlight>1 comment<end> in that category.";
		} else {
			$msg .= " and <highlight>{$deleted} comments<end> in that category.";
		}
		$sendto->reply($msg);
	}

	/**
	 * Command to add a new category
	 *
	 * @HandlesCommand("commentcategories")
	 * @Matches("/^commentcategories\s+(?:add|create|new|edit|change)\s+(\w+)\s+(\w+)\s*$/i")
	 * @Matches("/^commentcategories\s+(?:add|create|new|edit|change)\s+(\w+)\s+(\w+)\s+(\w+)\s*$/i")
	 */
	public function addCategoryCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$category = $args[1];
		$alRead = $args[2];
		$alWrite = (count($args) > 3) ? $args[3] : $alRead;
		try {
			$alRead = $this->accessManager->getAccessLevel($alRead);
			$alWrite = $this->accessManager->getAccessLevel($alWrite);
		} catch (Exception $e) {
			$sendto->reply($e->getMessage());
			return;
		}
		$cat = $this->getCategory($category);
		if ($cat === null) {
			$cat = new CommentCategory();
			$cat->created_by = $sender;
			$cat->name = $category;
			$cat->min_al_read = $alRead;
			$cat->min_al_write = $alWrite;
			$this->saveCategory($cat);
			$sendto->reply("Category <highlight>{$category}<end> successfully created.");
			return;
		}
		$senderAl = $this->accessManager->getAccessLevelForCharacter($sender);
		if ($this->accessManager->compareAccessLevels($senderAl, $cat->min_al_read) <0
			|| $this->accessManager->compareAccessLevels($senderAl, $cat->min_al_write) <0) {
			$sendto->reply(
				"You can only change the required access levels of categories ".
				"to which you have read and write access."
			);
			return;
		}
		$cat->min_al_read = $alRead;
		$cat->min_al_write = $alWrite;
		$this->db->update("comment_categories_<myname>", "name", $cat);
		$sendto->reply("Access levels for category <highlight>{$category}<end> successfully changes.");
	}

	/**
	 * Command to add a new comment
	 *
	 * @HandlesCommand("comment")
	 * @Matches("/^comment\s+(?:add|create|new)\s+(\w+)\s+(\w+)\s+(.+)$/i")
	 */
	public function addCommentCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$character = ucfirst(strtolower($args[1]));
		$category = $args[2];
		$commentText = $args[3];

		$cat = $this->getCategory($category);
		if ($cat === null) {
			$sendto->reply("The category <highlight>{$category}<end> does not exist.");
			return;
		}
		if (!$this->chatBot->get_uid($character)) {
			$sendto->reply("No player named <highlight>{$character}<end> found.");
			return;
		}
		if (!$this->accessManager->checkAccess($sender, $cat->min_al_write)) {
			$sendto->reply(
				"You don't have the required access level to create comments of type ".
				"<highlight>{$category}<end>."
			);
			return;
		}
		if ($this->altsController->getAltInfo($sender)->main === $this->altsController->getAltInfo($character)->main) {
			$sendto->reply("You cannot comment on yourself.");
			return;
		}
		$comment = new Comment();
		$comment->category = $cat->name;
		$comment->character = $character;
		$comment->comment = trim($commentText);
		$comment->created_by = $sender;
		$cooldown = $this->saveComment($comment);
		if ($cooldown > 0) {
			$sendto->reply(
				"You have to wait <highlight>" . $this->util->unixtimeToReadable($cooldown) . "<end> ".
				"before posting another comment about <highlight>{$character}<end>."
			);
			return;
		}
		$sendto->reply("Comment about <highlight>{$character}<end> successfully saved.");
	}

	/**
	 * Calculate how many seconds to wait before posting another comment
	 * about the same character again.
	 */
	protected function getCommentCooldown(Comment $comment): int {
		if ($comment->created_by === $this->chatBot->vars["name"]) {
			return 0;
		}
		$cooldown = $this->settingManager->getInt("comment_cooldown");
		// Get all comments about that same character
		$comments = $this->getComments(null, $comment->character);
		// Only keep those that were created by the same person creating one now
		$ownComments = array_values(
			array_filter(
				$comments,
				function(Comment $com) use($comment): bool {
					return $com->created_by === $comment->created_by;
				}
			)
		);
		// They are sorted by time, so last element is the newest
		$lastComment = end($ownComments);
		if (!isset($lastComment)) {
			return 0;
		}
		// If the age of the last comment is less than the cooldown, return the remaining cooldown
		if (time() - $lastComment->created_at < $cooldown) {
			return $cooldown - time() + $lastComment->created_at;
		}
		return 0;
	}

	/**
	 * Save a comment and take the cooldown into consideration
	 * @return int 0 for success, otherwise the remaining time in seconds for posting
	 */
	public function saveComment(Comment $comment): int {
		$cooldown = $this->getCommentCooldown($comment);
		if ($cooldown > 0) {
			return $cooldown;
		}

		$this->db->insert("comments_<myname>", $comment);
		return 0;
	}

	/**
	 * Command to read comments about a player
	 *
	 * @HandlesCommand("comment")
	 * @Matches("/^comment\s+(?:get|search|find)\s+(\w+)$/i")
	 * @Matches("/^comment\s+(?:get|search|find)\s+(\w+)\s+(\w+)$/i")
	 */
	public function searchCommentCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$character = ucfirst(strtolower($args[1]));
		if (!$this->chatBot->get_uid($character)) {
			$sendto->reply("No player named <highlight>{$character}<end> found.");
			return;
		}

		$category = null;
		if (count($args) > 2) {
			$categoryName = $args[2];
			$category = $this->getCategory($categoryName);
			if ($category === null) {
				$sendto->reply("The category <highlight>{$categoryName}<end> does not exist.");
				return;
			}
			if (!$this->accessManager->checkAccess($sender, $category->min_al_read)) {
				$sendto->reply(
					"You don't have the required access level to read comments of type ".
					"<highlight>{$categoryName}<end>."
				);
				return;
			}
		}
		/** @var Comment[] */
		$comments = $this->getComments($category, $character);
		$comments = $this->filterInaccessibleComments($comments, $sender);
		if (!count($comments)) {
			$msg = "No comments found for <highlight>{$character}<end>".
			(isset($category) ? " in category <highlight>{$category->name}<end>." : ".");
			$sendto->reply($msg);
			return;
		}
		$formatted = $this->formatComments($comments, false, !isset($category));
		$msg = "Comments about {$character}".
			(isset($category) ? " in category {$category->name}" : "").
			" (" . count($comments) . ")";
		$msg = $this->text->makeBlob($msg, $formatted->blob);
		$sendto->reply($msg);
	}

	/**
	 * Remove all comments from $comments that $sender does not have permission to read
	 */
	public function filterInaccessibleComments(array $comments, string $sender): array {
		$accessCache = [];
		$senderAL = $this->accessManager->getAccessLevelForCharacter($sender);
		$readableComments = array_values(
			array_filter(
				$comments,
				function(Comment $comment) use (&$accessCache, $senderAL): bool {
					if (isset($accessCache[$comment->category])) {
						return $accessCache[$comment->category];
					}
					$cat = $this->getCategory($comment->category);
					$canRead = $this->accessManager->compareAccessLevels($senderAL, $cat->min_al_read) >= 0;
					return $accessCache[$comment->category] = $canRead;
				}
			)
		);
		return $readableComments;
	}

	/**
	 * Format the blob for a list of commnts
	 * @param Comment[] $comments
	 */
	public function formatComments(array $comments, bool $groupByMain, bool $addCategory=false): FormattedComments {
		$result = new FormattedComments();
		$result->numComments = count($comments);
		$chars = [];
		foreach ($comments as $comment) {
			$chars[$comment->character] ??= [];
			$chars[$comment->character] []= $comment;
		}
		$result->numChars = count($chars);
		if ($groupByMain) {
			$grouped = [];
			foreach ($chars as $char => $comments) {
				$main = $this->altsController->getAltInfo($char)->main;
				$grouped[$main] ??= [];
				$grouped[$main] = [...$grouped[$main], ...$comments];
			}
		} else {
			$grouped = $chars;
		}
		$result->numMains = count($grouped);
		$blob = "";
		foreach ($grouped as $main => $comments) {
			$blob .= "<pagebreak><header2>{$main}<end>\n";
			$blob .= "<tab>" . join(
				"\n<tab>",
				array_map(
					[$this, "formatComment"],
					$comments,
					array_fill(0, count($comments), $addCategory)
				)
			) . "\n\n";
		}
		$result->blob = $blob;
		return $result;
	}

	/** Format a single comment */
	public function formatComment(Comment $comment, bool $addCategory=false): string {
		$line = "{$comment->comment} (<highlight>{$comment->created_by}<end>, ".
			($addCategory ? "<highlight>{$comment->category}<end>, " : "").
			$this->util->date($comment->created_at) . ") [".
			$this->text->makeChatcmd("delete", "/tell <myname> comment del {$comment->id}").
			"]";
		return $line;
	}

	/**
	 * Command to delete a comment about a player
	 *
	 * @HandlesCommand("comment")
	 * @Matches("/^comment\s+(?:delete|del|rem|rm)\s+(\d+)$/i")
	 */
	public function deleteCommentCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];
		/** @var ?Comment */
		$comment = $this->db->fetch(Comment::class, "SELECT * FROM `comments_<myname>` WHERE `id`=?", $id);
		if (!isset($comment)) {
			$sendto->reply("The comment <highlight>#{$id}<end> does not exist.");
			return;
		}
		$cat = $this->getCategory($comment->category);
		if (!isset($cat)) {
			$sendto->reply("The category <highlight>{$comment->category}<end> does not exist.");
			return;
		}
		if ($this->accessManager->checkAccess($sender, $cat->min_al_write) < 0) {
			$sendto->reply("You don't have the necessary access level to delete this comment.");
			return;
		}
		$this->db->exec("DELETE FROM `comments_<myname>` WHERE `id`=?", $id);
		$sendto->reply("Comment deleted.");
	}

	/**
	 * Read all comments about a list of players or their alts/main, optionally limited to a category
	 *
	 * @return Comment[]
	 */
	public function getComments(?CommentCategory $category, string ...$characters): array {
		$sql = "SELECT * FROM `comments_<myname>` WHERE `character` IN";
		$params = [];
		foreach ($characters as $character) {
			$altInfo = $this->altsController->getAltInfo($character);
			$params = [...$params, $altInfo->main, ...$altInfo->getAllValidatedAlts()];
		}
		$sql .= "(" . join(",", array_fill(0, count($params), "?")) . ")";
		if (isset($category)) {
			$sql .= " AND `category`=?";
			$params []= $category->name;
		}
		$sql .= " ORDER BY `created_at` ASC";
		/** @var Comment[] */
		$comments = $this->db->fetchAll(Comment::class, $sql, ...$params);
		return $comments;
	}

	/**
	 * Read all comments about of a category
	 *
	 * @return Comment[]
	 */
	public function readCategoryComments(CommentCategory $category): array {
		$sql = "SELECT * FROM `comments_<myname>` WHERE `category` LIKE ? ORDER BY `created_at` ASC";
		$comments = $this->db->fetchAll(Comment::class, $sql, $category->name);
		return $comments;
	}
}
