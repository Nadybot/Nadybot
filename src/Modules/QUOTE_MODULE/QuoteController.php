<?php declare(strict_types=1);

namespace Nadybot\Modules\QUOTE_MODULE;

use Nadybot\Core\{
	AccessManager,
	CommandReply,
	DB,
	Nadybot,
	Text,
	Util,
};

/**
 * @author Lucier (RK1)
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'quote',
 *		accessLevel = 'all',
 *		description = 'Add/Remove/View Quotes',
 *		help        = 'quote.txt'
 *	)
 */
class QuoteController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public AccessManager $accessManager;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;

	/** @Inject */
	public Nadybot $chatBot;
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "quote");
		if ($this->db->getType() === $this->db::MYSQL) {
			$this->db->exec("ALTER TABLE `quote` CHANGE `id` `id` INTEGER AUTO_INCREMENT");
		}
	}

	/**
	 * @HandlesCommand("quote")
	 * @Matches("/^quote add (.+)$/si")
	 */
	public function quoteAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$quoteMsg = trim($args[1]);
		$row = $this->db->queryRow("SELECT * FROM `quote` WHERE `msg` LIKE ?", $quoteMsg);
		if ($row !== null) {
			$msg = "This quote has already been added as quote <highlight>$row->id<end>.";
			$sendto->reply($msg);
			return;
		}
		if (strlen($quoteMsg) > 1000) {
			$msg = "This quote is too long.";
			$sendto->reply($msg);
			return;
		}
		$poster = $sender;

		$this->db->exec(
			"INSERT INTO `quote` (`poster`, `dt`, `msg`) ".
			"VALUES (?, ?, ?)",
			$poster,
			time(),
			$quoteMsg
		);
		$id = $this->db->lastInsertId();
		$msg = "Quote <highlight>$id<end> has been added.";
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("quote")
	 * @Matches("/^quote (rem|del|remove|delete) (\d+)$/i")
	 */
	public function quoteRemoveCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[2];
		/** @var ?Quote */
		$row = $this->db->fetch(
			Quote::class,
			"SELECT * FROM `quote` WHERE `id` = ?",
			$id
		);

		if ($row === null) {
			$msg = "Could not find this quote. Already deleted?";
			$sendto->reply($msg);
			return;
		}
		$poster = $row->poster;

		//only author or admin can delete.
		if (($poster === $sender)
			|| $this->accessManager->checkAccess($sender, 'moderator')
		) {
			$this->db->exec("DELETE FROM `quote` WHERE `id` = ?", $id);
			$msg = "This quote has been deleted.";
		} else {
			$msg = "Only a moderator or $poster can delete this quote.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("quote")
	 * @Matches("/^quote search (.+)$/i")
	 */
	public function quoteSearchCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = $args[1];
		$searchParam = '%' . $search . '%';

		// Search for poster:
		/** @var Quote[] */
		$quotes = $this->db->fetchAll(
			Quote::class,
			"SELECT * FROM `quote` WHERE `poster` LIKE ?",
			$searchParam
		);
		$idList = [];
		foreach ($quotes as $quote) {
			$idList []= $this->text->makeChatcmd((string)$quote->id, "/tell <myname> quote $quote->id");
		}
		if (count($idList)) {
			$msg  = "<header2>Quotes posted by \"$search\"<end>\n";
			$msg .= "<tab>" . join(", ", $idList) . "\n\n";
		}

		// Search inside quotes:
		/** @var Quote[] */
		$quotes = $this->db->fetchAll(
			Quote::class,
			"SELECT * FROM `quote` WHERE `msg` LIKE ?",
			$searchParam
		);
		$idList = [];
		foreach ($quotes as $quote) {
			$idList []= $this->text->makeChatcmd((string)$quote->id, "/tell <myname> quote $quote->id");
		}
		if (count($idList)) {
			$msg .= "<header2>Quotes that contain \"$search\"<end>\n";
			$msg .= "<tab>" . join(", ", $idList);
		}

		if ($msg) {
			$msg = $this->text->makeBlob("Results for: '$search'", $msg);
		} else {
			$msg = "Could not find any matches for this search.";
		}
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("quote")
	 * @Matches("/^quote (\d+)$/i")
	 * @Matches("/^quote (org|priv) (\d+)$/i")
	 */
	public function quoteShowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)($args[2] ?? $args[1]);
		
		$result = $this->getQuoteInfo($id);
		
		if ($result === null) {
			$msg = "No quote found with ID <highlight>$id<end>.";
			$sendto->reply($msg);
			return;
		}
		$msg = $result;
		if (count($args) === 2) {
			$sendto->reply($msg);
			return;
		}
		if ($args[1] === "priv") {
			$this->chatBot->sendPrivate($msg);
		} elseif ($args[1] === "org") {
			$this->chatBot->sendGuild($msg);
		}
	}
	
	/**
	 * @HandlesCommand("quote")
	 * @Matches("/^quote$/i")
	 */
	public function quoteShowRandomCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		// choose a random quote to show
		$result = $this->getQuoteInfo(null);
		
		if ($result === null) {
			$msg = "There are no quotes to show.";
		} else {
			$msg = $result;
		}
		$sendto->reply($msg);
	}
	
	public function getMaxId(): int {
		$row = $this->db->queryRow("SELECT COALESCE(MAX(id), 0) AS max_id FROM `quote`");
		return $row->max_id;
	}

	public function getQuoteInfo(int $id=null) {
		$count = $this->getMaxId();

		if ($count === 0) {
			return null;
		}

		if ($id === null) {
			if ($this->db->getType() === $this->db::SQLITE) {
				$row = $this->db->fetch(Quote::class, "SELECT * FROM `quote` ORDER BY RANDOM() LIMIT 1");
			} else {
				$row = $this->db->fetch(Quote::class, "SELECT * FROM `quote` ORDER BY RAND() LIMIT 1");
			}
		} else {
			$row = $this->db->fetch(Quote::class, "SELECT * FROM `quote` WHERE `id` = ?", $id);
		}
		/** @var ?Quote $row */
		if ($row === null) {
			return null;
		}

		$poster = $row->poster;
		$quoteMsg = $row->msg;

		$msg = "ID: <highlight>$row->id<end> of $count\n";
		$msg .= "Poster: <highlight>$poster<end>\n";
		$msg .= "Date: <highlight>" . $this->util->date($row->dt) . "<end>\n";
		$msg .= "Quote: <highlight>$quoteMsg<end>\n";
		$msg .= "Action:";
		if (!empty($this->chatBot->vars["my_guild"])) {
			$msg .= " [".
				$this->text->makeChatcmd("To orgchat", "/tell <myname> quote org {$row->id}").
			"]";
		}
		$msg .= " [".
			$this->text->makeChatcmd("To Privchat", "/tell <myname> quote priv {$row->id}").
		"]\n\n";

		$msg .= "<header2>Quotes posted by \"$poster\"<end>\n";
		/** @var Quote[] */
		$data = $this->db->fetchAll(Quote::class, "SELECT * FROM `quote` WHERE `poster` = ?", $poster);
		$idList = [];
		foreach ($data as $row) {
			$idList []= $this->text->makeChatcmd((string)$row->id, "/tell <myname> quote $row->id");
		}
		$msg .= "<tab>" . join(", ", $idList);

		return $this->text->makeBlob("Quote", $msg).': "'.$quoteMsg.'"';
	}
}
