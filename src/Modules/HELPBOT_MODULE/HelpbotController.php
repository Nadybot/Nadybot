<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use ParseError;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 * @author Neksus (RK2)
 * @author Mdkdoc420 (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/Dyna"),
	NCA\DefineCommand(
		command: "dyna",
		accessLevel: "all",
		description: "Search for RK Dynabosses",
	),
	NCA\DefineCommand(
		command: "oe",
		accessLevel: "all",
		description: "Over-equipped calculation",
	),
	NCA\DefineCommand(
		command: "calc",
		accessLevel: "all",
		description: "Calculator",
	)
]
class HelpbotController extends ModuleInstance {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public PlayfieldController $pfController;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/dynadb.csv');
	}

	/** Return a list of dyna camps within -25 and +25 of QL */
	#[NCA\HandlesCommand("dyna")]
	public function dynaLevelCommand(CmdContext $context, int $ql): void {
		$range1 = (int)floor($ql - $ql / 10);
		$range2 = (int)ceil($ql + $ql / 10);
		/** @var Collection<DynaDBSearch> */
		$data = $this->db->table("dynadb AS d")
			->where("max_ql", ">=", $range1)
			->where("min_ql", "<=", $range2)
			->orderBy("min_ql")
			->asObj(DynaDBSearch::class)
			->each(function (DynaDBSearch $ql): void {
				$ql->pf = $this->pfController->getPlayfieldById($ql->playfield_id);
			});
		if ($data->isEmpty()) {
			$context->reply(
				"No dynacamps found between level <highlight>{$range1}<end> ".
				"and <highlight>{$range2}<end>."
			);
			return;
		}

		$blob = "Results of Dynacamps level <highlight>{$range1}<end>-<highlight>{$range2}<end>\n\n";

		$blob .= $this->formatResults($data);

		$msg = $this->text->makeBlob("Dynacamps (" . $data->count() . ")", $blob);
		$context->reply($msg);
	}

	/**
	 * Return a list of all dyna camps that either
	 * consist of mobs of type &lt;search&gt; or
	 * are in the playfield &lt;search&gt;
	 */
	#[NCA\HandlesCommand("dyna")]
	public function dynaNameCommand(CmdContext $context, string $search): void {
		$dbSearch = str_replace(" ", "%", $search);
		$playfields = $this->pfController->searchPlayfieldsByName("%{$dbSearch}%");
		$data = $this->db->table("dynadb AS d")
			->whereIn("playfield_id", $playfields->pluck("id")->toArray())
			->orWhereIlike("mob", "%{$dbSearch}%")
			->asObj(DynaDBSearch::class)
			->each(function (DynaDBSearch $search): void {
				$search->pf = $this->pfController->getPlayfieldById($search->playfield_id);
			});
		$count = count($data);

		if (!$count) {
			$context->reply("No dyna names or locations matched <highlight>{$search}<end>.");
			return;
		}
		$blob = "Results of Dynacamp Search for <highlight>{$search}<end>\n\n";

		$blob .= $this->formatResults($data);

		$msg = $this->text->makeBlob("Dynacamps ($count)", $blob);
		$context->reply($msg);
	}

	/**
	 * Format the dynacamp results as a blob for a popup
	 * @param Collection<DynaDBSearch> $data
	 */
	private function formatResults(Collection $data): string {
		$blob = '';
		/** @var Collection<string,Collection<DynaDBSearch>> */
		$data = $data->filter(fn (DynaDBSearch $search): bool => isset($search->pf))
			->groupBy("pf.long_name")
			->sortKeys();

		foreach ($data as $pfName => $rows) {
			$blob .= "\n<pagebreak><header2>{$pfName}<end>\n";
			foreach ($rows as $row) {
				$coordLink = $this->text->makeChatcmd(
					"{$row->x_coord}x{$row->y_coord}",
					"/waypoint {$row->x_coord} {$row->y_coord} {$row->playfield_id}"
				);
				$range = "{$row->min_ql}-{$row->max_ql}";
				if (strlen($range) < 7) {
					$range = "<black>" . str_repeat("_", 7 - strlen($range)) . "<end>{$range}";
				}
				$blob .= "<tab>{$range}: <highlight>{$row->mob}<end> at {$coordLink}\n";
			}
		}
		return trim($blob);
	}

	/** See the over-equip ranges for a skill requirement */
	#[NCA\HandlesCommand("oe")]
	#[NCA\Help\Epilogue(
		"For example: you have 112 Pistol and wanna check what the max Pistol requirement is you can wear without going oe:\n".
		"<tab><highlight><symbol>oe 112<end>\n\n".
		"Another Example: your favorite Weapon needs 1200 Pistol and you wanna know how much pistol you need to not OE do:\n".
		"<tab><highlight><symbol>oe 1200<end>"
	)]
	public function oeCommand(CmdContext $context, int $skillRequirement): void {
		$oe100 = (int)floor($skillRequirement / 0.8);
		$lowOE100 = (int)floor($skillRequirement * 0.8);
		$oe75 = (int)floor($skillRequirement / 0.6);
		$lowOE75 = (int)floor($skillRequirement * 0.6);
		$oe50 = (int)floor($skillRequirement / 0.4);
		$lowOE50 = (int)floor($skillRequirement * 0.4);
		$oe25 = (int)floor($skillRequirement / 0.2);
		$lowOE25 = (int)floor($skillRequirement * 0.2);

		$blob = "With a skill requirement of <highlight>{$skillRequirement}<end>, you will be\n".
			"Out of OE: <highlight>$lowOE100<end> or higher\n".
			"75%: <highlight>$lowOE75<end> - <highlight>" .($lowOE100 - 1). "<end>\n".
			"50%: <highlight>" .($lowOE50 + 1). "<end> - <highlight>" .($lowOE75 - 1). "<end>\n".
			"25%: <highlight>" .($lowOE25 + 1). "<end> - <highlight>$lowOE50<end>\n".
			"<black>0<end>0%: <highlight>$lowOE25<end> or lower\n\n".
			"With a personal skill of <highlight>$skillRequirement<end>, you can use up to and be\n".
			"Out of OE: <highlight>$oe100<end> or lower\n".
			"75%: <highlight>" .($oe100 + 1). "<end> - <highlight>$oe75<end>\n".
			"50%: <highlight>" .($oe75 + 1). "<end> - <highlight>" .($oe50 - 1). "<end>\n".
			"25%: <highlight>$oe50<end> - <highlight>" .($oe25 - 1). "<end>\n".
			"<black>0<end>0%: <highlight>$oe25<end> or higher\n\n".
			"WARNING: May be plus/minus 1 point!";

		$msg = $this->text->blobWrap(
			"<highlight>{$lowOE100}<end> - {$skillRequirement} - <highlight>{$oe100}<end> ",
			$this->text->makeBlob('More info', $blob, 'Over-equipped Calculation')
		);

		$context->reply($msg);
	}

	/** Use a calculator */
	#[NCA\HandlesCommand("calc")]
	#[NCA\Help\Example("<symbol>calc 1+1")]
	#[NCA\Help\Example("<symbol>calc 2^16")]
	public function calcCommand(CmdContext $context, string $formula): void {
		$calc = strtolower($formula);

		// check if the calc string includes not allowed chars
		$numValidChars = strspn($calc, "0123456789.+^-*%()/\\ ");

		if ($numValidChars !== strlen($calc)) {
			$context->reply("Cannot compute.");
			return;
		}
		$calc = str_replace("^", "**", $calc);
		try {
			$result = 0;
			$calc = "\$result = {$calc};";
			eval($calc);

			$result = preg_replace("/\.?0+$/", "", number_format(round($result, 4), 4));
			$result = str_replace(",", "<end>,<highlight>", $result);
		} catch (ParseError $e) {
			$context->reply("Cannot compute.");
			return;
		}
		preg_match_all("{(\d*\.?\d+|[+%()/^-]|\*+)}", $formula, $matches);
		$expression = join(" ", $matches[1]);
		$expression = str_replace(["* *", "( ", " )", "*"], ["^", "(", ")", "×"], $expression);
		$expression = preg_replace("/(\d+)/", "<cyan>$1<end>", $expression);

		$msg ="{$expression} = <highlight>{$result}<end>";
		$context->reply($msg);
	}
}
