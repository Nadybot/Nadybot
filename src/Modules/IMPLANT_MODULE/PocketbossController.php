<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	ParamClass\PWord,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Pocketboss"),
	NCA\DefineCommand(
		command: "pocketboss",
		accessLevel: "guest",
		description: "Shows what symbiants a pocketboss drops",
		alias: "pb"
	),
	NCA\DefineCommand(
		command: "symbiant",
		accessLevel: "guest",
		description: "Shows which pocketbosses drop a symbiant",
		alias: "symb"
	)
]
class PocketbossController extends ModuleInstance {
	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/pocketboss.csv");
	}

	/** Show a list of Symbiants that a Pocketboss drops */
	#[NCA\HandlesCommand("pocketboss")]
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
	 * Show a list of symbiants and which pocketboss drops it
	 *
	 * The arguments are either a slot name (rhand), a type (artillery) or a line (living).
	 * You can use 1, 2 or 3 of these arguments or their abbreviations in any order to search.
	 */
	#[NCA\HandlesCommand("symbiant")]
	#[NCA\Help\Example("<symbol>symbiant brain alpha arti")]
	#[NCA\Help\Example("<symbol>symbiant alpha rhand")]
	#[NCA\Help\Example("<symbol>symbiant inf")]
	#[NCA\Help\Example("<symbol>symbiant inf living")]
	#[NCA\Help\Example("<symbol>symbiant beta control")]
	#[NCA\Help\Epilogue(
		"<header2>Slot names<end>\n\n".
		"<tab>- eye\n".
		"<tab>- head\n".
		"<tab>- ear\n".
		"<tab>- rarm\n".
		"<tab>- chest\n".
		"<tab>- larm\n".
		"<tab>- rwrist\n".
		"<tab>- waist\n".
		"<tab>- lwrist\n".
		"<tab>- rhand\n".
		"<tab>- legs\n".
		"<tab>- lhand\n".
		"<tab>- feet\n\n".
		"<header2>Types<end>\n\n".
		"<tab>- support\n".
		"<tab>- control\n".
		"<tab>- infantry\n".
		"<tab>- artillery\n".
		"<tab>- extermination\n\n".
		"<header2>Lines<end>\n\n".
		"<tab>- Alert\n".
		"<tab>- Cognizant\n".
		"<tab>- Vital\n".
		"<tab>- Excited\n".
		"<tab>- Effective\n".
		"<tab>- Vigorous\n".
		"<tab>- Persisting\n".
		"<tab>- Living\n".
		"<tab>- Growing\n".
		"<tab>- Enduring\n".
		"<tab>- Awakened\n".
		"<tab>- Active\n".
		"<tab>- Working\n".
		"<tab>- Surviving\n".
		"<tab>- Running\n".
		"<tab>- Residing\n".
		"<tab>- Prevailing\n".
		"<tab>- Operative\n".
		"<tab>- Breathing\n".
		"<tab>- Vibrating\n".
		"<tab>- Moving\n".
		"<tab>- Animated\n".
		"<tab>- Lulled\n".
		"<tab>- Sluggish\n".
		"<tab>- Sleeping\n".
		"<tab>- Neglectful\n".
		"<tab>- Lethargic\n"
	)]
	public function symbiantCommand(
		CmdContext $context,
		PWord $arg1,
		?PWord $arg2,
		?PWord $arg3
	): void {
		$args = $context->args;
		/** @var string[] */
		$args = array_filter([$args[1], $args[2]??null, $args[3]??null]);
		$paramCount = count($args);

		$slot = '%';
		$symbtype = '%';
		$line = '%';

		/** @var string[] */
		$lines = $this->db->table("pocketboss")->select("line")->distinct()
			->pluckAs("line", "string")->toArray();

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
