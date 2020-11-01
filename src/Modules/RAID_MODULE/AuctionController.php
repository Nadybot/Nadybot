<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use DateTime;
use Nadybot\Core\{
	CommandAlias,
	CommandReply,
	DB,
	EventManager,
	LoggerWrapper,
	Nadybot,
	SettingManager,
	Text,
	Timer,
	TimerEvent,
	Util,
};
use Nadybot\Modules\RAFFLE_MODULE\RaffleItem;

/**
 * This class contains all functions necessary to deal with points in a raid
 *
 * @Instance
 * @package Nadybot\Modules\RAID_MODULE
 *
 * @DefineCommand(
 *     command       = 'bid',
 *     accessLevel   = 'member',
 *     description   = 'Bid points for an auctioned item',
 *     help          = 'auctions.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'auction',
 *     accessLevel   = 'raid_leader_1',
 *     description   = 'Manage auctions',
 *     help          = 'auctions.txt'
 * )

 * @DefineCommand(
 *     command       = 'auction reimburse .+',
 *     accessLevel   = 'raid_leader_1',
 *     description   = 'Give back points for an auction',
 *     help          = 'auctions.txt'
 * )
 *
 * @ProvidesEvent("auction(start)")
 * @ProvidesEvent("auction(end)")
 * @ProvidesEvent("auction(cancel)")
 * @ProvidesEvent("auction(bid)")
 */
class AuctionController {
	public const ERR_NO_AUCTION = "There's currently nothing being auctioned.";

	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public RaidController $raidController;

	/** @Inject */
	public RaidMemberController $raidMemberController;

	/** @Inject */
	public RaidPointsController $raidPointsController;

	/** @Inject */
	public RaidBLockController $raidBlockController;

	/** @Inject */
	public EventManager $eventManager;

	/** @Inject */
	public CommandAlias $commandAlias;

	/** @Inject */
	public SettingManager $settingManager;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Timer $timer;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Logger */
	public LoggerWrapper $logger;

	public ?Auction $auction = null;
	protected ?TimerEvent $auctionTimer = null;

	/** @Setup */
	public function setup(): void {
		$this->settingManager->add(
			$this->moduleName,
			'auctions_only_for_raid',
			'Allow auctions only for people in the raid',
			'edit',
			'options',
			'0',
			'true;false',
			'1;0',
			'raid_admin_2'
		);
		$this->settingManager->add(
			$this->moduleName,
			'auctions_show_max_bidder',
			'Show the name of the top bidder during the auction',
			'edit',
			'options',
			'1',
			'true;false',
			'1;0',
			'raid_admin_2'
		);
		$this->settingManager->add(
			$this->moduleName,
			'auctions_show_rival_bidders',
			'Show the names of the rival bidders',
			'edit',
			'options',
			'0',
			'true;false',
			'1;0',
			'raid_admin_2'
		);
		$this->settingManager->add(
			$this->moduleName,
			'auction_duration',
			'Duration for auctions',
			'edit',
			'time',
			'50s',
		);
		$this->settingManager->add(
			$this->moduleName,
			'auction_min_time_after_bid',
			'Bidding grace period',
			'edit',
			'time',
			'5s',
		);
		$this->settingManager->add(
			$this->moduleName,
			'auction_refund_tax',
			'Refund tax in percent',
			'edit',
			'number',
			'10',
		);
		$this->db->loadSQLFile($this->moduleName, "auction");
		$this->commandAlias->register($this->moduleName, "bid history", "bh");
		$this->commandAlias->register($this->moduleName, "auction start", "bid start");
		$this->commandAlias->register($this->moduleName, "auction end", "bid end");
		$this->commandAlias->register($this->moduleName, "auction cancel", "bid cancel");
		$this->commandAlias->register($this->moduleName, "auction reimburse", "bid reimburse");
		$this->commandAlias->register($this->moduleName, "auction reimburse", "bid payback");
		$this->commandAlias->register($this->moduleName, "auction reimburse", "bid refund");
	}

