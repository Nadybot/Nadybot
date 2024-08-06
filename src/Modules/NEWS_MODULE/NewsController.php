<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use function Safe\preg_split;

use Amp\Http\HttpStatus;
use Amp\Http\Server\{Request, Response};
use EventSauce\ObjectHydrator\{DefinitionProvider, KeyFormatterWithoutConversion, ObjectMapperUsingReflection};
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\ParamClass\PUuid;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	EventManager,
	Events\JoinMyPrivEvent,
	Events\LogonEvent,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Nadybot,
	ParamClass\PRemove,
	Text,
	Util,
};
use Nadybot\Modules\WEBSERVER_MODULE\{ApiResponse, WebserverController};
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'news',
		accessLevel: 'member',
		description: 'Shows news',
	),
	NCA\DefineCommand(
		command: NewsController::CMD_NEWS_MANAGE,
		accessLevel: 'mod',
		description: 'Adds, removes, pins or unpins a news entry',
	),

	NCA\ProvidesEvent(
		event: SyncNewsEvent::class,
		desc: 'Triggered whenever someone creates or modifies a news entry'
	),
	NCA\ProvidesEvent(
		event: SyncNewsDeleteEvent::class,
		desc: 'Triggered when deleting a news entry'
	)
]
class NewsController extends ModuleInstance {
	public const CMD_NEWS_MANAGE = 'news add/change/delete';

	/** Maximum number of news items shown */
	#[NCA\Setting\Number(options: [5, 10, 15, 20])]
	public int $numNewsShown = 10;

