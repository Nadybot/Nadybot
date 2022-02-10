<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Safe\DateTime;
use InvalidArgumentException;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	CommandReply,
	DB,
	EventManager,
	ModuleInstance,
	LoggerWrapper,
	Nadybot,
	ParamClass\PCharacter,
	SettingManager,
	Text,
	Timer,
	TimerEvent,
	Util,
};
use Nadybot\Modules\RAFFLE_MODULE\RaffleItem;

/**
 * This class contains all functions necessary to deal with points in a raid
 * @package Nadybot\Modules\RAID_MODULE
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Auctions"),
	NCA\DefineCommand(
		command: "bid",
		accessLevel: "member",
		description: "Bid points for an auctioned item",
	),
	NCA\DefineCommand(
		command: AuctionController::CMD_BID_AUCTION,
		accessLevel: "raid_leader_1",
		description: "Manage auctions",
	),
	NCA\DefineCommand(
		command: AuctionController::CMD_BID_REIMBURSE,
		accessLevel: "raid_leader_1",
		description: "Give back points for an auction",
	),
	NCA\ProvidesEvent("auction(start)"),
	NCA\ProvidesEvent("auction(end)"),
	NCA\ProvidesEvent("auction(cancel)"),
	NCA\ProvidesEvent("auction(bid)")
]
class AuctionController extends ModuleInstance {
	public const CMD_BID_AUCTION = "bid auction";
	public const CMD_BID_REIMBURSE = "bid reimburse";
	public const DB_TABLE = "auction_<myname>";
	public const ERR_NO_AUCTION = "There's currently nothing being auctioned.";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public RaidController $raidController;

	#[NCA\Inject]
	public RaidMemberController $raidMemberController;

	#[NCA\Inject]
	public RaidPointsController $raidPointsController;

	#[NCA\Inject]
	public RaidBlockController $raidBlockController;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Timer $timer;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Logger]
	public LoggerWrapper $logger;

	public ?Auction $auction = null;
	protected ?TimerEvent $auctionTimer = null;

	#[NCA\Setup]
	public function setup(): void {
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'auctions_only_for_raid',
			description: 'Allow auctions only for people in the raid',
			mode: 'edit',
			type: 'options',
			value: '0',
			options: 'true;false',
			intoptions: '1;0',
			accessLevel: 'raid_admin_2'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'auctions_show_max_bidder',
			description: 'Show the name of the top bidder during the auction',
			mode: 'edit',
			type: 'options',
			value: '1',
			options: 'true;false',
			intoptions: '1;0',
			accessLevel: 'raid_admin_2'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'auctions_show_rival_bidders',
			description: 'Show the names of the rival bidders',
			mode: 'edit',
			type: 'options',
			value: '0',
			options: 'true;false',
			intoptions: '1;0',
			accessLevel: 'raid_admin_2'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'auction_duration',
			description: 'Duration for auctions',
			mode: 'edit',
			type: 'time',
			value: '50s',
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'auction_min_time_after_bid',
			description: 'Bidding grace period',
			mode: 'edit',
			type: 'time',
			value: '5s',
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'auction_refund_tax',
			description: 'Refund tax in percent',
			mode: 'edit',
			type: 'number',
			value: '10',
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'auction_refund_min_tax',
			description: 'Refund minimum tax in points',
			mode: 'edit',
			type: 'number',
			value: '0',
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'auction_refund_max_tax',
			description: 'Refund maximum tax in points',
			mode: 'edit',
			type: 'number',
			value: '0',
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'auction_refund_max_time',
			description: 'Refund maximum age of auction',
			mode: 'edit',
			type: 'time',
			value: '1h',
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'auction_announcement_layout',
			description: 'Layout of the auction announcement',
			mode: 'edit',
			type: 'options',
			value: '2',
			options: 'Simple;Yellow border;Yellow header;Pink border;Rainbow border',
			intoptions: '1;2;3;4;5'
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: 'auction_winner_announcement',
			description: 'Layout of the winner announcement',
			mode: 'edit',
			type: 'options',
			value: '1',
			options: 'Simple;Yellow border;Yellow header;Pink border;Rainbow border;Gratulations',
			intoptions: '1;2;3;4;5;6'
		);
		$this->commandAlias->register($this->moduleName, "bid history", "bh");
		// $this->commandAlias->register($this->moduleName, "auction start", "bid start");
		// $this->commandAlias->register($this->moduleName, "auction end", "bid end");
		// $this->commandAlias->register($this->moduleName, "auction cancel", "bid cancel");
		// $this->commandAlias->register($this->moduleName, "auction reimburse", "bid reimburse");
		// $this->commandAlias->register($this->moduleName, "auction reimburse", "bid payback");
		// $this->commandAlias->register($this->moduleName, "auction reimburse", "bid refund");
	}

	/** Auction an item */
	#[NCA\HandlesCommand(self::CMD_BID_AUCTION)]
	public function bidStartCommand(
		CmdContext $context,
		#[NCA\Str("start")] string $action,
		string $item
	): void {
		if ($this->settingManager->getBool('auctions_only_for_raid') && !isset($this->raidController->raid)) {
			$context->reply(RaidController::ERR_NO_RAID);
			return;
		}
		if (isset($this->auction)) {
			$context->reply("There's already an auction running.");
			return;
		}
		$auction = new Auction();
		$auction->item = new RaffleItem();
		$auction->item->fromString($item);
		$auction->auctioneer = $context->char->name;
		$auction->end = time() + ($this->settingManager->getInt('auction_duration') ?? 50);
		$this->startAuction($auction);
	}

	/** Cancel the running auction */
	#[NCA\HandlesCommand(self::CMD_BID_AUCTION)]
	#[NCA\Help\Group("auction")]
	public function bidCancelCommand(
		CmdContext $context,
		#[NCA\Str("cancel")] string $action
	): void {
		if (!isset($this->auction)) {
			$context->reply(static::ERR_NO_AUCTION);
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

	/** End the running auction prematurely */
	#[NCA\HandlesCommand(self::CMD_BID_AUCTION)]
	public function bidEndCommand(
		CmdContext $context,
		#[NCA\Str("end")] string $action
	): void {
		if (!isset($this->auction)) {
			$context->reply(static::ERR_NO_AUCTION);
			return;
		}
		$this->endAuction($context->char->name);
	}

	/**
	 * Refund someone for an accidentally won auction
	 *
	 * This will refund &lt;winner&gt; for the last auction they have won.
	 * It will usually not give them back the full amount, but subtract
	 * a small "tax", as configured on the bot.
	 *
	 * You cannot refund further back than the last auction &lt;winner&gt; has won.
	 *
	 * If you want custom refunds or refunds further back than the last
	 * auction, take a look at the '<symbol>points add' and '<symbol>points rem' commands.
	 */
	#[NCA\HandlesCommand(self::CMD_BID_REIMBURSE)]
	public function bidReimburseCommand(
		CmdContext $context,
		#[NCA\Str("reimburse", "payback", "refund")] string $action,
		PCharacter $winner
	): void {
		$winner = $winner();
		/** @var ?DBAuction */
		$lastAuction = $this->db->table(self::DB_TABLE)
			->where("winner", $winner)
			->orderByDesc("id")
			->limit(1)
			->asObj(DBAuction::class)->first();
		if ($lastAuction === null) {
			$context->reply(
				"<highlight>{$winner}<end> haven't won any auction ".
				"that they could be reimbursed for."
			);
			return;
		}
		$maxAge = $this->settingManager->getInt('auction_refund_max_time') ?? 3600;
		if (time() - $lastAuction->end > $maxAge) {
			$context->reply(
				"<highlight>{$lastAuction->item}<end> was auctioned longer than ".
				$this->util->unixtimeToReadable($maxAge) . " ".
				"ago and can no longer be reimbursed."
			);
			return;
		}
		if ($lastAuction->reimbursed) {
			$context->reply(
				"<highlight>{$lastAuction->winner}<end> was already ".
				"reimbursed for {$lastAuction->item}."
			);
			return;
		}
		$minPenalty = $this->settingManager->getInt('auction_refund_min_tax')??0;
		$maxPenalty = $this->settingManager->getInt('auction_refund_max_tax')??0;
		$penalty = $this->settingManager->getInt('auction_refund_tax')??10;
		$percentualPenalty = (int)ceil(($lastAuction->cost??0) * $penalty / 100);
		if ($maxPenalty > 0) {
			$giveBack = max(
				($lastAuction->cost??0) - min($maxPenalty, max($minPenalty, $percentualPenalty, 0)),
				0
			);
		} else {
			$giveBack = max(
				($lastAuction->cost??0) - max($minPenalty, $percentualPenalty, 0),
				0
			);
		}
		if ($minPenalty > 0 && $lastAuction->cost <= $minPenalty) {
			$context->reply(
				"The minimum penalty for a refund is {$minPenalty} points. ".
				"So if the cost of {$lastAuction->item} is {$lastAuction->cost} points, ".
				"0 points would be given back."
			);
			return;
		}
		if ($giveBack === 0) {
			$context->reply(
				"{$lastAuction->cost} point" . (($lastAuction->cost !== 1) ? "s" : "").
				" minus the {$penalty}% penalty would result in 0 points given back."
			);
			return;
		}
		if (!isset($lastAuction->winner)) {
			$context->reply("Somehow, the last auction I found didn't have a winner.");
			return;
		}
		$raid = $this->raidController->raid ?? null;
		$this->raidPointsController->modifyRaidPoints(
			$lastAuction->winner,
			$giveBack,
			true,
			"Refund for " . $lastAuction->item,
			$context->char->name,
			$raid
		);
		$this->db->table(self::DB_TABLE)
			->where("id", $lastAuction->id)
			->update(["reimbursed" => true]);
		if ($minPenalty > $percentualPenalty) {
			$this->chatBot->sendPrivate(
				"<highlight>{$lastAuction->winner}<end> was reimbursed <highlight>{$giveBack}<end> point".
				(($giveBack > 1) ? "s" : "") . " ({$lastAuction->cost} - {$minPenalty} points min penalty) for ".
				$lastAuction->item . "."
			);
		} elseif ($maxPenalty > 0 && $maxPenalty < $percentualPenalty) {
			$this->chatBot->sendPrivate(
				"<highlight>{$lastAuction->winner}<end> was reimbursed <highlight>{$giveBack}<end> point".
				(($giveBack > 1) ? "s" : "") . " ({$lastAuction->cost} - {$maxPenalty} points max penalty) for ".
				$lastAuction->item . "."
			);
		} else {
			$this->chatBot->sendPrivate(
				"<highlight>{$lastAuction->winner}<end> was reimbursed <highlight>{$giveBack}<end> point".
				(($giveBack > 1) ? "s" : "") . " ({$lastAuction->cost} - {$penalty}% penalty) for ".
				$lastAuction->item . "."
			);
		}
	}

	/**
	 * Place a bid for the currently running auction
	 *
	 * This is your maximum offer which the bot will use to automatically bid
	 * against other players bidding on the same item.
	 * The bot will only use up as much of your maximum offer as necessary to
	 * win the auction.
	 *
	 * You can also use the same command to increase an already given maximum offer,
	 * but not to lower it.
	 */
	#[NCA\HandlesCommand("bid")]
	public function bidCommand(CmdContext $context, int $bid): void {
		if (!$context->isDM()) {
			$context->reply("<red>The <symbol>bid command only works in tells<end>.");
			return;
		}
		if (!isset($this->auction)) {
			$context->reply(static::ERR_NO_AUCTION);
			return;
		}
		if ($this->raidBlockController->isBlocked($context->char->name, RaidBlockController::AUCTION_BIDS)) {
			$context->reply(
				"You are currently blocked from ".
				$this->raidBlockController->blockToString(RaidBlockController::AUCTION_BIDS).
				"."
			);
			return;
		}
		if (
			$this->settingManager->getBool('auctions_only_for_raid')
			&& (
				!isset($this->raidController->raid->raiders[$context->char->name])
				|| isset($this->raidController->raid->raiders[$context->char->name]->left)
			)
		) {
			$context->reply("This auction is for the members of the current raid only.");
			return;
		}
		$myPoints = $this->raidPointsController->getRaidPoints($context->char->name);
		if ($myPoints === null) {
			$context->reply("You haven't earned any raid points yet.");
			return;
		}
		if ($myPoints < $bid) {
			$context->reply("You only have <highlight>{$myPoints}<end> raid points.");
			return;
		}
		$this->bid($context->char->name, $bid, $context);
	}

	/** See a list of the last 40 auctions */
	#[NCA\HandlesCommand("bid")]
	public function bidHistoryCommand(
		CmdContext $context,
		#[NCA\Str("history")] string $action
	): void {
		/** @var DBAuction[] */
		$items = $this->db->table(self::DB_TABLE)
			->orderByDesc("id")
			->limit(40)
			->asObj(DBAuction::class)
			->toArray();
		if (!count($items)) {
			$context->reply("No auctions have ever been started on this bot.");
			return;
		}
		$context->reply(
			$this->text->makeBlob(
				"Last auctions (" . count($items) . ")",
				$this->renderAuctionList($items)
			)
		);
	}

	/** Search the bid history for an item */
	#[NCA\HandlesCommand("bid")]
	public function bidHistorySearchCommand(
		CmdContext $context,
		#[NCA\Str("history")] string $action,
		string $search
	): void {
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
		$quickSearch = $shortcuts[strtolower($search)] ?? [];
		$query = $this->db->table(self::DB_TABLE);
		if (count($quickSearch)) {
			foreach ($quickSearch as $searchTerm) {
				$query->orWhereIlike("item", $searchTerm);
			}
		} else {
			$this->db->addWhereFromParams(
				$query,
				\Safe\preg_split('/\s+/', $search),
				'item'
			);
		}
		/** @var DBAuction[] */
		$items = (clone $query)
			->orderByDesc("end")
			->limit(40)
			->asObj(DBAuction::class)->toArray();
		if (!count($items)) {
			$context->reply("Nothing matched <highlight>{$search}<end>.");
			return;
		}
		/** @var DBAuction */
		$mostExpensiveItem = (clone $query)
			->orderByDesc("cost")
			->limit(1)
			->asObj(DBAuction::class)->first();
		$avgCost = (int)(clone $query)->avg("cost");
		$queryLastTen = (clone $query)->orderByDesc("id")->limit(10);
		$avgCostLastTen = (int)$this->db->fromSub($queryLastTen, "last_auctions")
			->avg("cost");
		$text = "<header2>Most expensive result<end>\n".
			"<tab>On " . DateTime::createFromFormat("U", (string)$mostExpensiveItem->end)->format("Y-m-d").
			", <highlight>{$mostExpensiveItem->winner}<end> paid ".
			"<highlight>" . number_format($mostExpensiveItem->cost??0) . "<end> raid points ".
			"for " . preg_replace('|"(itemref://\d+/\d+/\d+)"|', "$1", $mostExpensiveItem->item) . "\n\n".
		$text = "<header2>Average cost<end>\n".
			"<tab>Total: <highlight>" . number_format($avgCost, 1) . "<end>\n".
			"<tab>Last 10: <highlight>" . number_format($avgCostLastTen, 1) . "<end>\n\n".
			$this->renderAuctionList($items);
		$blob = $this->text->makeBlob("Auction history results for {$search}", $text);
		$context->reply($blob);
	}

	/** @param DBAuction[] $items */
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

	/**
	 * Have $sender place a bid of $offer in the current auction
	 * @param string $sender Nme of the character placing the bid
	 * @param int $offer Height of the bid
	 * @param CommandReply $sendto Where to send messages about success/failure
	 * @return void
	 */
	public function bid(string $sender, int $offer, CommandReply $sendto): void {
		if (!isset($this->auction)) {
			$sendto->reply(self::ERR_NO_AUCTION);
			return;
		}
		if ($offer <= $this->auction->bid) {
			$sendto->reply("You have to bid more than the current offer of {$this->auction->bid}.");
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

		$minTime = $this->settingManager->getInt('auction_min_time_after_bid') ?? 5;
		// When something changes, make sure people have at least
		// $minTime seconds to place new bids
		if (isset($this->auctionTimer)) {
			if ($this->auctionTimer->time - time() < $minTime) {
				$this->auctionTimer->delay = $minTime;
				$this->timer->restartEvent($this->auctionTimer);
			}
			$this->auction->end = $this->auctionTimer->time;
		}

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
		$auction = $this->auction;
		$this->auction = null;
		$event = new AuctionEvent();
		$event->type = "auction(end)";
		$event->auction = $auction;
		if (isset($sender)) {
			$event->sender = $sender;
		}
		$this->recordAuctionInDB($auction);
		if (isset($auction->bid) && $auction->bid > 0 && isset($auction->top_bidder)) {
			$this->raidPointsController->modifyRaidPoints(
				$auction->top_bidder,
				$auction->bid * -1,
				true,
				$auction->item->toString(),
				$sender ?? $this->chatBot->char->name,
				$this->raidController->raid ?? null
			);
		}
		$this->eventManager->fireEvent($event);
	}

	/**
	 * Record a finished auction into the database so that it can be searched later on
	 */
	protected function recordAuctionInDB(Auction $auction): bool {
		return $this->db->table(self::DB_TABLE)
			->insert([
				"item" => $auction->item->toString(),
				"auctioneer" => $auction->auctioneer,
				"cost" => $auction->bid,
				"winner" => $auction->top_bidder,
				"end" => $auction->end,
				"reimbursed" => false,
			]);
	}

	public function getBiddingInfo(): string {
		return "<header2>Placing a bid<end>\n".
			"To place a bid, use\n".
			"<tab><highlight>/tell " . $this->chatBot->char->name . " bid &lt;points&gt;<end>\n".
			"<i>(Replace &lt;points&gt; with the number of points you would like to bid)</i>\n\n".
			"The auction ends after " . ($this->settingManager->getInt('auction_duration')??50).
			"s, or " . ($this->settingManager->getInt('auction_min_time_after_bid')??5) . "s after ".
			"the last bid was placed.\n\n".
			"<header2>How it works<end>\n".
			"Bidding works like ebay: You bid the maximum number of points you would like ".
			"to spend on an item.\n".
			"The actual number of points spent will be that of the second highest bidder plus one,\n".
			"so you will only actually bid as many points as needed - not more.\n\n".
			"<header2>Note<end>\n".
			"When 2 people are bidding the same amount for an item, the first person ".
			"placing the bid will get the item.\n".
			"Slowly increasing your bid might cost you points!";
	}

	/**
	 * @return string[]
	 * @psalm-return array{0:string, 1:string}
	 * @phpstan-return array{0:string, 1:string}
	 */
	public function getAnnouncementBorders(): array {
		$layout = $this->settingManager->getInt('auction_announcement_layout');
		$shortDash = str_repeat("-", 25);
		$longDash = str_repeat("-", 65);
		switch ($layout) {
			case 5:
				return [
					$this->rainbow($longDash, 5) . "\n",
					$this->rainbow($longDash, 5),
				];
			case 4:
				return [
					"<font color=#FF1493>{$longDash}</font>\n",
					"<font color=#FF1493>{$longDash}</font>",
				];
			case 3:
				return [
					"<yellow>{$shortDash}[ AUCTION ]{$shortDash}<end>\n",
					"",
				];
			case 2:
				return [
					"<yellow>{$longDash}<end>\n",
					"<yellow>{$longDash}<end>",
				];
			default:
				return ["", ""];
		}
	}

	public function getAuctionAnnouncement(Auction $auction): string {
		[$top, $bottom] = $this->getAnnouncementBorders();
		$item = $auction->item->toString();
		$bidInfo = ((array)$this->text->makeBlob("click for info", $this->getBiddingInfo(), "Howto bid"))[0];
		$secondsLeft = ($auction->end - time());
		$msg = "\n{$top}".
			"<highlight>{$auction->auctioneer}<end> started an auction for ".
			"<highlight>{$item}<end>!\n".
			"You have <highlight>{$secondsLeft} seconds<end> to place bids :: {$bidInfo}".
			(strlen($bottom) ? "\n{$bottom}" : "");
		return $msg;
	}

	#[NCA\Event(
		name: "auction(start)",
		description: "Announce a new auction"
	)]
	public function announceAuction(AuctionEvent $event): void {
		$this->chatBot->sendPrivate($this->getAuctionAnnouncement($event->auction));
	}

	#[NCA\Event(
		name: "auction(end)",
		description: "Announce the winner of an auction"
	)]
	public function announceAuctionWinner(AuctionEvent $event): void {
		if ($event->auction->top_bidder === null) {
			$this->chatBot->sendPrivate("Auction is over. No one placed any bids. Do not loot it.");
			return;
		}
		$points = "point" . (($event->auction->bid > 1) ? "s" : "");
		$layout = $this->settingManager->getInt('auction_winner_announcement') ?? 1;
		$msg = sprintf(
			$this->getAuctionWinnerLayout($layout),
			$event->auction->top_bidder,
			$event->auction->item->toString(),
			$event->auction->bid,
			$points
		);
		$this->chatBot->sendPrivate($msg);
	}

	protected function rainbow(string $text, int $length=1): string {
		if ($length < 1) {
			throw new InvalidArgumentException("Argument\$length to " . __FUNCTION__ . "() cannot be less than 1");
		}
		$colors = [
			"FF0000",
			"FFa500",
			"FFFF00",
			"00BB00",
			"6666FF",
			"EE82EE",
		];
		$chars = str_split($text, $length);
		$result = "";
		for ($i = 0; $i < count($chars); $i++) {
			$result .= "<font color=#" . $colors[$i % count($colors)] . ">{$chars[$i]}</font>";
		}
		return $result;
	}

	public function getAuctionWinnerLayout(int $type): string {
		$line1 = "<highlight>%s<end> won <highlight>%s<end> for <highlight>%d<end> %s.";
		switch ($type) {
			case 6:
				return "\n".
					$this->rainbow("CONGRATULATIONS!") . "  {$line1}";
			case 5:
				return "\n".
					$this->rainbow(str_repeat("-", 65), 5) . "\n".
					$line1 . "\n".
					$this->rainbow(str_repeat("-", 65), 5);
			case 4:
				return "\n<font color=#FF1493>" . str_repeat("-", 65) . "</font>\n".
					$line1 . "\n".
					"<font color=#FF1493>" . str_repeat("-", 65) . "</font>";
			case 3:
				return "\n<yellow>" . str_repeat("-", 25) . "[ AUCTION ]" . str_repeat("-", 25) . "<end>\n".
					$line1;
			case 2:
				return "\n<yellow>" . str_repeat("-", 65) . "<end>\n".
					$line1 . "\n".
					"<yellow>" . str_repeat("-", 65) . "<end>";
			default:
				return $line1;
		}
	}

	#[NCA\Event(
		name: "auction(cancel)",
		description: "Announce the cancellation of an auction"
	)]
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
			((array)$this->text->makeBlob("click for info", $this->getBiddingInfo(), "Howto bid"))[0];
		return $msg;
	}

	#[NCA\Event(
		name: "auction(bid)",
		description: "Announce a new bid"
	)]
	public function announceAuctionBid(AuctionEvent $event): void {
		$this->chatBot->sendPrivate($this->getRunningAuctionInfo($event->auction));
	}
}
