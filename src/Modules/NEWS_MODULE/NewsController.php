<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	CommandReply,
	DB,
	Event,
	Nadybot,
	SettingManager,
	Text,
	Util,
};
use Nadybot\Core\Modules\ALTS\AltsController;
use Nadybot\Modules\WEBSERVER_MODULE\{
	ApiResponse,
	HttpProtocolWrapper,
	JsonImporter,
	Request,
	Response,
};
use Throwable;

/**
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'news',
 *		accessLevel = 'member',
 *		description = 'Shows news',
 *		help        = 'news.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'news confirm .+',
 *		accessLevel = 'member',
 *		description = 'Mark news as read',
 *		help        = 'news.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'news .+',
 *		accessLevel = 'mod',
 *		description = 'Adds, removes, pins or unpins a news entry',
 *		help        = 'news.txt'
 *	)
 */
class NewsController {
	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	public string $moduleName;

	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");

		$this->settingManager->add(
			$this->moduleName,
			"num_news_shown",
			"Maximum number of news items shown",
			"edit",
			"number",
			"10",
			"5;10;15;20"
		);
		$this->settingManager->add(
			$this->moduleName,
			"news_announcement_layout",
			"Layout of the news announcement",
			"edit",
			"options",
			"1",
			"Last date;Latest news",
			"1;2"
		);
		$this->settingManager->add(
			$this->moduleName,
			"news_confirmed_for_all_alts",
			"Confirmed news count for all alts",
			"edit",
			"options",
			"1",
			"true;false",
			"1;0"
		);
	}

	/**
	 * @return Collection<INews>
	 */
	public function getNewsItems(string $player): Collection {
		if ($this->settingManager->getBool('news_confirmed_for_all_alts')) {
			$player = $this->altsController->getAltInfo($player)->main;
		}
		$query = $this->db->table("news AS n")
			->where("deleted", 0)
			->orderByDesc("sticky")
			->orderByDesc("time")
			->limit($this->settingManager->getInt('num_news_shown'))
			->select("n.*")
			->selectSub(
				$this->db->table("news_confirmed AS c")
					->whereColumn("c.id", "n.id")
					->where("c.player", $player)
					->selectRaw("COUNT(*) > 0"),
				"confirmed"
			);
		return $query->asObj(INews::class);
	}

	/**
	 * @return string|string[]|null
	 */
	public function getNews(string $player, bool $onlyUnread=true) {
		$news = $this->getNewsItems($player);
		if ($onlyUnread) {
			$news = $news->where("confirmed", false);
		}
		if ($news->count() === 0) {
			return null;
		}
		$latestNews = null;
		$msg = '';
		$blob = '';
		$sticky = "";
		foreach ($news as $item) {
			if ($latestNews === null || $item->time > $latestNews->time) {
				$latestNews = $item;
			}
			if ($sticky !== '') {
				if ($sticky !== $item->sticky) {
					$blob .= "_____________________________________________\n\n";
				} else {
					$blob .= "\n";
				}
			}

			if ($item->sticky) {
				$blob .= "<img src=tdb://id:GFX_GUI_PINBUTTON_PRESSED> ";
			}
			$blob .= ($item->confirmed ? "<grey>" : "<highlight>").
				"{$item->news}<end>\n";
			$blob .= "By {$item->name} " . $this->util->date($item->time) . " ";
			$blob .= $this->text->makeChatcmd("Remove", "/tell <myname> news rem $item->id") . " ";
			if ($item->sticky) {
				$blob .= $this->text->makeChatcmd("Unpin", "/tell <myname> news unpin $item->id") . " ";
			} else {
				$blob .= $this->text->makeChatcmd("Pin", "/tell <myname> news pin $item->id") . " ";
			}
			if (!$item->confirmed) {
				$blob .= $this->text->makeChatcmd("Confirm", "/tell <myname> news confirm $item->id") . " ";
			}
			$blob .= "\n";
			$sticky = $item->sticky;
		}

		/** @var ?News */
		$item = $this->db->table("news")
			->where("deleted", 0)
			->orderByDesc("time")
			->limit(1)
			->asObj(News::class)
			->first();
		$layout = $this->settingManager->getInt('news_announcement_layout');
		if ($layout === 1) {
			$msg = $this->text->makeBlob(
				"News [Last updated at " . $this->util->date($item->time) . "]",
				$blob
			);
		} elseif ($layout === 2) {
			$msg = "<yellow>NEWS:<end> <highlight>{$latestNews->news}<end>\nBy {$latestNews->name} (".
				$this->util->date($latestNews->time) . ") ".
				$this->text->makeBlob("more", $blob, "News");
		}
		return $msg;
	}

	/**
	 * @Event("logOn")
	 * @Description("Sends news to org members logging in")
	 */
	public function logonEvent(Event $eventObj): void {
		$sender = $eventObj->sender;

		if (!$this->chatBot->isReady() || !isset($this->chatBot->guildmembers[$sender])) {
			return;
		}
		if ($this->hasRecentNews($sender)) {
			$this->chatBot->sendMassTell($this->getNews($sender, true), $sender);
		}
	}

	/**
	 * @Event("joinPriv")
	 * @Description("Sends news to players joining private channel")
	 */
	public function privateChannelJoinEvent(Event $eventObj): void {
		if ($this->hasRecentNews($eventObj->sender)) {
			$this->chatBot->sendMassTell($this->getNews($eventObj->sender, true), $eventObj->sender);
		}
	}

	/**
	 * Check if there are recent news for player $player
	 */
	public function hasRecentNews(string $player): bool {
		$thirtyDays = time() - (86400 * 30);
		$news = $this->getNewsItems($player);
		return $news->where("confirmed", false)
			->contains("time", ">", $thirtyDays);
	}

	/**
	 * This command handler shows latest news.
	 *
	 * @HandlesCommand("news")
	 * @Matches("/^news$/i")
	 */
	public function newsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$msg = $this->getNews($sender, false);

		$sendto->reply($msg ?? "No News recorded yet.");
	}

	/**
	 * This command handler confirms a news entry.
	 *
	 * @HandlesCommand("news confirm .+")
	 * @Matches("/^news confirm (\d+)$/i")
	 */
	public function newsconfirmCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];

		$row = $this->getNewsItem($id);
		if ($row === null) {
			$msg = "No news entry found with the ID <highlight>{$id}<end>.";
			$sendto->reply($msg);
			return;
		}
		if ($this->settingManager->getBool('news_confirmed_for_all_alts')) {
			$sender = $this->altsController->getAltInfo($sender)->main;
		}

		if ($this->db->table("news_confirmed")
			->where("id", $row->id)
			->where("player", $sender)
			->exists()
		) {
			$msg = "You've already confirmed these news.";
			$sendto->reply($msg);
			return;
		}
		$this->db->table("news_confirmed")
			->insert([
				"id" => $row->id,
				"player" => $sender,
				"time" => time(),
			]);
		$msg = "News confirmed, it won't be shown to you again.";
		$sendto->reply($msg);
	}

	/**
	 * This command handler adds a news entry.
	 *
	 * @HandlesCommand("news .+")
	 * @Matches("/^news add (.+)$/si")
	 */
	public function newsAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$news = $args[1];
		$this->db->table("news")
			->insert([
				"time" => time(),
				"name" => $sender,
				"news" => $news,
				"sticky" => 0,
				"deleted" => 0,
			]);
		$msg = "News has been added successfully.";

		$sendto->reply($msg);
	}

	/**
	 * This command handler removes a news entry.
	 *
	 * @HandlesCommand("news .+")
	 * @Matches("/^news rem (\d+)$/i")
	 */
	public function newsRemCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];

		$row = $this->getNewsItem($id);
		if ($row === null) {
			$msg = "No news entry found with the ID <highlight>{$id}<end>.";
		} else {
			$this->db->table("news")
				->where("id", $id)
				->update(["deleted" => 1]);
			$msg = "News entry <highlight>{$id}<end> was deleted successfully.";
		}

		$sendto->reply($msg);
	}

	/**
	 * This command handler pins a news entry.
	 *
	 * @HandlesCommand("news .+")
	 * @Matches("/^news pin (\d+)$/i")
	 */
	public function newsPinCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];

		$row = $this->getNewsItem($id);

		if (!isset($row)) {
			$msg = "No news entry found with the ID <highlight>{$id}<end>.";
		} elseif ($row->sticky) {
			$msg = "News ID $id is already pinned.";
		} else {
			$this->db->table("news")
				->where("id", $id)
				->update(["sticky" => 1]);
			$msg = "News ID $id successfully pinned.";
		}
		$sendto->reply($msg);
	}

	/**
	 * This command handler unpins a news entry.
	 *
	 * @HandlesCommand("news .+")
	 * @Matches("/^news unpin (\d+)$/i")
	 */
	public function newsUnpinCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];

		$row = $this->getNewsItem($id);

		if (!isset($row)) {
			$msg = "No news entry found with the ID <highlight>{$id}<end>.";
		} elseif (!$row->sticky) {
			$msg = "News ID $id is not pinned.";
		} else {
			$this->db->table("news")
				->where("id", $id)
				->update(["sticky" => 0]);
			$msg = "News ID $id successfully unpinned.";
		}
		$sendto->reply($msg);
	}

	public function getNewsItem(int $id): ?News {
		return $this->db->table("news")
			->where("deleted", 0)
			->where("id", $id)
			->asObj(News::class)
			->first();
	}

	/**
	 * Get a list of all news
	 * @Api("/news")
	 * @GET
	 * @AccessLevelFrom("news")
	 * @ApiResult(code=200, class='News[]', desc='A list of news items')
	 */
	public function apiNewsEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		/** @var News[] */
		$result = $this->db->table("news")
			->where("deleted", 0)
			->asObj(News::class)
			->toArray();
		return new ApiResponse($result);
	}

	/**
	 * Get a single news item by id
	 * @Api("/news/%d")
	 * @GET
	 * @AccessLevelFrom("news")
	 * @ApiResult(code=200, class='News', desc='The requested news item')
	 * @ApiResult(code=404, desc='Given news id not found')
	 */
	public function apiNewsIdEndpoint(Request $request, HttpProtocolWrapper $server, int $id): Response {
		$result = $this->getNewsItem($id);
		if (!isset($result)) {
			return new Response(Response::NOT_FOUND);
		}
		return new ApiResponse($result);
	}

	/**
	 * Create a new news item
	 * @Api("/news")
	 * @POST
	 * @AccessLevelFrom("news .+")
	 * @RequestBody(class='NewNews', desc='The item to create', required=true)
	 * @ApiResult(code=204, desc='The news item was created successfully')
	 */
	public function apiNewsCreateEndpoint(Request $request, HttpProtocolWrapper $server): Response {
		$news = $request->decodedBody;
		try {
			/** @var NewNews */
			$decoded = JsonImporter::convert(NewNews::class, $news);
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		unset($decoded->id);
		$decoded->time ??= time();
		$decoded->name = $request->authenticatedAs;
		$decoded->sticky ??= false;
		$decoded->deleted ??= false;
		if (!isset($decoded->news)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		if ($this->db->insert("news", $decoded)) {
			return new Response(Response::NO_CONTENT);
		}
		return new Response(Response::INTERNAL_SERVER_ERROR);
	}

	/**
	 * Modify an existing news item
	 * @Api("/news/%d")
	 * @PATCH
	 * @AccessLevelFrom("news .+")
	 * @RequestBody(class='NewNews', desc='The new data for the item', required=true)
	 * @ApiResult(code=200, class='News', desc='The news item it is now')
	 */
	public function apiNewsModifyEndpoint(Request $request, HttpProtocolWrapper $server, int $id): Response {
		$result = $this->getNewsItem($id);
		if (!isset($result)) {
			return new Response(Response::NOT_FOUND);
		}
		$news = $request->decodedBody;
		try {
			/** @var NewNews */
			$decoded = JsonImporter::convert(NewNews::class, $news);
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$decoded->id = $id;
		$decoded->name = $request->authenticatedAs;
		foreach ($decoded as $attr => $value) {
			if (isset($value)) {
				$result->{$attr} = $value;
			}
		}
		if (!$this->db->update("news", "id", $decoded)) {
			return new Response(Response::INTERNAL_SERVER_ERROR);
		}
		return new ApiResponse($this->getNewsItem($id));
	}

	/**
	 * @NewsTile("news")
	 * @Description("Show excerpts of unread news")
	 * @Example("<header2>News [<u>see more</u>]<end>
	 * <tab><highlight>2021-Oct-18<end>: We have a new tower site...")
	 */
	public function newsTile(string $sender, callable $callback): void {
		$thirtyDays = time() - (86400 * 30);
		$news = $this->getNewsItems($sender);
		$unreadNews = $news->where("confirmed", false)
			->where("time", ">", $thirtyDays);
		if ($unreadNews->isEmpty()) {
			$callback(null);
			return;
		}
		$blob = "<header2>News [".
			$this->text->makeChatcmd("see all", "/tell <myname> news") . "]<end>\n";
		$blobLines = [];
		foreach ($unreadNews as $news) {
			$firstLine = explode("\n", $news->news)[0];
			$firstWords = array_slice(preg_split("/\s+/", $firstLine), 0, 5);
			$blobLines []= "<tab><highlight>" . $this->util->date($news->time).
				"<end>: " . join(" ", $firstWords) . "...";
		}
		$blob .= join("\n", $blobLines);
		$callback($blob);
	}
}
