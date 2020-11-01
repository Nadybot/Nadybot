<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Nadybot,
	Text,
	Util,
};
use Nadybot\Core\Modules\ALTS\AltsController;

/**
 * This class contains all functions necessary to deal with temporary raid blocks
 *
 * @Instance
 * @package Nadybot\Modules\RAID_MODULE
 *
 * @DefineCommand(
 *     command       = 'raidblock',
 *     accessLevel   = 'member',
 *     description   = 'Check your raid blocks',
 *     help          = 'raidblock.txt'
 * )
 *
 * @DefineCommand(
 *     command       = 'raidblock .+',
 *     accessLevel   = 'raid_leader_1',
 *     description   = 'Temporarily block raiders',
 *     help          = 'raidblock.txt'
 * )
 */
class RaidBlockController {
	public const POINTS_GAIN = "points";
	public const JOIN_RAIDS = "join";
	public const AUCTION_BIDS = "bid";

	public string $moduleName;

	public int $lastExpiration = 0;

	/** @var array<string,array<string,RaidBlock>> */
	public array $blocks = [];

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public AltsController $altsController;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public Text $text;

	/** @Setup */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, "raid_block");
		$this->loadBlocks();
	}

	/**
	 * Load all blocks from the database into memory
	 */
	public function loadBlocks(): void {
		/** @var RaidBlock[] */
		$blocks = $this->db->fetchAll(
			RaidBlock::class,
			"SELECT * FROM `raid_block_<myname>` WHERE `expiration` IS NULL OR `expiration` > ?",
			time()
		);
		$this->blocks = [];
		foreach ($blocks as $block) {
			$this->blocks[$block->player] ??= [];
			$this->blocks[$block->player][$block->blocked_from] = $block;
		}
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

	/**
	 * @HandlesCommand("raidblock .+")
	 * @Matches("/^raidblock (points|join|bid) ([^ ]+) ([^ ]+) (.+)$/")
	 */
	public function raidBlockAddCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$player = ucfirst(strtolower($args[2]));
		if ($this->isBlocked($player, $args[1])) {
			$sendto->reply("<highlight>{$player}<end> is already blocked on <highlight>{$args[1]}<end>.");
			return;
		}
		if (!$this->chatBot->get_uid($player)) {
			$sendto->reply("<highlight>{$player}<end> doesn't exist.");
		}
		$player = $this->altsController->getAltInfo($player)->main;
		$duration = $this->util->parseTime($args[3]);
		$reason = $args[4];
		if ($duration === 0) {
			$reason = "{$args[3]} $reason";
			$expiration = null;
		} else {
			$expiration = time() + $duration;
		}
		$block = new RaidBlock();
		$block->blocked_by = $sender;
		$block->blocked_from = $args[1];
		$block->expiration = $expiration;
		$block->player = $player;
		$block->reason = $reason;
		$block->time = time();
		$this->blocks[$player] ??= [];
		$this->blocks[$player][$args[1]] = $block;
		$this->db->insert("raid_block_<myname>", $block);
		$msg = "<highlight>{$player}<end> is now blocked from <highlight>".
			$this->blockToString($args[1]) . "<end> ";
		if ($duration > 0) {
			$msg .= "for <highlight>" . $this->util->unixtimeToReadable($duration) . "<end>.";
		} else {
			$msg .= "until someone removes the block.";
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("raidblock")
	 * @Matches("/^raidblock$/i")
	 */
	public function raidBlockCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->expireBans();
		$player = $this->altsController->getAltInfo($sender)->main;
		if (!isset($this->blocks[$player])) {
			$sendto->reply("You are currently not blocked from any part of raiding.");
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
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("raidblock .+")
	 * @Matches("/^raidblock ([^ ]+)$/i")
	 */
	public function raidBlockShowCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$player = ucfirst(strtolower($args[1]));
		$player = $this->altsController->getAltInfo($player)->main;
		$this->expireBans();
		if (!isset($this->blocks[$player])) {
			$sendto->reply("<highlight>{$player}<end> is currently not blocked from any part of raiding.");
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
		$sendto->reply($this->text->makeBlob($msg, $blob));
	}

	/**
	 * @HandlesCommand("raidblock .+")
	 * @Matches("/^raidblock (?:lift|del|rem) ([^ ]+) (points|join|bid)$/i")
	 * @Matches("/^raidblock (?:lift|del|rem) ([^ ]+)$/i")
	 */
	public function raidBlockLiftCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$player = ucfirst(strtolower($args[1]));
		$player = $this->altsController->getAltInfo($player)->main;
		$block = $args[2] ?? null;
		$this->expireBans();
		if (!isset($this->blocks[$player])) {
			$sendto->reply("<highlight>{$player}<end> is currently not blocked from any part of raiding.");
			return;
		}
		if (isset($block) && !isset($this->blocks[$player][$block])) {
			$sendto->reply(
				"<highlight>{$player}<end> is currently not blocked from " . $this->blockToString($block) . "."
			);
			return;
		}
		$args = [time(), $player];
		$sql = "UPDATE `raid_block_<myname>` SET `expiration`=? WHERE `player`=?";
		if (isset($block)) {
			$args []= $block;
			$sql .= " AND `blocked_from`=?";
			$this->blocks[$player][$block]->expiration = time();
		} else {
			foreach ($this->blocks[$player] as $name => $block) {
				$block->expiration = time();
			}
		}
		$this->db->exec($sql, ...$args);
		$sendto->reply("Raidblock removed from <highlight>{$player}<end>.");
	}
}
