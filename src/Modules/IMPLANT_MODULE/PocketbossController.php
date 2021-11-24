<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\CmdContext;
use Nadybot\Core\DB;
use Nadybot\Core\ParamClass\PWord;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'pocketboss',
 *		accessLevel = 'all',
 *		description = 'Shows what symbiants a pocketboss drops',
 *		help        = 'pocketboss.txt',
 *		alias       = 'pb'
 *	)
 *	@DefineCommand(
 *		command     = 'symbiant',
 *		accessLevel = 'all',
 *		description = 'Shows which pocketbosses drop a symbiant',
 *		help        = 'symbiant.txt',
 *		alias       = 'symb'
 *	)
 */
class PocketbossController {
	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public DB $db;

	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadMigrations($this->moduleName, __DIR__ . "/Migrations/Pocketboss");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/pocketboss.csv");
	}

	/**
	 * @HandlesCommand("pocketboss")
	 */
	public function pocketbossCommand(CmdContext $context, string $search): void {
		$data = $this->pbSearchResults($search);
		$numrows = count($data);
		$blob = "";
		if ($numrows === 0) {
			$msg = "Could not find any pocket bosses that matched your search criteria.";
		} elseif ($numrows === 1) {
			$name = $data[0]->pb;
			$blob .= $this->singlePbBlob($name);
			$msg = $this->text->makeBlob("Remains of $name", $blob);
		} else {
			$blob = '';
			foreach ($data as $row) {
				$pbLink = $this->text->makeChatcmd($row->pb, "/tell <myname> pocketboss $row->pb");
				$blob .= $pbLink . "\n";
			}
			$msg = $this->text->makeBlob("Search results for $search ($numrows)", $blob);
		}
		$context->reply($msg);
	}

	public function singlePbBlob(string $name): string {
		/** @var Pocketboss[] */
		$data = $this->db->table("pocketboss")
			->where("pb", $name)
			->orderBy("ql")
			->asObj(Pocketboss::class)
			->toArray();
		if (empty($data)) {
			return '';
		}
		$symbs = '';
		foreach ($data as $symb) {
			if (in_array($symb->line, ["Alpha", "Beta"])) {
				$name = "Xan $symb->slot Symbiant, $symb->type Unit $symb->line";
			} else {
				$name = "$symb->line $symb->slot Symbiant, $symb->type Unit Aban";
			}
			$symbs .= $this->text->makeItem($symb->itemid, $symb->itemid, $symb->ql, $name) . " ($symb->ql)\n";
		}

		$blob = "Location: <highlight>$symb->pb_location, $symb->bp_location<end>\n";
		$blob .= "Found on: <highlight>$symb->bp_mob, Level $symb->bp_lvl<end>\n\n";
		$blob .= $symbs;

		return $blob;
	}

	/**
	 * @return Pocketboss[]
	 */
	public function pbSearchResults(string $search): array {
		$row = $this->db->table("pocketboss")
			->whereIlike("pb", $search)
			->orderBy("pb")
			->limit(1)
			->asObj(Pocketboss::class)
			->first();
		if ($row !== null) {
			return [$row];
		}

		$query = $this->db->table("pocketboss")
			->orderBy("pb");
		$tmp = explode(" ", $search);
		$this->db->addWhereFromParams($query, $tmp, "pb");

		$pb =$query->asObj(Pocketboss::class);
		return $pb->groupBy("pb")
			->map(fn(Collection $col): Pocketboss => $col->first())
			->values()
			->toArray();
	}

	/**
	 * @HandlesCommand("symbiant")
	 */
	public function symbiantCommand(
		CmdContext $context,
		PWord $arg1,
		?PWord $arg2,
		?PWord $arg3
	): void {
		$args = $context->args;
		$args = array_filter([$args[1], $args[2]??null, $args[3]??null]);
		$paramCount = count($args);

		$slot = '%';
		$symbtype = '%';
		$line = '%';

		$lines = $this->db->table("pocketboss")->select("line")->distinct()
			->asObj()->pluck("line")->toArray();

		for ($i = 0; $i < $paramCount; $i++) {
			switch (strtolower($args[$i])) {
				case "eye":
				case "ocular":
					$impDesignSlot = 'eye';
					$slot = "Ocular";
					break;
				case "brain":
				case "head":
					$impDesignSlot = 'head';
					$slot = "Brain";
					break;
				case "ear":
					$impDesignSlot = 'ear';
					$slot = "Ear";
					break;
				case "rarm":
					$impDesignSlot = 'rarm';
					$slot = "Right Arm";
					break;
				case "chest":
					$impDesignSlot = 'chest';
					$slot = "Chest";
					break;
				case "larm":
					$impDesignSlot = 'larm';
					$slot = "Left Arm";
					break;
				case "rwrist":
					$impDesignSlot = 'rwrist';
					$slot = "Right Wrist";
					break;
				case "waist":
					$impDesignSlot = 'waist';
					$slot = "Waist";
					break;
				case "lwrist":
					$impDesignSlot = 'lwrist';
					$slot = "Left Wrist";
					break;
				case "rhand":
					$impDesignSlot = 'rhand';
					$slot = "Right Hand";
					break;
				case "leg":
				case "legs":
				case "thigh":
					$impDesignSlot = 'legs';
					$slot = "Thigh";
					break;
				case "lhand":
					$impDesignSlot = 'lhand';
					$slot = "Left Hand";
					break;
				case "feet":
					$impDesignSlot = 'feet';
					$slot = "Feet";
					break;
				default:
					// check if it's a line
					foreach ($lines as $l) {
						if (strtolower($l) === strtolower($args[$i])) {
							$line = $l;
							break 2;
						}
					}

					// check if it's a type
					if (preg_match("/^art/i", $args[$i])) {
						$symbtype = "Artillery";
						break;
					} elseif (preg_match("/^sup/i", $args[$i])) {
						$symbtype = "Support";
						break;
					} elseif (preg_match("/^inf/i", $args[$i])) {
						$symbtype = "Infantry";
						break;
					} elseif (preg_match("/^ext/i", $args[$i])) {
						$symbtype = "Extermination";
						break;
					} elseif (preg_match("/^control/i", $args[$i])) {
						$symbtype = "Control";
						break;
					}

					// check if it's a line, but be less strict this time
					$matchingLines = array_filter(
						$lines,
						function (string $line) use ($args, $i): bool {
							return strncasecmp($line, $args[$i], strlen($args[$i])) === 0;
						}
					);
					if (count($matchingLines) === 1) {
						$line = array_shift($matchingLines);
						break;
					}
					$context->reply(
						"I cannot find any symbiant line, location or type '<highlight>{$args[$i]}<end>'."
					);
					return;
			}
		}

		$query = $this->db->table("pocketboss")
			->whereIlike("slot", $slot)
			->whereIlike("type", $symbtype)
			->whereIlike("line", $line)
			->orderByDesc("ql");
		$query->orderByRaw($query->grammar->wrap("line") . " = ? desc")
			->addBinding("Alpha")
			->orderByRaw($query->grammar->wrap("line") . " = ? desc")
			->addBinding("Beta")
			->orderBy("type");
		/** @var Pocketboss[] */
		$data = $query->asObj(Pocketboss::class)->toArray();
		$numrows = count($data);
		if ($numrows === 0) {
			$msg = "Could not find any symbiants that matched your search criteria.";
			$context->reply($msg);
			return;
		}
		$implantDesignerLink = $this->text->makeChatcmd("implant designer", "/tell <myname> implantdesigner");
		$blob = "Click '[add]' to add symbiant to $implantDesignerLink.\n\n";
		foreach ($data as $row) {
			if (in_array($row->line, ["Alpha", "Beta"])) {
				$name = "Xan $row->slot Symbiant, $row->type Unit $row->line";
			} else {
				$name = "$row->line $row->slot Symbiant, $row->type Unit Aban";
			}
			$blob .= "<pagebreak>" . $this->text->makeItem($row->itemid, $row->itemid, $row->ql, $name)." ($row->ql)";
			if (isset($impDesignSlot) ) {
				$impDesignerAddLink = $this->text->makeChatcmd("add", "/tell <myname> implantdesigner $impDesignSlot symb $name");
				$blob .= " [$impDesignerAddLink]";
			}
			$blob .= "\n";
			$blob .= "Found on " . $this->text->makeChatcmd($row->pb, "/tell <myname> pb $row->pb");
			$blob .= "\n\n";
		}
		$msg = $this->text->makeBlob("Symbiant Search Results ($numrows)", $blob);
		$context->reply($msg);
	}
}