	/** Layout of the news announcement */
	#[NCA\Setting\Options(options: [
		'Last date' => 1,
		'Latest news' => 2,
	])]
	public int $newsAnnouncementLayout = 1;

	/** Confirmed news count for all alts */
	#[NCA\Setting\Boolean]
	public bool $newsConfirmedForAllAlts = true;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Nadybot $chatBot;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Logger]
	private LoggerInterface $logger;

	/** @return Collection<int,INews> */
	public function getNewsItems(string $player): Collection {
		if ($this->newsConfirmedForAllAlts) {
			$player = $this->altsController->getMainOf($player);
		}
		$query = $this->db->table(News::getTable(), 'n')
			->where('deleted', 0)
			->orderByDesc('sticky')
			->orderByDesc('time')
			->limit($this->numNewsShown)
			->select('n.*')
			->selectSub(
				$this->db->table(NewsConfirmed::getTable(), 'c')
					->whereColumn('c.id', 'n.id')
					->where('c.player', $player)
					->selectRaw('COUNT(*) > 0'),
				'confirmed'
			);
		return $query->asObj(INews::class);
	}

	/** @return ?list<string> */
	public function getNews(string $player, bool $onlyUnread=true): ?array {
		$news = $this->getNewsItems($player);
		if ($onlyUnread) {
			$news = $news->where('confirmed', false);
		}
		if ($news->count() === 0) {
			return null;
		}
		$latestNews = $news->firstOrFail();
		$msg = '';
		$blob = '';
		$sticky = '';
		foreach ($news as $item) {
			if ($item->time > $latestNews->time) {
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
				$blob .= '<img src=tdb://id:GFX_GUI_PINBUTTON_PRESSED> ';
			}
			$blob .= ($item->confirmed ? '<grey>' : '<highlight>').
				"{$item->news}<end>\n";
			$blob .= "By {$item->name} " . Util::date($item->time) . ' ';
			$blob .= '[' . Text::makeChatcmd('remove', "/tell <myname> news rem {$item->id}") . '] ';
			if ($item->sticky) {
				$blob .= '[' . Text::makeChatcmd('unpin', "/tell <myname> news unpin {$item->id}") . '] ';
			} else {
				$blob .= '[' . Text::makeChatcmd('pin', "/tell <myname> news pin {$item->id}") . '] ';
			}
			if (!$item->confirmed) {
				$blob .= '[' . Text::makeChatcmd('confirm', "/tell <myname> news confirm {$item->id}") . '] ';
			}
			$blob .= "\n";
			$sticky = $item->sticky;
		}

		/** @var ?News */
		$item = $this->db->table(News::getTable())
			->where('deleted', 0)
			->orderByDesc('time')
			->limit(1)
			->asObj(News::class)
			->first();
		if (!isset($item)) {
			return null;
		}
		$layout = $this->newsAnnouncementLayout;
		if ($layout === 1) {
			$msg = $this->text->makeBlob(
				'News [Last updated at ' . Util::date($item->time) . ']',
				$blob
			);
		} elseif ($layout === 2) {
			$msg = Text::blobWrap(
				"<yellow>NEWS:<end> <highlight>{$latestNews->news}<end>\n".
					"By {$latestNews->name} (".
					Util::date($latestNews->time) . ') ',
				$this->text->makeBlob('more', $blob, 'News')
			);
		}
		return (array)$msg;
	}

	#[NCA\Event(
		name: LogonEvent::EVENT_MASK,
		description: 'Sends news to org members logging in'
	)]
	public function logonEvent(LogonEvent $eventObj): void {
		$sender = $eventObj->sender;

		if (!$this->chatBot->isReady()
			|| !isset($this->chatBot->guildmembers[$sender])
			|| $eventObj->wasOnline !== false
			|| !$this->hasRecentNews($sender)
		) {
			return;
		}
		$news = $this->getNews($sender, true);
		if (isset($news)) {
			$this->chatBot->sendMassTell($news, $sender);
		}
	}

	#[NCA\Event(
		name: JoinMyPrivEvent::EVENT_MASK,
		description: 'Sends news to players joining private channel'
	)]
	public function privateChannelJoinEvent(JoinMyPrivEvent $eventObj): void {
		if (!$this->hasRecentNews($eventObj->sender)) {
			return;
		}
		$news = $this->getNews($eventObj->sender, true);
		if (isset($news)) {
			$this->chatBot->sendMassTell($news, $eventObj->sender);
		}
	}

	/** Check if there are recent news for player $player */
	public function hasRecentNews(string $player): bool {
		$thirtyDays = time() - (86_400 * 30);
		$news = $this->getNewsItems($player);
		return $news->where('confirmed', false)
			->contains('time', '>', $thirtyDays);
	}

	/** Show the latest news entries */
	#[NCA\HandlesCommand('news')]
	public function newsCommand(CmdContext $context): void {
		$msg = $this->getNews($context->char->name, false);

		$context->reply($msg ?? 'No News recorded yet.');
	}

	/** Confirm having read a news entry */
	#[NCA\HandlesCommand('news')]
	public function newsconfirmCommand(
		CmdContext $context,
		#[NCA\Str('confirm')] string $action,
		PUuid $id
	): void {
		$id = $id();
		$row = $this->getNewsItem($id);
		if ($row === null) {
			$msg = "No news entry found with the ID <highlight>{$id}<end>.";
			$context->reply($msg);
			return;
		}
		$sender = $context->char->name;
		if ($this->newsConfirmedForAllAlts) {
			$sender = $this->altsController->getMainOf($context->char->name);
		}

		if ($this->db->table(NewsConfirmed::getTable())
			->where('id', $row->id)
			->where('player', $sender)
			->exists()
		) {
			$msg = "You've already confirmed these news.";
			$context->reply($msg);
			return;
		}
		$this->db->insert(new NewsConfirmed(
			id: $row->id,
			player: $sender,
			time: time(),
		));
		$msg = "News confirmed, it won't be shown to you again.";
		$context->reply($msg);
	}

	/** Add a news entry */
	#[NCA\HandlesCommand(self::CMD_NEWS_MANAGE)]
	public function newsAddCommand(
		CmdContext $context,
		#[NCA\Str('add')] string $action,
		string $news
	): void {
		$entry = new News(
			time: time(),
			name: $context->char->name,
			news: $news,
			sticky: false,
			deleted: false,
		);
		$this->db->insert($entry);
		$msg = 'News has been added successfully.';
		$event = new SyncNewsEvent(
			time: $entry->time,
			name: $entry->name,
			news: $entry->news,
			uuid: $entry->id->toString(),
			sticky: $entry->sticky,
			forceSync: $context->forceSync,
		);
		$this->eventManager->fireEvent($event);

		$context->reply($msg);
	}

	/** Remove a news entry by ID */
	#[NCA\HandlesCommand(self::CMD_NEWS_MANAGE)]
	public function newsRemCommand(
		CmdContext $context,
		PRemove $action,
		PUuid $id
	): void {
		$id = $id();
		$row = $this->getNewsItem($id);
		if ($row === null) {
			$msg = "No news entry found with the ID <highlight>{$id}<end>.";
		} else {
			$this->db->table(News::getTable())
				->where('id', $id)
				->update(['deleted' => 1]);
			$msg = "News entry <highlight>{$id}<end> was deleted successfully.";
			$event = new SyncNewsDeleteEvent(
				uuid: $row->id->toString(),
				forceSync: $context->forceSync,
			);
			$this->eventManager->fireEvent($event);
		}

		$context->reply($msg);
	}

	/** Pin a news entry to the top */
	#[NCA\HandlesCommand(self::CMD_NEWS_MANAGE)]
	public function newsPinCommand(
		CmdContext $context,
		#[NCA\Str('pin')] string $action,
		PUuid $id
	): void {
		$id = $id();
		$row = $this->getNewsItem($id);

		if (!isset($row)) {
			$msg = "No news entry found with the ID <highlight>{$id}<end>.";
		} elseif ($row->sticky) {
			$msg = "News ID {$id} is already pinned.";
		} else {
			$this->db->table(News::getTable())
				->where('id', $id)
				->update(['sticky' => 1]);
			$msg = "News ID {$id} successfully pinned.";
			$event = new SyncNewsEvent(
				time: $row->time,
				name: $row->name,
				news: $row->news,
				uuid: $row->id->toString(),
				sticky: true,
				forceSync: $context->forceSync,
			);
			$this->eventManager->fireEvent($event);
		}
		$context->reply($msg);
	}

	/** Unpin a news entry from the top */
	#[NCA\HandlesCommand(self::CMD_NEWS_MANAGE)]
	public function newsUnpinCommand(
		CmdContext $context,
		#[NCA\Str('unpin')] string $action,
		PUuid $id
	): void {
		$id = $id();
		$row = $this->getNewsItem($id);

		if (!isset($row)) {
			$msg = "No news entry found with the ID <highlight>{$id}<end>.";
		} elseif (!$row->sticky) {
			$msg = "News ID {$id} is not pinned.";
		} else {
			$this->db->table(News::getTable())
				->where('id', $id)
				->update(['sticky' => 0]);
			$msg = "News ID {$id} successfully unpinned.";
			$event = new SyncNewsEvent(
				time: $row->time,
				name: $row->name,
				news: $row->news,
				uuid: $row->id->toString(),
				sticky: false,
				forceSync: $context->forceSync,
			);
			$this->eventManager->fireEvent($event);
		}
		$context->reply($msg);
	}

	public function getNewsItem(\Stringable|string $id): ?News {
		return $this->db->table(News::getTable())
			->where('deleted', 0)
			->where('id', (string)$id)
			->asObj(News::class)
			->first();
	}

	/** Get a list of all news */
	#[
		NCA\Api('/news'),
		NCA\GET,
		NCA\AccessLevelFrom('news'),
		NCA\ApiResult(code: 200, class: 'News[]', desc: 'A list of news items')
	]
	public function apiNewsEndpoint(Request $request): Response {
		$result = $this->db->table(News::getTable())
			->where('deleted', 0)
			->asObjArr(News::class);
		return ApiResponse::create($result);
	}

	/** Get a single news item by id */
	#[
		NCA\Api('/news/%d'),
		NCA\GET,
		NCA\AccessLevelFrom('news'),
		NCA\ApiResult(code: 200, class: 'News', desc: 'The requested news item'),
		NCA\ApiResult(code: 404, desc: 'Given news id not found')
	]
	public function apiNewsIdEndpoint(Request $request, string $id): Response {
		$result = $this->getNewsItem($id);
		if (!isset($result)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		return ApiResponse::create($result);
	}

	/** Create a new news item */
	#[
		NCA\Api('/news'),
		NCA\POST,
		NCA\AccessLevelFrom(self::CMD_NEWS_MANAGE),
		NCA\RequestBody(class: 'NewNews', desc: 'The item to create', required: true),
		NCA\ApiResult(code: 204, desc: 'The news item was created successfully')
	]
	public function apiNewsCreateEndpoint(Request $request): Response {
		$user = $request->getAttribute(WebserverController::USER) ?? '_';
		$body = $request->getAttribute(WebserverController::BODY);
		$this->logger->notice('Body: {body}', ['body' => $body]);
		$mapper = new ObjectMapperUsingReflection(
			new DefinitionProvider(
				keyFormatter: new KeyFormatterWithoutConversion(),
			),
		);
		try {
			if (!is_array($body)) {
				throw new Exception('Wrong content body');
			}
			$default = [
				'time' => time(),
				'name' => $user,
				'sticky' => false,
				'deleted' => false,
				'uuid' => Util::createUUID(),
			];
			$data = Util::mergeArraysRecursive($default, $body);

			$news = $mapper->hydrateObject(News::class, $data);
		} catch (Throwable) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}

		if ($this->db->insert($news) === 0) {
			return new Response(status: HttpStatus::INTERNAL_SERVER_ERROR);
		}
		$this->eventManager->fireEvent(SyncNewsEvent::fromNews($news));
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/** Modify an existing news item */
	#[
		NCA\Api('/news/%d'),
		NCA\PATCH,
		NCA\AccessLevelFrom(self::CMD_NEWS_MANAGE),
		NCA\RequestBody(class: 'NewNews', desc: 'The new data for the item', required: true),
		NCA\ApiResult(code: 200, class: 'News', desc: 'The news item it is now')
	]
	public function apiNewsModifyEndpoint(Request $request, string $id): Response {
		$oldItem = $this->getNewsItem($id);
		if (!isset($oldItem)) {
			return new Response(status: HttpStatus::NOT_FOUND);
		}
		$user = $request->getAttribute(WebserverController::USER) ?? '_';
		$body = $request->getAttribute(WebserverController::BODY);
		$mapper = new ObjectMapperUsingReflection(
			new DefinitionProvider(
				keyFormatter: new KeyFormatterWithoutConversion(),
			),
		);
		try {
			if (!is_array($body)) {
				throw new Exception('Wrong content body');
			}
			$oldData = $mapper->serializeObject($oldItem);
			$data = Util::mergeArraysRecursive($oldData, $body);
			$news = $mapper->hydrateObject(News::class, $data);
		} catch (Throwable) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		$news->name = $user;
		if ($this->db->update($news) === 0) {
			return new Response(status: HttpStatus::INTERNAL_SERVER_ERROR);
		}
		$this->eventManager->fireEvent(SyncNewsEvent::fromNews($news));
		return ApiResponse::create($this->getNewsItem($id));
	}

	#[
		NCA\NewsTile(
			name: 'news',
			description: 'Show excerpts of unread news',
			example: "<header2>News [<u>see more</u>]<end>\n".
				'<tab><highlight>2021-Oct-18<end>: We have a new tower site...'
		)
	]
	public function newsTile(string $sender): ?string {
		$thirtyDays = time() - (86_400 * 30);
		$unreadNews = $this->getNewsItems($sender)
			->where('confirmed', false)
			->where('time', '>', $thirtyDays);
		if ($unreadNews->isEmpty()) {
			return null;
		}
		$blob = '<header2>News ['.
			Text::makeChatcmd('see all', '/tell <myname> news') . "]<end>\n";
		$blobLines = [];
		foreach ($unreadNews as $news) {
			$firstLine = explode("\n", $news->news)[0];
			$firstWords = array_slice(preg_split("/\s+/", $firstLine), 0, 5);
			$blobLines []= '<tab><highlight>' . Util::date($news->time).
				'<end>: ' . implode(' ', $firstWords) . '...';
		}
		$blob .= implode("\n", $blobLines);
		return $blob;
	}

	#[NCA\Event(
		name: SyncNewsEvent::EVENT_MASK,
		description: 'Sync external news created or modified'
	)]
	public function processNewsSyncEvent(SyncNewsEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->db->table(News::getTable())
			->upsert($event->toData(), 'uuid', $event->toData());
	}

	#[NCA\Event(
		name: SyncNewsDeleteEvent::EVENT_MASK,
		description: 'Sync external news being deleted'
	)]
	public function processNewsDeleteSyncEvent(SyncNewsDeleteEvent $event): void {
		if (!$event->isLocal()) {
			$this->db->table(News::getTable())->where('uuid', $event->uuid)->update(['deleted' => 1]);
		}
	}
}
