<?php declare(strict_types=1);

namespace Nadybot\Modules\RAID_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Nadybot,
	ParamClass\PCharacter,
	ParamClass\PDuration,
	ParamClass\PRemove,
	Text,
	Util,
};

/**
 * This class contains all functions necessary to deal with temporary raid blocks
 *
 * @package Nadybot\Modules\RAID_MODULE
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Block"),
	NCA\DefineCommand(
		command: "raidblock",
		accessLevel: "member",
		description: "Check your raid blocks",
	),
	NCA\DefineCommand(
		command: RaidBlockController::CMD_RAIDBLOCK_EDIT,
		accessLevel: "raid_leader_1",
		description: "Temporarily block raiders",
	)
]
class RaidBlockController extends ModuleInstance {
	public const DB_TABLE = "raid_block_<myname>";
	public const POINTS_GAIN = "points";
	public const JOIN_RAIDS = "join";
	public const AUCTION_BIDS = "bid";

	public const CMD_RAIDBLOCK_EDIT = "raidblock add/remove";

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

	/** Load all blocks from the database into memory */
	public function loadBlocks(): void {
		$this->db->table(self::DB_TABLE)
			->whereNull("expiration")
			->orWhere("expiration", ">", time())
			->asObj(RaidBlock::class)
			->each(function (RaidBlock $block) {
				$this->blocks[$block->player] ??= [];
				$this->blocks[$block->player][$block->blocked_from] = $block;
			});
	}

	/** Remove all temporary bans that are expired from memory */
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

	/** Check if a player is blocked from a certain raid activity */
	public function isBlocked(string $player, string $activity): bool {
		$player = $this->altsController->getMainOf($player);
		$this->expireBans();
		return isset($this->blocks[ucfirst(strtolower($player))][$activity]);
	}

	/** Get a descriptive noun for a raid block key */
	public function blockToString(string $block): string {
		$mapping = [
			static::AUCTION_BIDS => "bidding in auctions",
			static::JOIN_RAIDS => "joining raids",
			static::POINTS_GAIN => "gaining raid points",
		];
		return $mapping[$block] ?? "an unknown activity";
	}

	/**
	 * Block a player from attending aspects of the raids
	 *
	 * If &lt;duration&gt; is given, then the block is only temporary.
	 * Permanent blocks can only be lifted manually.
	 */
	#[NCA\HandlesCommand(self::CMD_RAIDBLOCK_EDIT)]
	public function raidBlockAddCommand(
		CmdContext $context,
		#[NCA\StrChoice("points", "join", "bid")]
		string $blockFrom,
		PCharacter $character,
		?PDuration $duration,
		string $reason
	): void {
		$character = $character();
		if ($this->isBlocked($character, $blockFrom)) {
			$context->reply("<highlight>{$character}<end> is already blocked on <highlight>{$blockFrom}<end>.");
			return;
		}
		if (null === $this->chatBot->getUid($character)) {
			$context->reply("<highlight>{$character}<end> doesn't exist.");
		}
		$character = $this->altsController->getMainOf($character);
		if (isset($duration)) {
			$duration = $duration->toSecs();
			$expiration = time() + $duration;
		}
		$block = new RaidBlock();
		$block->blocked_by = $context->char->name;
		$block->blocked_from = $blockFrom;
		$block->expiration = $expiration??null;
		$block->player = $character;
		$block->reason = $reason;
		$block->time = time();
		$this->blocks[$character] ??= [];
		$this->blocks[$character][$blockFrom] = $block;
		$this->db->insert(self::DB_TABLE, $block, null);
		$msg = "<highlight>{$character}<end> is now blocked from <highlight>".
			$this->blockToString($blockFrom) . "<end> ";
		if (is_int($duration) && $duration > 0) {
			$msg .= "for <highlight>" . $this->util->unixtimeToReadable($duration) . "<end>.";
		} else {
			$msg .= "until someone removes the block.";
		}
		$context->reply($msg);
	}

	/** Check if you are blocked from some raid aspects and for how long */
	#[NCA\HandlesCommand("raidblock")]
	public function raidBlockCommand(CmdContext $context): void {
		$this->expireBans();
		$player = $this->altsController->getMainOf($context->char->name);
		if (!isset($this->blocks[$player])) {
			$context->reply("You are currently not blocked from any part of raiding.");
			return;
		}
		$blocks = $this->blocks[$player];
		$msg = "You are blocked from the following raid part" . ((count($blocks) > 1) ? "s" : "") . ":";
		foreach ($blocks as $name => $block) {
			$msg .= "\n<tab><highlight>" . $this->blockToString($name) . "<end>: ";
			if (isset($block->expiration) && $block->expiration > 0) {
				$msg .= "until " . $this->util->date($block->expiration);
			} else {
				$msg .= "until block is lifted";
			}
		}
		$context->reply($msg);
	}

	/** Check another character's blocks */
	#[NCA\HandlesCommand(self::CMD_RAIDBLOCK_EDIT)]
	public function raidBlockShowCommand(CmdContext $context, PCharacter $char): void {
		$player = $char();
		$player = $this->altsController->getMainOf($player);
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
			if (isset($block->expiration) && $block->expiration > 0) {
				$blob .= "until " . $this->util->date($block->expiration);
			} else {
				$blob .= "until block is lifted";
			}
			$blob .= " (by <highlight>{$block->blocked_by}<end>: {$block->reason})";
		}
		$context->reply($this->text->makeBlob($msg, $blob));
	}

	/** Lift all or just one raid block from a character */
	#[NCA\HandlesCommand(self::CMD_RAIDBLOCK_EDIT)]
	public function raidBlockLiftCommand(
		CmdContext $context,
		PRemove $action,
		PCharacter $char,
		#[NCA\StrChoice("points", "join", "bid")]
		?string $blockFrom
	): void {
		$player = $char();
		$player = $this->altsController->getMainOf($player);
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