	/**
	 * @HandlesCommand("auction")
	 * @Matches("/^auction start (.+)$/i")
	 */
	public function bidStartCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($this->settingManager->getBool('auctions_only_for_raid') && !isset($this->raidController->raid)) {
			$sendto->reply(RaidController::ERR_NO_RAID);
			return;
		}
		if (isset($this->auction)) {
			$sendto->reply("There's already an auction running.");
			return;
		}
		$auction = new Auction();
		$auction->item = new RaffleItem();
		$auction->item->fromString($args[1]);
		$auction->auctioneer = $sender;
		$auction->end = time() + $this->settingManager->getInt('auction_duration');
		$this->startAuction($auction);
	}

	/**
	 * @HandlesCommand("auction")
	 * @Matches("/^auction cancel$/i")
	 */
	public function bidCancelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->auction)) {
			$sendto->reply(static::ERR_NO_AUCTION);
			return;
		}
		if (isset($this->auctionTimer)) {
			$this->timer->abortEvent($this->auctionTimer);
			$this->auctionTimer = null;
		}
		$event = new AuctionEvent();
		$event->type = "auction(cancel)";
		$event->auction = $this->auction;
		$this->auction = null;
		$this->eventManager->fireEvent($event);
	}

	/**
	 * @HandlesCommand("auction")
	 * @Matches("/^auction end$/i")
	 */
	public function bidEndCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (!isset($this->auction)) {
			$sendto->reply(static::ERR_NO_AUCTION);
			return;
		}
		$this->endAuction($sender);
	}

	/**
	 * @HandlesCommand("auction reimburse .+")
	 * @Matches("/^auction reimburse (.+)$/i")
	 * @Matches("/^auction payback (.+)$/i")
	 * @Matches("/^auction refund (.+)$/i")
	 */
	public function bidReimburseCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$winner = ucfirst(strtolower($args[1]));
		/** @var ?DBAuction */
		$lastAuction = $this->db->fetch(
			DBAuction::class,
			"SELECT * FROM `auction_<myname>` WHERE `winner`=? ORDER BY id DESC LIMIT 1",
			$winner
		);
		if ($lastAuction === null) {
			$sendto->reply("<highlight>{$winner}<end> haven't won any auction that they could be reimbursed for.");
			return;
		}
		if ($lastAuction->reimbursed) {
			$sendto->reply(
				"<highlight>{$lastAuction->winner}<end> was already reimbursed for {$lastAuction->item}."
			);
			return;
		}
		$penalty = $this->settingManager->getInt('auction_refund_tax');
		$giveBack = max(
			(int)floor($lastAuction->cost * ((100 - $penalty) / 100)),
			0
		);
		if ($giveBack === 0) {
			$sendto->reply(
				"{$lastAuction->cost} point" . (($lastAuction->cost !== 1) ? "s" : "").
				" minus the {$penalty}% penalty would result in 0 points given back."
			);
			return;
		}
		$raid = $this->raidController->raid ?? null;
		$this->raidPointsController->modifyRaidPoints(
			$lastAuction->winner,
			$giveBack,
			"Refund for " . $lastAuction->item,
			$sender,
			$raid
		);
		$this->db->exec("UPDATE `auction_<myname>` SET `reimbursed`=TRUE WHERE `id`=?", $lastAuction->id);
		$this->chatBot->sendPrivate(
			"<highlight>{$lastAuction->winner}<end> was reimbursed <highlight>{$giveBack}<end> point".
			(($giveBack > 1) ? "s" : "") . " ({$lastAuction->cost} - {$penalty}% penalty) for ".
			$lastAuction->item . "."
		);
	}

	/**
	 * @HandlesCommand("bid")
	 * @Matches("/^bid (\d+)$/i")
	 */
	public function bidCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if ($channel !== "msg") {
			$sendto->reply("<red>The <symbol>bid command only works in tells<end>.");
			return;
		}
		if (!isset($this->auction)) {
			$sendto->reply(static::ERR_NO_AUCTION);
			return;
		}
		if ($this->raidBlockController->isBlocked($sender, RaidBlockController::AUCTION_BIDS)) {
			$sendto->reply(
				"You are currently blocked from ".
				$this->raidBlockController->blockToString(RaidBlockController::AUCTION_BIDS).
				"."
			);
			return;
		}
		if (
			$this->settingManager->getBool('auctions_only_for_raid')
			&& (
				!isset($this->raidController->raid->raiders[$sender])
				|| isset($this->raidController->raid->raiders[$sender]->left)
			)
		) {
			$sendto->reply("This auction is for the members of the current raid only.");
			return;
		}
		$myBid = (int)$args[1];
		$myPoints = $this->raidPointsController->getRaidPoints($sender);
		if ($myPoints === null) {
			$sendto->reply("You haven't earned any raid points yet.");
			return;
		}
		if ($myPoints < $myBid) {
			$sendto->reply("You only have <highlight>{$myPoints}<end> raid points.");
			return;
		}
		$this->bid($sender, $myBid, $sendto);
	}

	/**
	 * @HandlesCommand("bid")
	 * @Matches("/^bid history$/i")
	 */
	public function bidHistoryCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		/** @var DBAuction[] */
		$items = $this->db->fetchAll(
			DBAuction::class,
			"SELECT * FROM `auction_<myname>` ORDER BY `id` DESC LIMIT 40"
		);
		if (!count($items)) {
			$sendto->reply("No auctions have ever been started on this bot.");
			return;
		}
		$sendto->reply(
			$this->text->makeBlob(
				"Last auctions (" . count($items) . ")",
				$this->renderAuctionList($items)
			)
		);
	}

	/**
	 * @HandlesCommand("bid")
	 * @Matches("/^bid history (.+)$/i")
	 */
	public function bidHistorySearchCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$shortcuts = [
			"boc"  => ["%Burden of Competence%"],
			"acdc" => ["%Alien Combat Directive Controller%", "%acdc%", "%Invasion Plan%"],
			"belt" => ["%Inertial Adjustment Processing Unit%"],
			"apf belt" => ["%Inertial Adjustment Processing Unit%"],
			"vlrd" => ["%Visible Light Remodulation Device%", "%vlrd%"],
			"eru" => ["%Energy Redistribution Unit%"],
			"nac" => ["%Notum Amplification Coil%"],
			"ape" => ["%Action Probability Estimator%"],
		];
		$quickSearch = $shortcuts[strtolower($args[1])] ?? [];
		if (count($quickSearch)) {
			$whereCriteria = "item LIKE " . join(" OR item LIKE ", array_fill(0, count($quickSearch), '?'));
		} else {
			[$whereCriteria, $quickSearch] = $this->util->generateQueryFromParams(
				preg_split('/\s+/', $args[1]),
				'item'
			);
		}
		/** @var DBAuction[] */
		$items = $this->db->fetchAll(
			DBAuction::class,
			"SELECT * FROM `auction_<myname>` WHERE $whereCriteria ORDER BY `end` DESC LIMIT 40",
			...$quickSearch
		);
		if (!count($items)) {
			$sendto->reply("Nothing matched <highlight>{$args[1]}<end>.");
			return;
		}
		/** @var DBAuction */
		$mostExpensiveItem = $this->db->fetch(
			DBAuction::class,
			"SELECT * FROM `auction_<myname>` WHERE $whereCriteria ORDER BY `cost` DESC LIMIT 1",
			...$quickSearch
		);
		$avgCost = (float)$this->db->queryRow(
			"SELECT AVG(`cost`) AS avg FROM `auction_<myname>` WHERE $whereCriteria",
			...$quickSearch
		)->avg;
		$avgCostLastTen = (float)$this->db->queryRow(
			"SELECT AVG(`cost`) AS avg FROM ".
			"(SELECT `cost` FROM `auction_<myname>` WHERE $whereCriteria ORDER BY `id` DESC LIMIT 10)",
			...$quickSearch
		)->avg;
		$text = "<header2>Most expensive result<end>\n".
			"<tab>On " . DateTime::createFromFormat("U", (string)$mostExpensiveItem->end)->format("Y-m-d").
			", <highlight>{$mostExpensiveItem->winner}<end> paid ".
			"<highlight>" . number_format($mostExpensiveItem->cost) . "<end> raid points ".
			"for " . preg_replace('|"(itemref://\d+/\d+/\d+)"|', "$1", $mostExpensiveItem->item) . "\n\n".
		$text = "<header2>Average cost<end>\n".
			"<tab>Total: <highlight>" . number_format($avgCost, 1) . "<end>\n".
			"<tab>Last 10: <highlight>" . number_format($avgCostLastTen, 1) . "<end>\n\n".
			$this->renderAuctionList($items);
		$blob = $this->text->makeBlob("Auction history results for {$args[1]}", $text);
		$sendto->reply($blob);
	}

	public function renderAuctionList(array $items): string {
		$result = [];
		foreach ($items as $item) {
			$result []= $this->renderAuctionItem($item);
		}
		return "<header2><u>Time                              Cost     Winner                                                  </u><end>\n".
			join("\n", $result);
	}

	public function renderAuctionItem(DBAuction $item): string {
		return sprintf(
			"%s     %s     %s",
			DateTime::createFromFormat("U", (string)$item->end)->format("Y-m-d H:i:s"),
			$item->cost ? $this->text->alignNumber($item->cost, 5, null, true) : "      -",
			"<highlight>" . ($item->winner ?? "nobody") . "<end> won ".
			preg_replace('|"(itemref://\d+/\d+/\d+)"|', "$1", $item->item)
		);
	}

	public function bid(string $sender, int $offer, CommandReply $sendto): void {
		if ($offer <= $this->auction->bid) {
			$sendto->reply("The have to bid more than the current offer.");
			return;
		}
		if ($sender === $this->auction->top_bidder) {
			if ($this->auction->max_bid >= $offer) {
				$sendto->reply("You cannot reduce your maximum offer.");
				return;
			}
			$this->auction->max_bid = $offer;
			$sendto->reply("Raised your maximum offer to <highlight>{$offer}<end>.");
			return;
		}

		if ($offer === $this->auction->max_bid) {
			// If both bid the same, the oldest offer has priority
			$this->auction->bid = $offer;
			if ($this->settingManager->getBool('auctions_show_rival_bidders')) {
				$this->chatBot->sendPrivate("{$sender}'s bid was not high enough.");
			}
		} elseif ($offer < $this->auction->max_bid) {
			// If the new bidder bid less, than old bidder bids one more than them
			$this->auction->bid = $offer+1;
			if ($this->settingManager->getBool('auctions_show_rival_bidders')) {
				$this->chatBot->sendPrivate("{$sender}'s bid was not high enough.");
			}
		} else {
			if (
				!$this->settingManager->getBool('auctions_show_max_bidder')
				&& isset($this->auction->top_bidder)
			) {
				$this->chatBot->sendMassTell(
					"You are no longer the top bidder.",
					$this->auction->top_bidder
				);
			}
			// If the new bidder bid more, then the new bid is the old max +1
			$this->auction->bid = $this->auction->max_bid+1;
			$this->auction->max_bid = $offer;
			$this->auction->top_bidder = $sender;
			$sendto->reply("You are now the top bidder.");
		}

		$minTime = $this->settingManager->getInt('auction_min_time_after_bid');
		// When something changes, make sure people have at least
		// $minTime seconds to place new bids
		if ($this->auctionTimer->time - time() < $minTime) {
			$this->auctionTimer->delay = $minTime;
			$this->timer->restartEvent($this->auctionTimer);
		}
		$this->auction->end = $this->auctionTimer->time;

		$event = new AuctionEvent();
		$event->auction = $this->auction;
		$event->type = "auction(bid)";
		$this->eventManager->fireEvent($event);
	}

	/**
	 * Start an auction for an item
	 */
	public function startAuction(Auction $auction): bool {
		if ($this->auction) {
			return false;
		}
		$this->auction = $auction;
		$this->auctionTimer = $this->timer->callLater(
			$auction->end - time(),
			[$this, "endAuction"],
		);
		$event = new AuctionEvent();
		$event->type = "auction(start)";
		$event->auction = $auction;
		$this->eventManager->fireEvent($event);
		return true;
	}

	/**
	 * End an auction, either forced ($sender set) or by time
	 */
	public function endAuction(?string $sender=null): void {
		if (!$this->auction) {
			return;
		}
		if (isset($this->auctionTimer)) {
			$this->timer->abortEvent($this->auctionTimer);
			$this->auctionTimer = null;
		}
		$event = new AuctionEvent();
		$event->type = "auction(end)";
		$event->auction = $this->auction;
		if (isset($sender)) {
			$event->sender = $sender;
		}
		$this->recordAuctionInDB($this->auction);
		if (isset($this->auction->bid) && $this->auction->bid > 0) {
			$this->raidPointsController->modifyRaidPoints(
				$this->auction->top_bidder,
				$this->auction->bid * -1,
				$this->auction->item->toString(),
				$sender ?? $this->chatBot->vars["name"],
				$this->raidController->raid ?? null
			);
		}
		$this->eventManager->fireEvent($event);
		$this->auction = null;
	}

	/**
	 * Record a finished auction into the database so that it can be searched later on
	 */
	protected function recordAuctionInDB(Auction $auction): bool {
		return $this->db->exec(
			"INSERT INTO auction_<myname> ".
			"(`item`, `auctioneer`, `cost`, `winner`, `end`) ".
			"VALUES (?, ?, ?, ?, ?)",
			$auction->item->toString(),
			$auction->auctioneer,
			$auction->bid,
			$auction->top_bidder,
			$auction->end
		) !== 0;
	}

	public function getBiddingInfo(): string {
		return "<header2>Placing a bid<end>\n".
			"To place a bid, use\n".
			"<tab><highlight>/tell " . $this->chatBot->vars["name"] . " bid &lt;points&gt;<end>\n".
			"<i>(Replace &lt;points&gt; with the number of points you would like to bid</i>\n\n".
			"The auction ends after " . $this->settingManager->getInt('auction_duration').
			"s, or " . $this->settingManager->getInt('auction_min_time_after_bid') . "s after ".
			"the last bid was placed.\n\n".
			"<header2>How it works<end>\n".
			"Bidding works like ebay: You bid the maximum number of points you would like ".
			"to spend on an item.\n".
			"The actual number of points spent will be that of the second hightest bidder plus one,\n".
			"so you will only actually bid as many points as needed - not more.\n\n".
			"<header2>Note<end>\n".
			"When 2 people are bidding the same amount for an item, the first person ".
			"placing the bid will get the item.\n".
			"Slowly increasing your bid might cost you points!";
	}

	public function getAuctionAnnouncement(Auction $auction): string {
		$msg = "\n<yellow>" . str_repeat("-", 40) . "<end>\n".
			"<highlight>{$auction->auctioneer}<end> started an auction for ".
			"<highlight>" . $auction->item->toString() . "<end>!\n".
			"You have <highlight>" . ($auction->end - time()) . " seconds<end> ".
			"to place bids :: ".
			$this->text->makeBlob("click for info", $this->getBiddingInfo(), "Howto bid") . "\n".
			"<yellow>" . str_repeat("-", 40) . "<end>";
		return $msg;
	}

	/**
	 * @Event("auction(start)")
	 * @Description("Announce a new auction")
	 */
	public function announceAuction(AuctionEvent $event): void {
		$this->chatBot->sendPrivate($this->getAuctionAnnouncement($event->auction));
	}

	/**
	 * @Event("auction(end)")
	 * @Description("Announce the winner of an auction")
	 */
	public function announceAuctionWinner(AuctionEvent $event): void {
		if ($event->auction->top_bidder === null) {
			$this->chatBot->sendPrivate("Auction is over. No one placed any bids. Do not loot it.");
			return;
		}
		$msg = "<highlight>{$event->auction->top_bidder}<end> has won the auction for ".
			"<highlight>" . $event->auction->item->toString() . "<end>. ".
			"<highlight>{$event->auction->bid}<end> point";
		if ($event->auction->bid > 1) {
			$msg .= "s are";
		} else {
			$msg .= " is";
		}
		$msg .= " being deduced from their account.";
		$this->chatBot->sendPrivate($msg);
	}

	/**
	 * @Event("auction(cancel)")
	 * @Description("Announce the cancellation of an auction")
	 */
	public function announceAuctionCancellation(AuctionEvent $event): void {
		$this->chatBot->sendPrivate("The auction was cancelled.");
	}

	public function getRunningAuctionInfo(Auction $auction): string {
		$msg = "The highest offer is currently ";
		if ($this->settingManager->getBool('auctions_show_max_bidder')) {
			$msg = "<highlight>{$auction->top_bidder}<end> leads with ";
		}
		$msg .= "<highlight>{$auction->bid}<end> point" . ($auction->bid > 1 ? "s" : "") . ". ".
			"Auction ends in <highlight>" . ($auction->end - time()) . " seconds<end> :: ".
			$this->text->makeBlob("click for info", $this->getBiddingInfo(), "Howto bid");
		return $msg;
	}

	/**
	 * @Event("auction(bid)")
	 * @Description("Announce a new bid")
	 */
	public function announceAuctionBid(AuctionEvent $event): void {
		$this->chatBot->sendPrivate($this->getRunningAuctionInfo($event->auction));
	}
}
