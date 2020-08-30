<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Event,
	Nadybot,
	SettingManager,
	SQLException,
	Text,
	Util,
};

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
 *		description = 'Adds, removes, stickies or unstickies a news entry',
 *		help        = 'news.txt'
 *	)
 */
class NewsController {
	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

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
		$this->db->loadSQLFile($this->moduleName, 'news');

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
	}

	/**
	 * @return INews[]
	 */
	public function getNewsItems(string $player): array {
		$sql = "SELECT n.*, ".
			"(SELECT COUNT(*)>0 FROM news_confirmed c WHERE c.id=n.id AND c.player=?) AS confirmed ".
			"FROM `news` n ".
			"WHERE deleted = 0 ".
			"ORDER BY `sticky` DESC, `time` DESC ".
			"LIMIT ?";
		/** @var INews[] */
		$newsItems = $this->db->fetchAll(
			INews::class,
			$sql,
			$player,
			$this->settingManager->getInt('num_news_shown')
		);
		return $newsItems;
	}

	/**
	 * @return string|string[]|null
	 */
	public function getNews(string $player, bool $onlyUnread=true) {
		$news = $this->getNewsItems($player);
		if ($onlyUnread) {
			$news = array_filter(
				$news,
				function(INews $item): bool {
					return $item->confirmed === false;
				}
			);
		}
		/** @var INews[] $news */
		if (count($news) === 0) {
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

		$sql = "SELECT * FROM `news` WHERE deleted = 0 ORDER BY `time` DESC LIMIT 1";
		/** @var ?News */
		$item = $this->db->fetch(News::class, $sql);
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
			$this->chatBot->sendTell($this->getNews($sender, true), $sender);
		}
	}
	
	/**
	 * @Event("joinPriv")
	 * @Description("Sends news to players joining private channel")
	 */
	public function privateChannelJoinEvent(Event $eventObj): void {
		if ($this->hasRecentNews($eventObj->sender)) {
			$this->chatBot->sendTell($this->getNews($eventObj->sender, true), $eventObj->sender);
		}
	}
	
	/**
	 * Check if there are recent news for player $player
	 */
	public function hasRecentNews(string $player): bool {
		$thirtyDays = time() - (86400 * 30);
		$news = $this->getNewsItems($player);
		$recentNews = array_filter(
			$news,
			function(INews $item) use ($thirtyDays): bool {
				return $item->confirmed === false && $item->time > $thirtyDays;
			}
		);
		return count($recentNews) > 0;
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
	 * This command handler unstickies a news entry.
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

		try {
			$this->db->exec(
				"INSERT INTO news_confirmed(id, player, time) ".
				"VALUES (?, ?, ?)",
				$row->id,
				$sender,
				time()
			);
			$msg = "News confirmed, it won't be shown to you again.";
		} catch (SQLException $e) {
			$msg = "You've already confirmed this news.";
		}
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
		$this->db->exec(
			"INSERT INTO `news` (`time`, `name`, `news`, `sticky`, `deleted`) ".
			"VALUES (?, ?, ?, 0, 0)",
			time(),
			$sender,
			$news
		);
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
			$this->db->exec("UPDATE `news` SET deleted = 1 WHERE `id` = ?", $id);
			$msg = "News entry <highlight>{$id}<end> was deleted successfully.";
		}

		$sendto->reply($msg);
	}

	/**
	 * This command handler stickies a news entry.
	 *
	 * @HandlesCommand("news .+")
	 * @Matches("/^news pin (\d+)$/i")
	 */
	public function newsPinCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];

		$row = $this->getNewsItem($id);

		if ($row->sticky) {
			$msg = "News ID $id is already stickied.";
		} else {
			$this->db->exec("UPDATE `news` SET `sticky` = 1 WHERE `id` = ?", $id);
			$msg = "News ID $id successfully stickied.";
		}
		$sendto->reply($msg);
	}

	/**
	 * This command handler unstickies a news entry.
	 *
	 * @HandlesCommand("news .+")
	 * @Matches("/^news unpin (\d+)$/i")
	 */
	public function newsUnpinCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$id = (int)$args[1];

		$row = $this->getNewsItem($id);

		if (!$row->sticky) {
			$msg = "News ID $id is not stickied.";
		} else {
			$this->db->exec("UPDATE `news` SET `sticky` = 0 WHERE `id` = ?", $id);
			$msg = "News ID $id successfully unstickied.";
		}
		$sendto->reply($msg);
	}
	
	public function getNewsItem(int $id): ?News {
		return $this->db->fetch(
			News::class,
			"SELECT * FROM `news` WHERE `deleted` = 0 AND `id` = ?",
			$id
		);
	}
}
