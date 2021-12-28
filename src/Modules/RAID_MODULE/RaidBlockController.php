<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{
	CmdContext,
	CommandReply,
	DB,
	Nadybot,
	Text,
	Util,
};
use Nadybot\Core\Modules\ALTS\AltsController;
use Nadybot\Core\ParamClass\PCharacter;
use Nadybot\Core\ParamClass\PDuration;
use Nadybot\Core\ParamClass\PRemove;

/**
 * This class contains all functions necessary to deal with temporary raid blocks
 * @package Nadybot\Modules\RAID_MODULE
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Block"),
	NCA\DefineCommand(
		command: "raidblock",
		accessLevel: "member",
		description: "Check your raid blocks",
		help: "raidblock.txt"
	),
	NCA\DefineCommand(
		command: "raidblock .+",
		accessLevel: "raid_leader_1",
		description: "Temporarily block raiders",
		help: "raidblock.txt"
	)
]
class RaidBlockController {
	public const DB_TABLE = "raid_block_<myname>";
	public const POINTS_GAIN = "points";
	public const JOIN_RAIDS = "join";
	public const AUCTION_BIDS = "bid";

	public string $moduleName;

	public int $lastExpiration = 0;

	/** @var array<string,array<string,RaidBlock>> */
	public array $blocks = [];

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public AltsController $altsController;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Setup]
	public function setup(): void {
		$this->loadBlocks();
	}

	/**
	 * Load all blocks from the database into memory
	 */
	public function loadBlocks(): void {
		$this->db->table(self::DB_TABLE)
			->whereNull("expiration")
			->orWhere("expiration", ">", time())
			->asObj(RaidBlock::class)
			->each(function(RaidBlock $block) {
				$this->blocks[$block->player] ??= [];
				$this->blocks[$block->player][$block->blocked_from] = $block;
			});
	}

	/**
	 * Remove all temporary bans that are expired from memory
	 */
	public function expireBans(): void {
		// Prevent loops that check blocks from over-triggering this
		if (time() <= $this->lastExpiration) {
			return;
		}
		$this->lastExpiration = time();
		foreach ($this->blocks as $player => $blocks) {
			foreach ($blocks as $from => $block) {
				if ($block->expiration !== null && $block->expiration <= time()) {
					unset($this->blocks[$player][$from]);
					if (empty($this->blocks[$player])) {
						unset($this->blocks[$player]);
					}
				}
			}
		}
	}

	/**
	 * Check if a player is blocked from a certain raid activity
	 */
	public function isBlocked(string $player, string $activity): bool {
		$player = $this->altsController->getAltInfo($player)->main;
		$this->expireBans();
		return isset($this->blocks[ucfirst(strtolower($player))][$activity]);
	}

	/**
	 * Get a descriptive noun for a raid block key
	 */
	public function blockToString(string $block): string {
		$mapping = [
			static::AUCTION_BIDS => "bidding in auctions",
			static::JOIN_RAIDS => "joining raids",
			static::POINTS_GAIN => "gaining raid points",
		];
		return $mapping[$block] ?? "an unknown activity";
	}

	#[NCA\HandlesCommand("raidblock .+")]
	public function raidBlockAddCommand(
		CmdContext $context,
		#[NCA\Regexp("points|join|bid")] string $blockFrom,
		PCharacter $player,
		?PDuration $duration,
		string $reason
	): void {
		$player = $player();
		if ($this->isBlocked($player, $blockFrom)) {
			$context->reply("<highlight>{$player}<end> is already blocked on <highlight>{$blockFrom}<end>.");
			return;
		}
		if (!$this->chatBot->get_uid($player)) {
			$context->reply("<highlight>{$player}<end> doesn't exist.");
		}
		$player = $this->altsController->getAltInfo($player)->main;
		if (isset($duration)) {
			$duration = $duration->toSecs();
			$expiration = time() + $duration;
		}
		$block = new RaidBlock();
		$block->blocked_by = $context->char->name;
		$block->blocked_from = $blockFrom;
		$block->expiration = $expiration??null;
		$block->player = $player;
		$block->reason = $reason;
		$block->time = time();
		$this->blocks[$player] ??= [];
		$this->blocks[$player][$blockFrom] = $block;
		$this->db->insert(self::DB_TABLE, $block, null);
		$msg = "<highlight>{$player}<end> is now blocked from <highlight>".
			$this->blockToString($blockFrom) . "<end> ";
		if ($duration > 0) {
			$msg .= "for <highlight>" . $this->util->unixtimeToReadable($duration) . "<end>.";
		} else {
			$msg .= "until someone removes the block.";
		}
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("raidblock")]
	public function raidBlockCommand(CmdContext $context): void {
		$this->expireBans();
		$player = $this->altsController->getAltInfo($context->char->name)->main;
		if (!isset($this->blocks[$player])) {
			$context->reply("You are currently not blocked from any part of raiding.");
			return;
		}
		$blocks = $this->blocks[$player];
		$msg = "You are blocked from the following raid part" . ((count($blocks) > 1) ? "s" : "") . ":";
		foreach ($blocks as $name => $block) {
			$msg .= "\n<tab><highlight>" . $this->blockToString($name) . "<end>: ";
			if ($block->expiration) {
				$msg .= "until " . $this->util->date($block->expiration);
			} else {
				$msg .= "until block is lifted";
			}
		}
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("raidblock .+")]
	public function raidBlockShowCommand(CmdContext $context, PCharacter $char): void {
		$player = $char();
		$player = $this->altsController->getAltInfo($player)->main;
		$this->expireBans();
		if (!isset($this->blocks[$player])) {
			$context->reply("<highlight>{$player}<end> is currently not blocked from any part of raiding.");
			return;
		}
		$blocks = $this->blocks[$player];
		$blob = "<header2>Active blocks for {$player}<end>\n";
		$msg = "Active raid blocks (" . count($blocks) . ")";
		foreach ($blocks as $name => $block) {
			$blob .= "\n<tab><highlight>" . $this->blockToString($name) . "<end>: ";
			if ($block->expiration) {
				$blob .= "until " . $this->util->date($block->expiration);
			} else {
				$blob .= "until block is lifted";
			}
			$blob .= " (by <highlight>{$block->blocked_by}<end>: {$block->reason})";
		}
		$context->reply($this->text->makeBlob($msg, $blob));
	}

	#[NCA\HandlesCommand("raidblock .+")]
	public function raidBlockLiftCommand(
		CmdContext $context,
		PRemove $action,
		PCharacter $char,
		#[NCA\Regexp("points|join|bid")] ?string $blockFrom
	): void {
		$player = $char();
		$player = $this->altsController->getAltInfo($player)->main;
		$this->expireBans();
		if (!isset($this->blocks[$player])) {
			$context->reply("<highlight>{$player}<end> is currently not blocked from any part of raiding.");
			return;
		}
		if (isset($blockFrom) && !isset($this->blocks[$player][$blockFrom])) {
			$context->reply(
				"<highlight>{$player}<end> is currently not blocked from " . $this->blockToString($blockFrom) . "."
			);
			return;
		}
		$query = $this->db->table(self::DB_TABLE)
			->where("player", $player);
		if (isset($blockFrom)) {
			$query->where("blocked_from", $blockFrom);
			$this->blocks[$player][$blockFrom]->expiration = time();
		} else {
			foreach ($this->blocks[$player] as $name => $blockFrom) {
				$blockFrom->expiration = time();
			}
		}
		$query->update(["expiration" => time()]);
		$context->reply("Raidblock removed from <highlight>{$player}<end>.");
	}
}
