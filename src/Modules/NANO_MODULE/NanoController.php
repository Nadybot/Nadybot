<?php

declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	CmdContext,
	DB,
	SettingManager,
	Text,
	Util,
};

/**
 * @author Nadyita (RK5)
 * @author Tyrence (RK2)
 * @author Healnjoo (RK2)
 * @author Mdkdoc420 (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "nano",
		accessLevel: "all",
		description: "Searches for a nano and tells you were to get it",
		help: "nano.txt"
	),
	NCA\DefineCommand(
		command: "nanolines",
		accessLevel: "all",
		description: "Shows nanos based on nanoline",
		help: "nanolines.txt",
		alias: "nl"
	),
	NCA\DefineCommand(
		command: "nanolinesfroob",
		accessLevel: "all",
		description: "Shows nanos for froobs based on nanoline ",
		help: "nanolinesfroob.txt",
		alias: "nlf"
	),
	NCA\DefineCommand(
		command: "nanoloc",
		accessLevel: "all",
		description: "Browse nanos by location",
		help: "nano.txt"
	)
]
class NanoController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	/** @var array<int,Nanoline> */
	public array $nanolines = [];
	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/nanos.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/nano_lines.csv");

		$this->settingManager->add(
			module: $this->moduleName,
			name: 'maxnano',
			description: 'Number of Nanos shown on the list',
			mode: 'edit',
			type: "number",
			value: '40',
			options: '30;40;50;60',
			intoptions: "",
			accessLevel: "mod"
		);
		$this->settingManager->add(
			module: $this->moduleName,
			name: "shownanolineicons",
			description: "Show icons for the nanolines",
			mode: "edit",
			type: "options",
			value: "0",
			options: "true;false",
			intoptions: "1;0"
		);
		$this->nanolines = $this->db->table("nano_lines")
			->asObj(Nanoline::class)
			->keyBy("strain_id")
			->toArray();
	}

	#[NCA\HandlesCommand("nano")]
	public function nanoCommand(CmdContext $context, string $search): void {
		$search = htmlspecialchars_decode($search);
		$query = $this->db->table("nanos")
			->orderBy("strain")
			->orderBy("sub_strain")
			->orderBy("sort_order")
			->limit($this->settingManager->getInt("maxnano")??40);
		$tmp = explode(" ", $search);
		$this->db->addWhereFromParams($query, $tmp, "nano_name");

		/** @var Collection<Nano> */
		$data = $query->asObj(Nano::class);

		$count = $data->count();
		if ($count === 0) {
			$msg = "No nanos found.";
			$context->reply($msg);
			return;
		}
		$blob = '';
		$currentNanoline = -1;
		$currentSubstrain = null;
		foreach ($data as $row) {
			$defColor = $this->settingManager->getString('default_window_color');
			if ($currentNanoline !== $row->strain || $currentSubstrain !== $row->sub_strain) {
				if (!empty($row->strain)) {
					$nanolineLink = $this->text->makeChatcmd("see all nanos", "/tell <myname> nanolines $row->strain");
					$blob .= "\n<header2>$row->school {$defColor}&gt;<end> $row->strain";
					if ($row->sub_strain) {
						$blob .= " {$defColor}&gt;<end> $row->sub_strain";
					}
					$blob .= "{$defColor} - [$nanolineLink]<end><end>\n";
				} else {
					$blob .= "\n<header2>Unknown/General<end>\n";
				}
				$currentNanoline = $row->strain;
				$currentSubstrain = $row->sub_strain;
			}
			$nanoLink = $this->makeNanoLink($row);
			$crystalLink = isset($row->crystal_id)
				? $this->text->makeItem($row->crystal_id, $row->crystal_id, $row->ql, "Crystal")
				: "Crystal";
			$info = "QL" . $this->text->alignNumber($row->ql, 3) . " [$crystalLink] $nanoLink ($row->location)";
			$info .= " - <highlight>" . implode("<end>, <highlight>", explode(":", $row->professions)) . "<end>";
			$blob .= "<tab>$info\n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("Nano Search Results ($count)", $blob);
		if (count($data) === 1) {
			$msg = $this->text->blobWrap(
				($info??"") . " [",
				$this->text->makeBlob("details", $blob),
				"]"
			);
		}

		$context->reply($msg);
	}

	#[NCA\HandlesCommand("nanolines")]
	public function nanolinesListProfsCommand(CmdContext $context): void {
		$this->listNanolineProfs($context, false);
	}

	#[NCA\HandlesCommand("nanolinesfroob")]
	public function nanolinesFroobListProfsCommand(CmdContext $context): void {
		$this->listNanolineProfs($context, true);
	}

	/**
	 * List all professions for which nanolines exist
	 * @param CmdContext $context Where to send the reply to
	 * @param bool $froobOnly Is set, only show professions a froob can play
	 * @return void
	 */
	public function listNanolineProfs(CmdContext $context, bool $froobOnly): void {
		$query = $this->db->table("nanos")
			->where("professions", "not like", "%:%")
			->orderBy("professions")
			->select("professions")->distinct();
		if ($froobOnly) {
			$query->whereNotIn("professions", ["Keeper", "Shade"]);
		}
		$data = $query->asObj();

		$blob = "<header2>Choose a profession<end>\n";
		$command = $froobOnly ? "nanolinesfroob" : "nanolines";
		foreach ($data as $row) {
			$blob .= "<tab>" . $this->text->makeChatcmd($row->professions, "/tell <myname> $command $row->professions");
			$blob .= "\n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob('Nanolines', $blob);

		$context->reply($msg);
	}

	#[NCA\HandlesCommand("nanolines")]
	public function nanolinesListCommand(CmdContext $context, string $arg): void {
		$this->listNanolines($context, false, $arg);
	}

	#[NCA\HandlesCommand("nanolinesfroob")]
	public function nanolinesFroobListCommand(CmdContext $context, string $arg): void {
		$this->listNanolines($context, true, $arg);
	}

	public function listNanolines(CmdContext $context, bool $froobOnly, string $arg): void {
		$arg = html_entity_decode($arg);
		$nanoArgs = explode(" > ", $arg);
		$profArg = array_shift($nanoArgs);
		$profession = $this->util->getProfessionName($profArg);
		if (in_array($profArg, ["general", "General"])) {
			$profession = "General";
		}
		if ($profession === '') {
			$this->nanolinesShow($arg, null, $froobOnly, $context);
		} elseif (count($nanoArgs)) {
			$this->nanolinesShow(implode(" > ", $nanoArgs), $profession, $froobOnly, $context);
		} else {
			$this->nanolinesList($profession, $froobOnly, $context);
		}
	}

	/**
	 * Show all nanos of a nanoline grouped by sub-strain
	 */
	private function nanolinesShow(string $nanoline, ?string $prof, bool $froobOnly, CmdContext $context): void {
		$query = $this->db->table("nanos")
			->whereIlike("strain", $nanoline)
			->orderBy("sub_strain")
			->orderBy("sort_order");
		if ($prof !== null) {
			$query->whereIlike("professions", "%{$prof}%");
		}
		if ($froobOnly) {
			if ($prof !== null && in_array($prof, ["Keeper", "Shade"])) {
				$msg = "<highlight>$prof<end> is not playable as froob.";
				$context->reply($msg);
				return;
			}
			$query->where("froob_friendly", true);
		}
		/** @var Collection<Nano> */
		$data = $query->asObj(Nano::class);
		if ($data->isEmpty()) {
			$msg = "No nanoline named <highlight>$nanoline<end> found.";
			if ($prof !== null) {
				$msg = "No nanoline named <highlight>$nanoline<end> found for <highlight>$prof<end>.";
			}
			$context->reply($msg);
			return;
		}

		$lastSubStrain = null;
		$blob = "<header2>{$data[0]->strain}<end>\n";
		foreach ($data as $nano) {
			if ($nano->sub_strain !== null && $nano->sub_strain !== '' && $nano->sub_strain !== $lastSubStrain) {
				$blob .= "\n<highlight>{$nano->sub_strain}<end>\n";
				$lastSubStrain = $nano->sub_strain;
			}
			$nanoLink = $this->makeNanoLink($nano);
			$crystalLink = isset($nano->crystal_id)
				? $this->text->makeItem($nano->crystal_id, $nano->crystal_id, $nano->ql, "Crystal")
				: "Crystal";
			$blob .= "<tab>" . $this->text->alignNumber($nano->ql, 3) . " [$crystalLink] $nanoLink ($nano->location)\n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("All {$data[0]->strain} Nanos", $blob);
		if ($prof !== null) {
			$msg = $this->text->makeBlob("All {$data[0]->strain} Nanos for $prof", $blob);
		}

		$context->reply($msg);
	}

	/**
	 * List all nanolines for a profession, grouped by school
	 * @param string $profession The full name of the profession
	 * @param bool   $froobOnly  If true, only show nanolines containing nanos a froob can use
	 * @param CmdContext $context Object to send the reply to
	 * @return void
	 */
	private function nanolinesList(string $profession, bool $froobOnly, CmdContext $context): void {
		$query = $this->db->table("nanos")
			->whereIlike("professions", "%{$profession}%")
			->orderBy("school")
			->orderBy("strain")
			->select("school", "strain")->distinct();
		if ($froobOnly) {
			if ($profession !== null && in_array($profession, ["Keeper", "Shade"])) {
				$msg = "<highlight>$profession<end> is not playable as froob.";
				$context->reply($msg);
				return;
			}
			$query->where("froob_friendly", true);
		}
		$data = $query->asObj()->toArray();

		$shortProf = $profession;
		if ($profession !== 'General') {
			$shortProf = $this->util->getProfessionAbbreviation($profession);
		}
		$blob = '';
		$lastSchool = null;
		$command = "nanolines";
		if ($froobOnly) {
			$command = "nanolinesfroob";
		}
		foreach ($data as $row) {
			$strain = $row->strain;
			if ($lastSchool === null || $lastSchool !== $row->school) {
				if ($lastSchool !== null) {
					$blob .="\n";
				}
				$blob .= "<pagebreak><header2>{$row->school}<end>\n";
				$lastSchool = $row->school;
			}
			$blob .= "<tab>" . $this->text->makeChatcmd($strain, "/tell <myname> $command $shortProf > $row->strain");
			$blob .= "\n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("$profession Nanolines", $blob);

		$context->reply($msg);
	}

	#[NCA\HandlesCommand("nanoloc")]
	public function nanolocListCommand(CmdContext $context): void {
		$query = $this->db->table("nanos")
			->groupBy("location")
			->orderBy("location")
			->select("location");
		$query->addSelect($query->colFunc("COUNT", "location", "count"));
		$data = $query->asObj();
		$nanoCount = [];
		foreach ($data as $row) {
			$locations = preg_split("/\s*\/\s*/", $row->location);
			foreach ($locations as $loc) {
				$nanoCount[$loc] = ($nanoCount[$loc]??0) + $row->count;
			}
		}
		ksort($nanoCount);

		$blob = "<header2>All nano locations<end>\n";
		foreach ($nanoCount as $loc => $count) {
			$blob .= "<tab>" . $this->text->makeChatcmd(
				$loc,
				"/tell <myname> nanoloc $loc"
			) . " ($count) \n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("Nano Locations", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("nanoloc")]
	public function nanolocViewCommand(CmdContext $context, string $location): void {
		$nanos = $this->db->table("nanos")
			->whereIlike("location", $location)
			->orWhereIlike("location", "%/{$location}")
			->orWhereIlike("location", "{$location}/%")
			->orderBy("nano_name")
			->asObj(Nano::class);

		$count = $nanos->count();
		if ($count === 0) {
			$nanos = $this->db->table("nanos")
				->whereIlike("location", "%{$location}%")
				->orderBy("nano_name")
				->asObj(Nano::class);
			$count = $nanos->count();
		}
		if ($count === 0) {
			$msg = "No nanos found.";
			$context->reply($msg);
			return;
		}
		/** @var Collection<Nano> $nanos */
		$blob = '';
		foreach ($nanos as $nano) {
			$nanoLink = $this->makeNanoLink($nano);
			$crystalLink = isset($nano->crystal_id)
				? $this->text->makeItem($nano->crystal_id, $nano->crystal_id, $nano->ql, "Crystal")
				: "Crystal";
			$blob .= "QL" . $this->text->alignNumber($nano->ql, 3) . " [$crystalLink] $nanoLink";
			if ($nano->professions) {
				$blob .= " - <highlight>" . join("<end>, <highlight>", explode(":", $nano->professions)) . "<end>";
			}
			$blob .= "\n";
		}

		$msg = $this->text->makeBlob("Nanos for Location '$location' ($count)", $blob);
		$context->reply($msg);
	}

	private function getFooter(): string {
		return "\n\nNanos DB originally provided by Saavick & Lucier, now enhanced with AOIA+ data";
	}

	/**
	 * Creates a link to a nano - not a crystal
	 */
	public function makeNanoLink(Nano $nano): string {
		return "<a href='itemid://53019/{$nano->nano_id}'>{$nano->nano_name}</a>";
	}

	/** @return Collection<Nanoline> */
	public function getNanoLinesByIds(int ...$ids): Collection {
		return $this->db->table("nano_lines")
			->whereIn("strain_id", $ids)
			->asObj(Nanoline::class);
	}

	public function getNanoLineById(int $id): ?Nanoline {
		return $this->nanolines[$id] ?? null;
	}
}
