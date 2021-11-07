<?php declare(strict_types=1);

namespace Nadybot\Modules\LEVEL_MODULE;

use Nadybot\Core\CommandAlias;
use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;

/**
 * @author Tyrence (RK2)
 * @author Derroylo (RK2)
 * @author Legendadv (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'level',
 *		accessLevel = 'all',
 *		description = 'Show level ranges',
 *		help        = 'level.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'missions',
 *		accessLevel = 'all',
 *		description = 'Shows what ql missions a character can roll',
 *		help        = 'missions.txt',
 *		alias       = 'mission'
 *	)
 *	@DefineCommand(
 *		command     = 'xp',
 *		accessLevel = 'all',
 *		description = 'Show xp/sk needed for specified level(s)',
 *		help        = 'xp.txt',
 *		alias       = 'sk'
 *	)
 */
class LevelController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public CommandAlias $commandAlias;

	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/levels.csv");

		$this->commandAlias->register($this->moduleName, "level", "pvp");
		$this->commandAlias->register($this->moduleName, "level", "lvl");
	}

	/**
	 * @HandlesCommand("level")
	 * @Matches("/^level (\d+)$/i")
	 */
	public function levelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$level = (int)$args[1];
		if (($row = $this->getLevelInfo($level)) === null) {
			$msg = "Level must be between <highlight>1<end> and <highlight>220<end>.";
			$sendto->reply($msg);
			return;
		}
		$msg = "Level must be between <highlight>1<end> and <highlight>220<end>.";
		$msg = "<white>L $row->level: Team {$row->teamMin}-{$row->teamMax}<end>".
			"<highlight> | <end>".
			"<cyan>PvP {$row->pvpMin}-{$row->pvpMax}<end>".
			"<highlight> | <end>".
			"<orange>Missions {$row->missions}<end>".
			"<highlight> | <end>".
			"<blue>{$row->tokens} token(s)<end>";

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("missions")
	 * @Matches("/^missions (\d+)$/i")
	 */
	public function missionsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$missionQl = (int)$args[1];

		if ($missionQl <= 0 || $missionQl > 250) {
			$msg = "Missions are only available between QL1 and QL250.";
			$sendto->reply($msg);
			return;
		}
		$msg = "QL{$missionQl} missions can be rolled from these levels:";

		foreach ($this->findAllLevels() as $row) {
			$array = explode(",", $row->missions);
			if (in_array($missionQl, $array)) {
				$msg .= " " . $row->level;
			}
		}
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("xp")
	 * @Matches("/^xp (\d+)$/i")
	 */
	public function xpSingleCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$level = (int)$args[1];
		if (($row = $this->getLevelInfo($level)) === null) {
			$msg = "Level must be between 1 and 219.";
			$sendto->reply($msg);
			return;
		}
		$xp = "XP";
		if ($level >= 200) {
			$xp = "SK";
		}
		$msg = "At level <highlight>{$row->level}<end> you need <highlight>".number_format($row->xpsk)."<end> ${xp} to level up.";
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("xp")
	 * @Matches("/^xp (\d+) (\d+)$/i")
	 */
	public function xpDoubleCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$minLevel = (int)$args[1];
		$maxLevel = (int)$args[2];
		if ($minLevel < 1 || $minLevel > 220 || $maxLevel < 1 || $maxLevel > 220) {
			$msg = "Level must be between 1 and 220.";
			$sendto->reply($msg);
			return;
		}
		if ($minLevel >= $maxLevel) {
			$msg = "The start level must be lower than the end level.";
			$sendto->reply($msg);
			return;
		}
		/** @var Collection<Level> */
		$data = $this->db->table("levels")
			->where("level", ">=", $minLevel)
			->where("level", "<", $maxLevel)
			->asObj(Level::class);
		$xp = 0;
		$sk = 0;
		foreach ($data as $row) {
			if ($row->level < 200) {
				$xp += $row->xpsk;
			} else {
				$sk += $row->xpsk;
			}
		}
		if ($sk > 0 && $xp > 0) {
			$msg = "From the beginning of level <highlight>$minLevel<end> ".
				"you need <highlight>".number_format($xp)."<end> XP ".
				"and <highlight>".number_format($sk)."<end> SK ".
				"to reach level <highlight>$maxLevel<end>.";
		} elseif ($sk > 0) {
			$msg = "From the beginning of level <highlight>$minLevel<end> ".
				"you need <highlight>".number_format($sk)."<end> SK ".
				"to reach level <highlight>$maxLevel<end>.";
		} elseif ($xp > 0) {
			$msg = "From the beginning of level <highlight>$minLevel<end> ".
				"you need <highlight>".number_format($xp)."<end> XP ".
				"to reach level <highlight>$maxLevel<end>.";
		} else {
			$msg = "You somehow managed to pass illegal parameters.";
		}
		$sendto->reply($msg);
	}

	public function getLevelInfo(int $level): ?Level {
		return $this->db->table("levels")
			->where("level", $level)
			->asObj(Level::class)
			->first();
	}

	/**
	 * @return Level[]
	 */
	public function findAllLevels(): array {
		return $this->db->table("levels")
			->orderBy("level")
			->asObj(Level::class)
			->toArray();
	}
}
