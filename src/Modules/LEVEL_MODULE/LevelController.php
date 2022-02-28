<?php declare(strict_types=1);

namespace Nadybot\Modules\LEVEL_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
};

/**
 * @author Tyrence (RK2)
 * @author Derroylo (RK2)
 * @author Legendadv (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "level",
		accessLevel: "guest",
		description: "Show level ranges",
		alias: ['pvp', 'lvl'],
	),
	NCA\DefineCommand(
		command: "missions",
		accessLevel: "guest",
		description: "Shows what ql missions a character can roll",
		alias: "mission"
	),
	NCA\DefineCommand(
		command: "xp",
		accessLevel: "guest",
		description: "Show xp/sk needed for specified level(s)",
		alias: "sk"
	)
]
class LevelController extends ModuleInstance {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/levels.csv");
	}

	/** Show information and level ranges for a character level */
	#[NCA\HandlesCommand("level")]
	public function levelCommand(CmdContext $context, int $level): void {
		if (($row = $this->getLevelInfo($level)) === null) {
			$msg = "Level must be between <highlight>1<end> and <highlight>220<end>.";
			$context->reply($msg);
			return;
		}
		$msg = "<white>L $row->level: Team {$row->teamMin}-{$row->teamMax}<end>".
			"<highlight> | <end>".
			"<cyan>PvP {$row->pvpMin}-{$row->pvpMax}<end>".
			"<highlight> | <end>".
			"<orange>Missions {$row->missions}<end>".
			"<highlight> | <end>".
			"<blue>{$row->tokens} token(s)<end>";

		$context->reply($msg);
	}

	/** See which levels can roll a mission in the given QL */
	#[NCA\HandlesCommand("missions")]
	public function missionsCommand(CmdContext $context, int $missionQL): void {
		if ($missionQL <= 0 || $missionQL > 250) {
			$msg = "Missions are only available between QL1 and QL250.";
			$context->reply($msg);
			return;
		}
		$msg = "QL{$missionQL} missions can be rolled from these levels:";

		foreach ($this->findAllLevels() as $row) {
			$array = explode(",", $row->missions);
			if (in_array($missionQL, $array)) {
				$msg .= " " . $row->level;
			}
		}
		$context->reply($msg);
	}

	/** See needed XP to level up for a single level */
	#[NCA\HandlesCommand("xp")]
	#[NCA\Help\Group("xp")]
	public function xpSingleCommand(CmdContext $context, int $level): void {
		if (($row = $this->getLevelInfo($level)) === null) {
			$msg = "Level must be between 1 and 219.";
			$context->reply($msg);
			return;
		}
		$xp = "XP";
		if ($level >= 200) {
			$xp = "SK";
		}
		$msg = "At level <highlight>{$row->level}<end> you need <highlight>".number_format($row->xpsk)."<end> ${xp} to level up.";
		$context->reply($msg);
	}

	/** See how much XP is needed from one level to another */
	#[NCA\HandlesCommand("xp")]
	#[NCA\Help\Group("xp")]
	public function xpDoubleCommand(CmdContext $context, int $startLevel, int $endLevel): void {
		if ($startLevel < 1 || $startLevel > 220 || $endLevel < 1 || $endLevel > 220) {
			$msg = "Level must be between 1 and 220.";
			$context->reply($msg);
			return;
		}
		if ($startLevel >= $endLevel) {
			$msg = "The start level must be lower than the end level.";
			$context->reply($msg);
			return;
		}
		/** @var Collection<Level> */
		$data = $this->db->table("levels")
			->where("level", ">=", $startLevel)
			->where("level", "<", $endLevel)
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
			$msg = "From the beginning of level <highlight>$startLevel<end> ".
				"you need <highlight>".number_format($xp)."<end> XP ".
				"and <highlight>".number_format($sk)."<end> SK ".
				"to reach level <highlight>$endLevel<end>.";
		} elseif ($sk > 0) {
			$msg = "From the beginning of level <highlight>$startLevel<end> ".
				"you need <highlight>".number_format($sk)."<end> SK ".
				"to reach level <highlight>$endLevel<end>.";
		} elseif ($xp > 0) {
			$msg = "From the beginning of level <highlight>$startLevel<end> ".
				"you need <highlight>".number_format($xp)."<end> XP ".
				"to reach level <highlight>$endLevel<end>.";
		} else {
			$msg = "You somehow managed to pass illegal parameters.";
		}
		$context->reply($msg);
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
