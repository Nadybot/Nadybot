<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	AOChatEvent,
	CmdContext,
	DB,
	Event,
	EventManager,
	Nadybot,
	SettingManager,
	Text,
	UserStateEvent,
	Util,
};
use Nadybot\Core\Modules\ALTS\AltsController;
use Nadybot\Core\ParamClass\PRemove;
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
 *  @ProvidesEvent(value="sync(news)", desc="Triggered whenever someone creates or modifies a news entry")
 *  @ProvidesEvent(value="sync(remnews)", desc="Triggered when deleting a news entry")
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
	public EventManager $eventManager;

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
			->limit($this->settingManager->getInt('num_news_shown')??10)
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
			$blob .= "[" . $this->text->makeChatcmd("remove", "/tell <myname> news rem $item->id") . "] ";
			if ($item->sticky) {
				$blob .= "[" . $this->text->makeChatcmd("unpin", "/tell <myname> news unpin $item->id") . "] ";
			} else {
				$blob .= "[" . $this->text->makeChatcmd("pin", "/tell <myname> news pin $item->id") . "] ";
			}
			if (!$item->confirmed) {
				$blob .= "[" . $this->text->makeChatcmd("confirm", "/tell <myname> news confirm $item->id") . "] ";
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
		if (!isset($item)) {
			return null;
		}
		$layout = $this->settingManager->getInt('news_announcement_layout')??1;
		if ($layout === 1) {
			$msg = $this->text->makeBlob(
				"News [Last updated at " . $this->util->date($item->time) . "]",
				$blob
			);
		} elseif ($layout === 2) {
			$msg = $this->text->blobWrap(
				"<yellow>NEWS:<end> <highlight>{$latestNews->news}<end>\n".
					"By {$latestNews->name} (".
					$this->util->date($latestNews->time) . ") ",
				$this->text->makeBlob("more", $blob, "News")
			);
		}
		return $msg;
	}

	/**
	 * @Event("logOn")
	 * @Description("Sends news to org members logging in")
	 */
	public function logonEvent(UserStateEvent $eventObj): void {
		$sender = $eventObj->sender;

		if (!$this->chatBot->isReady()
			|| !isset($this->chatBot->guildmembers[$sender])
			|| !is_string($sender)
			|| !$this->hasRecentNews($sender)
		) {
			return;
		}
		$news = $this->getNews($sender, true);
		if (isset($news)) {
			$this->chatBot->sendMassTell($news, $sender);
		}
	}

	/**
	 * @Event("joinPriv")
	 * @Description("Sends news to players joining private channel")
	 */
	public function privateChannelJoinEvent(AOChatEvent $eventObj): void {
		if (!is_string($eventObj->sender)
			|| !$this->hasRecentNews($eventObj->sender)
		) {
			return;
		}
		$news = $this->getNews($eventObj->sender, true);
		if (isset($news)) {
			$this->chatBot->sendMassTell($news, $eventObj->sender);
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
	 */
	public function newsCommand(CmdContext $context): void {
		$msg = $this->getNews($context->char->name, false);

		$context->reply($msg ?? "No News recorded yet.");
	}

	/**
	 * This command handler confirms a news entry.
	 *
	 * @HandlesCommand("news confirm .+")
	 * @Mask $action confirm
	 */
	public function newsconfirmCommand(CmdContext $context, string $action, int $id): void {
		$row = $this->getNewsItem($id);
		if ($row === null) {
			$msg = "No news entry found with the ID <highlight>{$id}<end>.";
			$context->reply($msg);
			return;
		}
		if ($this->settingManager->getBool('news_confirmed_for_all_alts')) {
			$sender = $this->altsController->getAltInfo($context->char->name)->main;
		}

		if ($this->db->table("news_confirmed")
			->where("id", $row->id)
			->where("player", $context->char->name)
			->exists()
		) {
			$msg = "You've already confirmed these news.";
			$context->reply($msg);
			return;
		}
		$this->db->table("news_confirmed")
			->insert([
				"id" => $row->id,
				"player" => $context->char->name,
				"time" => time(),
			]);
		$msg = "News confirmed, it won't be shown to you again.";
		$context->reply($msg);
	}

	/**
	 * This command handler adds a news entry.
	 *
	 * @HandlesCommand("news .+")
	 * @Mask $action add
	 */
	public function newsAddCommand(CmdContext $context, string $action, string $news): void {
		$entry = [
			"time" => time(),
			"name" => $context->char->name,
			"news" => $news,
			"sticky" => 0,
			"deleted" => 0,
			"uuid" => $this->util->createUUID(),
		];
		$this->db->table("news")
			->insert($entry);
		$msg = "News has been added successfully.";
		$event = new SyncNewsEvent();
		$event->time = $entry["time"];
		$event->name = $entry["name"];
		$event->news = $entry["news"];
		$event->uuid = $entry["uuid"];
		$event->sticky = (bool)$entry["sticky"];
		$event->forceSync = $context->forceSync;
		$this->eventManager->fireEvent($event);

		$context->reply($msg);
	}

	/**
	 * This command handler removes a news entry.
	 *
	 * @HandlesCommand("news .+")
	 */
	public function newsRemCommand(CmdContext $context, PRemove $action, int $id): void {
		$row = $this->getNewsItem($id);
		if ($row === null) {
			$msg = "No news entry found with the ID <highlight>{$id}<end>.";
		} else {
			$this->db->table("news")
				->where("id", $id)
				->update(["deleted" => 1]);
			$msg = "News entry <highlight>{$id}<end> was deleted successfully.";
			$event = new SyncRemnewsEvent();
			$event->uuid = $row->uuid;
			$event->forceSync = $context->forceSync;
			$this->eventManager->fireEvent($event);
		}

		$context->reply($msg);
	}

	/**
	 * This command handler pins a news entry.
	 *
	 * @HandlesCommand("news .+")
	 * @Mask $action pin
	 */
	public function newsPinCommand(CmdContext $context, string $action, int $id): void {
		$row = $this->getNewsItem($id);

		if (!isset($row)) {
			$msg = "No news entry found with the ID <highlight>{$id}<end>.";
		} elseif ($row->sticky) {
			$msg = "News ID {$id} is already pinned.";
		} else {
			$this->db->table("news")
				->where("id", $id)
				->update(["sticky" => 1]);
			$msg = "News ID {$id} successfully pinned.";
			$event = new SyncNewsEvent();
			$event->time = $row->time;
			$event->name = $row->name;
			$event->news = $row->news;
			$event->uuid = $row->uuid;
			$event->sticky = true;
			$event->forceSync = $context->forceSync;
			$this->eventManager->fireEvent($event);
		}
		$context->reply($msg);
	}

	/**
	 * This command handler unpins a news entry.
	 *
	 * @HandlesCommand("news .+")
	 * @Mask $action unpin
	 */
	public function newsUnpinCommand(CmdContext $context, string $action, int $id): void {
		$row = $this->getNewsItem($id);

		if (!isset($row)) {
			$msg = "No news entry found with the ID <highlight>{$id}<end>.";
		} elseif (!$row->sticky) {
			$msg = "News ID {$id} is not pinned.";
		} else {
			$this->db->table("news")
				->where("id", $id)
				->update(["sticky" => 0]);
			$msg = "News ID {$id} successfully unpinned.";
			$event = new SyncNewsEvent();
			$event->time = $row->time;
			$event->name = $row->name;
			$event->news = $row->news;
			$event->uuid = $row->uuid;
			$event->sticky = false;
			$event->forceSync = $context->forceSync;
			$this->eventManager->fireEvent($event);
		}
		$context->reply($msg);
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
			if (!is_object($news)) {
				throw new Exception("Wrong content body");
			}
			/** @var NewNews */
			$decoded = JsonImporter::convert(NewNews::class, $news);
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		unset($decoded->id);
		$decoded->time ??= time();
		$decoded->name = $request->authenticatedAs??"_";
		$decoded->sticky ??= false;
		$decoded->deleted ??= false;
		$decoded->uuid = $this->util->createUUID();
		if (!isset($decoded->news)) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		if ($this->db->insert("news", $decoded)) {
			$event = new SyncNewsEvent();
			$event->time = $decoded->time;
			$event->name = $decoded->name;
			$event->news = $decoded->news;
			$event->uuid = $decoded->uuid;
			$event->sticky = $decoded->sticky;
			$this->eventManager->fireEvent($event);
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
			if (!is_object($news)) {
				throw new Exception("Wrong content");
			}
			/** @var NewNews */
			$decoded = JsonImporter::convert(NewNews::class, $news);
		} catch (Throwable $e) {
			return new Response(Response::UNPROCESSABLE_ENTITY);
		}
		$decoded->id = $id;
		$decoded->name = $request->authenticatedAs??"_";
		foreach ($decoded as $attr => $value) {
			if (isset($value)) {
				$result->{$attr} = $value;
			}
		}
		if (!$this->db->update("news", "id", $decoded)) {
			$event = new SyncNewsEvent();
			$event->time = $decoded->time;
			$event->name = $decoded->name;
			$event->news = $decoded->news;
			$event->uuid = $result->uuid;
			$event->sticky = $decoded->sticky;
			$this->eventManager->fireEvent($event);
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

	/**
	 * @Event("sync(news)")
	 * @Description("Sync external news created or modified")
	 */
	public function processNewsSyncEvent(SyncNewsEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->db->table("news")
			->upsert($event->toData(), "uuid", $event->toData());
	}

	/**
	 * @Event("sync(remnews)")
	 * @Description("Sync external news being deleted")
	 */
	public function processRemnewsSyncEvent(SyncRemnewsEvent $event): void {
		if (!$event->isLocal()) {
			$this->db->table("news")->where("uuid", $event->uuid)->update(["deleted" => 1]);
		}
	}
}
