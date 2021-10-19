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
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
	}

	/**
	 * @HandlesCommand("quote")
	 * @Matches("/^quote add (.+)$/si")
	 */
	public function quoteAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$quoteMsg = trim($args[1]);
		$row = $this->db->table("quote")
			->whereIlike("msg", $quoteMsg)
			->asObj()->first();
		if (isset($row)) {
			$msg = "This quote has already been added as quote <highlight>{$row->id}<end>.";
			$sendto->reply($msg);
			return;
		}
		if (strlen($quoteMsg) > 1000) {
			$msg = "This quote is too long.";
			$sendto->reply($msg);
			return;
		}
		$poster = $sender;

		$id = $this->db->table("quote")
			->insertGetId([
				"poster" => $poster,
				"dt" => time(),
				"msg" => $quoteMsg,
			]);
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
		$row = $this->db->table("quote")
			->where("id", $id)
			->asObj(Quote::class)->first();

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
			$this->db->table("quote")->delete($id);
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
		$idList = $this->db->table("quote")
			->whereIlike("poster", $searchParam)
			->asObj(Quote::class)
			->map(function (Quote $quote): string {
				return $this->text->makeChatcmd(
					(string)$quote->id,
					"/tell <myname> quote $quote->id"
				);
			})->toArray();
		if (count($idList)) {
			$msg  = "<header2>Quotes posted by \"$search\"<end>\n";
			$msg .= "<tab>" . join(", ", $idList) . "\n\n";
		}

		// Search inside quotes:
		$idList = $this->db->table("quote")
			->whereIlike("msg", $searchParam)
			->asObj(Quote::class)
			->map(function (Quote $quote): string {
				return $this->text->makeChatcmd(
					(string)$quote->id,
					"/tell <myname> quote $quote->id"
				);
			})->toArray();
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
		return (int)($this->db->table("quote")->max("id") ?? 0);
	}

	public function getQuoteInfo(int $id=null) {
		$count = $this->getMaxId();

		if ($count === 0) {
			return null;
		}

		if ($id === null) {
			$row = $this->db->table("quote")
				->inRandomOrder()
				->limit(1)
				->asObj(Quote::class)->first();
		} else {
			$row = $this->db->table("quote")
				->where("id", $id)
				->asObj(Quote::class)->first();
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
		$idList = $this->db->table("quote")
			->where("poster", $poster)
			->asObj(Quote::class)
			->map(function (Quote $row) {
				return $this->text->makeChatcmd(
					(string)$row->id,
					"/tell <myname> quote {$row->id}"
				);
			});
		$msg .= "<tab>" . $idList->join(", ");

		return $this->text->makeBlob("Quote", $msg).': "'.$quoteMsg.'"';
	}

	/**
	 * @NewsTile("quote")
	 * @Description("Displays a random quote from your quote database")
	 */
	public function quoteTile(string $sender, callable $callback): void {
		/** @var ?Quote */
		$row = $this->db->table("quote")
			->inRandomOrder()
			->limit(1)
			->asObj(Quote::class)->first();
		if (!isset($row)) {
			$callback(null);
			return;
		}
		$result = [];
		$lines = preg_split("/ (?=(?:\(\d{2}:\d{2}\) )?\[[a-zA-Z 0-9-]+\])/", $row->msg);
		foreach ($lines as $line) {
			$result = [...$result, ...explode("\n", $line)];
		}
		$quote = join("\n» ", $result);
		$msg = "» {$quote}";
		$callback($msg);
	}
}
