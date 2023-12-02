<?php

declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE;

use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\ParamClass\PProfession;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	CommandAlias,
	DB,
	ModuleInstance,
	SettingManager,
	Text,
	UserException,
	Util,
};

/**
 * @author Nadyita (RK5)
 * @author Tyrence (RK2)
 * @author Healnjoo (RK2)
 * @author Mdkdoc420 (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "nano",
		accessLevel: "guest",
		description: "Searches for a nano and tells you were to get it",
	),
	NCA\DefineCommand(
		command: "nanolines",
		accessLevel: "guest",
		description: "Shows nanos based on nanoline",
		alias: "nl"
	),
	NCA\DefineCommand(
		command: "nanolinesfroob",
		accessLevel: "guest",
		description: "Shows nanos for froobs based on nanoline ",
		alias: "nlf"
	),
	NCA\DefineCommand(
		command: "nanoloc",
		accessLevel: "guest",
		description: "Browse nanos by location",
	),
	NCA\DefineCommand(
		command: "bestnanos",
		accessLevel: "guest",
		description: "Show the best nanos for your level and their requirements",
		alias: "bn"
	),
	NCA\DefineCommand(
		command: "bestnanosfroob",
		accessLevel: "guest",
		description: "Show the best froob-nanos for your level and their requirements",
		alias: "bnf"
	),
]
class NanoController extends ModuleInstance {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public SettingManager $settingManager;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	/** Number of Nanos shown on the list */
	#[NCA\Setting\Number(options: [30, 40, 50, 60])]
	public int $maxnano = 40;

	/** Add a link to check nano prices on GMI */
	#[NCA\Setting\Boolean]
	public bool $nanoAddGMI = true;

	/** Add the nano id to the output */
	#[NCA\Setting\Boolean]
	public bool $nanoAddID = true;

	/** @var array<int,Nanoline> */
	public array $nanolines = [];

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/nanos.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/nano_lines.csv");

		$this->nanolines = $this->db->table("nano_lines")
			->asObj(Nanoline::class)
			->keyBy("strain_id")
			->toArray();
		$this->commandAlias->register($this->moduleName, "bestnanos long", "bnl");
		$this->commandAlias->register($this->moduleName, "bestnanosfroob long", "bnfl");
	}

	/** Search for a nano by name */
	#[NCA\HandlesCommand("nano")]
	#[NCA\Help\Group("nano")]
	public function nanoCommand(CmdContext $context, string $search): void {
		$search = htmlspecialchars_decode($search);
		$query = $this->db->table("nanos")
			->orderBy("strain")
			->orderBy("sub_strain")
			->orderBy("sort_order")
			->limit($this->maxnano);
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
			/** @var Nano $row */
			$defColor = $this->settingManager->getString('default_window_color');
			if ($currentNanoline !== $row->strain || $currentSubstrain !== $row->sub_strain) {
				if (!empty($row->strain)) {
					$nanolineLink = $this->text->makeChatcmd("see all nanos", "/tell <myname> nanolines {$row->strain}");
					$blob .= "\n<pagebreak><header2>{$row->school} {$defColor}&gt;<end> {$row->strain}";
					if ($row->sub_strain) {
						$blob .= " {$defColor}&gt;<end> {$row->sub_strain}";
					}
					$blob .= "{$defColor} - [{$nanolineLink}]<end><end>\n";
				} else {
					$blob .= "\n<pagebreak><header2>Unknown/General<end>\n";
				}
				$currentNanoline = $row->strain;
				$currentSubstrain = $row->sub_strain;
			}
			$nanoLink = $this->makeNanoLink($row);
			$gmiLink = ($this->nanoAddGMI && isset($row->crystal_id))
				? " [" . $this->text->makeChatcmd("GMI", "/tell <myname> gmi {$row->crystal_id}") . "]"
				: '';
			$idLink = ($this->nanoAddID && isset($row->nano_id))
				? " ID " . $this->text->alignNumber($row->nano_id, 6)
				: '';
			$crystalLink = isset($row->crystal_id)
				? $this->text->makeItem($row->crystal_id, $row->crystal_id, $row->ql, "Crystal")
				: "Crystal";
			$info = "QL" . $this->text->alignNumber($row->ql, 3) . $gmiLink . $idLink . " [{$crystalLink}] {$nanoLink} ({$row->location})";
			$info .= " - <highlight>" . implode("<end>, <highlight>", explode(":", $row->professions)) . "<end>";
			$blob .= "<tab>{$info}\n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("Nano Search Results ({$count})", $blob);
		if (count($data) === 1) {
			assert(isset($info, $gmiLink));
			$msg = $this->text->blobWrap(
				str_replace($gmiLink, "", $info) . " [",
				$this->text->makeBlob("details", $blob),
				"]"
			);
		}

		$context->reply($msg);
	}

	/** Show all professions that have nanolines */
	#[NCA\HandlesCommand("nanolines")]
	#[NCA\Help\Group("nanolines")]
	public function nanolinesListProfsCommand(CmdContext $context): void {
		$this->listNanolineProfs($context, false);
	}

	/** Show all froob professions that have nanolines */
	#[NCA\HandlesCommand("nanolinesfroob")]
	#[NCA\Help\Group("nanolines")]
	public function nanolinesFroobListProfsCommand(CmdContext $context): void {
		$this->listNanolineProfs($context, true);
	}

	/**
	 * List all professions for which nanolines exist
	 *
	 * @param CmdContext $context   Where to send the reply to
	 * @param bool       $froobOnly Is set, only show professions a froob can play
	 */
	public function listNanolineProfs(CmdContext $context, bool $froobOnly): void {
		$query = $this->db->table("nanos")
			->where("professions", "not like", "%:%")
			->orderBy("professions")
			->select("professions")->distinct();
		if ($froobOnly) {
			$query->whereNotIn("professions", ["Keeper", "Shade"]);
		}

		/** @var Collection<string> */
		$profs = $query->pluckStrings("professions");

		$blob = "<header2>Choose a profession<end>\n";
		$command = $froobOnly ? "nanolinesfroob" : "nanolines";
		foreach ($profs as $prof) {
			$blob .= "<tab>" . $this->text->makeChatcmd($prof, "/tell <myname> {$command} {$prof}");
			$blob .= "\n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob('Nanolines', $blob);

		$context->reply($msg);
	}

	/** Show all nanos in a given nano line */
	#[NCA\HandlesCommand("nanolines")]
	#[NCA\Help\Group("nanolines")]
	public function nanolinesListCommand(CmdContext $context, string $nanoLine): void {
		$this->listNanolines($context, false, $nanoLine);
	}

	/** Show all froob-usable nanos in a given nano line */
	#[NCA\HandlesCommand("nanolinesfroob")]
	#[NCA\Help\Group("nanolines")]
	public function nanolinesFroobListCommand(CmdContext $context, string $nanoLine): void {
		$this->listNanolines($context, true, $nanoLine);
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

	/** Show a list of nano locations */
	#[NCA\HandlesCommand("nanoloc")]
	#[NCA\Help\Group("nano")]
	public function nanolocListCommand(CmdContext $context): void {
		$query = $this->db->table("nanos")
			->groupBy("location")
			->orderBy("location")
			->select("location");
		$query->addSelect($query->colFunc("COUNT", "location", "count"));

		/** @var Collection<LocationCount> */
		$data = $query->asObj(LocationCount::class);
		$nanoCount = [];
		foreach ($data as $row) {
			$locations = \Safe\preg_split("/\s*\/\s*/", $row->location);
			foreach ($locations as $loc) {
				$nanoCount[$loc] = ($nanoCount[$loc]??0) + $row->count;
			}
		}
		ksort($nanoCount);

		/** @var array<string,int> $nanoCount */

		$blob = "<header2>All nano locations<end>\n";
		foreach ($nanoCount as $loc => $count) {
			$blob .= "<tab>" . $this->text->makeChatcmd(
				$loc,
				"/tell <myname> nanoloc {$loc}"
			) . " ({$count}) \n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("Nano Locations", $blob);
		$context->reply($msg);
	}

	/** Search for a nano by location */
	#[NCA\HandlesCommand("nanoloc")]
	#[NCA\Help\Group("nano")]
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
			$gmiLink = ($this->nanoAddGMI && isset($nano->crystal_id))
				? " [" . $this->text->makeChatcmd("GMI", "/tell <myname> gmi {$nano->crystal_id}") . "]"
				: '';
			$crystalLink = isset($nano->crystal_id)
				? $this->text->makeItem($nano->crystal_id, $nano->crystal_id, $nano->ql, "Crystal")
				: "Crystal";
			$blob .= "QL" . $this->text->alignNumber($nano->ql, 3) . $gmiLink . " [{$crystalLink}] {$nanoLink}";
			if ($nano->professions) {
				$blob .= " - <highlight>" . join("<end>, <highlight>", explode(":", $nano->professions)) . "<end>";
			}
			$blob .= "\n";
		}

		$msg = $this->text->makeBlob("Nanos for Location '{$location}' ({$count})", $blob);
		$context->reply($msg);
	}

	/** Show a list of the best nanos and the requirements to cast them */
	#[NCA\HandlesCommand("bestnanos")]
	#[NCA\HandlesCommand("bestnanosfroob")]
	#[NCA\Help\Group("nano")]
	public function bestNanosCommand(
		CmdContext $context,
		#[NCA\Str("long")] ?string $long
	): Generator {
		/** @var ?Player */
		$whois = yield $this->playerManager->byName($context->char->name);
		if (!isset($whois) || !isset($whois->profession) || !isset($whois->level)) {
			$context->reply("Could not retrieve whois info for you.");
			return;
		}

		$this->showBestNanosCommand(
			$context,
			$whois->profession,
			$whois->level,
			$context->getCommand() === "bestnanosfroob",
			!isset($long)
		);
	}

	/** Show a list of the best nanos and the requirements to cast them */
	#[NCA\HandlesCommand("bestnanos")]
	#[NCA\HandlesCommand("bestnanosfroob")]
	#[NCA\Help\Group("nano")]
	public function bestNanos2Command(
		CmdContext $context,
		#[NCA\Str("long")] ?string $long,
		PProfession $profession,
		int $level,
	): void {
		$this->showBestNanosCommand(
			$context,
			$profession(),
			$level,
			$context->getCommand() === "bestnanosfroob",
			!isset($long)
		);
	}

	/** Show a list of the best nanos and the requirements to cast them */
	#[NCA\HandlesCommand("bestnanos")]
	#[NCA\HandlesCommand("bestnanosfroob")]
	#[NCA\Help\Group("nano")]
	public function bestNanos3Command(
		CmdContext $context,
		#[NCA\Str("long")] ?string $long,
		int $level,
		PProfession $profession,
	): void {
		$this->showBestNanosCommand(
			$context,
			$profession(),
			$level,
			$context->getCommand() === "bestnanosfroob",
			!isset($long)
		);
	}

	/** Creates a link to a nano - not a crystal */
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

	/** @return Collection<Nano> */
	private function getBestNanos(string $profession, int $level, bool $froobOnly): Collection {
		if ($level < 1 || $level > 220) {
			throw new UserException("Level has to be between 1 and 220.");
		}

		$query = $this->db->table("nanos")
			->orderBy("school")
			->orderBy("strain")
			->orderBy("sub_strain")
			->orderBy("sort_order")
			->whereIlike("professions", "%{$profession}%")
			->where(function (Builder $query) use ($level): void {
				$query->where("min_level", "<=", $level)
					->orWhereNull("min_level");
			});
		if ($froobOnly) {
			$query->where("froob_friendly", true);
		}

		/** @var array<string,Nano> */
		$nanos = $query
			->asObj(Nano::class)
			->reduce(function (array $nanos, Nano $nano): array {
				$key = "{$nano->school}|{$nano->strain}|{$nano->sub_strain}";
				$nanos[$key] ??= $nano;
				return $nanos;
			}, []);
		$nanos = array_values($nanos);
		return new Collection($nanos);
	}

	/** @param Collection<Nano> $nanos */
	private function getNanoSkillsNeeded(Collection $nanos): NanoSkillsNeeded {
		return $nanos->reduce(
			function (NanoSkillsNeeded $max, Nano $nano): NanoSkillsNeeded {
				if ($nano->mc > $max->mc) {
					$max->mc = $nano->mc;
				}
				if ($nano->ts > $max->ts) {
					$max->ts = $nano->ts;
				}
				if ($nano->mm > $max->mm) {
					$max->mm = $nano->mm;
				}
				if ($nano->bm > $max->bm) {
					$max->bm = $nano->bm;
				}
				if ($nano->pm > $max->pm) {
					$max->pm = $nano->pm;
				}
				if ($nano->si > $max->si) {
					$max->si = $nano->si;
				}
				return $max;
			},
			new NanoSkillsNeeded(mc: 0, ts: 0, mm: 0, bm: 0, pm: 0, si: 0),
		);
	}

	private function showBestNanosCommand(
		CmdContext $context,
		string $profession,
		int $level,
		bool $froobOnly,
		bool $compact,
	): void {
		if ($level < 1 || $level > 220) {
			$context->reply("Level has to be between 1 and 220.");
			return;
		}
		$nanos = $this->getBestNanos($profession, $level, $froobOnly);
		$count = $nanos->count();
		$blob = $this->renderBestNanos($nanos, $compact);
		$froobPrefix = "";
		if ($froobOnly) {
			$froobPrefix = "froob-";
		}
		if ($compact) {
			$cmdName = "bestnanos";
			if ($froobOnly) {
				$cmdName = "bestnanosfroob";
			}
			$blob = $this->text->makeChatcmd(
				"Show verbose list",
				"/tell <myname> {$cmdName} long {$profession} {$level}",
			) . "\n\n{$blob}";
		}
		$msg = $this->text->makeBlob("Best available nanos for a level {$level} {$froobPrefix}{$profession} ({$count})", $blob);

		$context->reply($msg);
	}

	/** @param Collection<Nano> $nanos */
	private function renderBestNanos(Collection $nanos, bool $compact): string {
		$nanoSkillsNeeded = $this->getNanoSkillsNeeded($nanos);
		$blob = "<header2>Required Nanoskills<end>".
			"\n".
			"<tab>MM: " . $this->text->alignNumber($nanoSkillsNeeded->mm, 4, "highlight", true).
			"<tab>BM: " . $this->text->alignNumber($nanoSkillsNeeded->bm, 4, "highlight", true).
			"\n".
			"<tab>PM: " . $this->text->alignNumber($nanoSkillsNeeded->pm, 4, "highlight", true).
			"<tab>SI: " . $this->text->alignNumber($nanoSkillsNeeded->si, 4, "highlight", true).
			"\n".
			"<tab>TS: " . $this->text->alignNumber($nanoSkillsNeeded->ts, 4, "highlight", true).
			"<tab>MC: " . $this->text->alignNumber($nanoSkillsNeeded->mc, 4, "highlight", true).
			"\n\n";
		$defColor = $this->settingManager->getString('default_window_color');
		if ($compact) {
			$blob .= "<header2>Best nanos in each line<end>";
		}
		foreach ($nanos as $row) {
			$nanoLink = $this->makeNanoLink($row);
			if ($compact) {
				if (!empty($row->strain)) {
					$blob .= "\n<pagebreak><tab><highlight>{$row->school} <end>&gt;<highlight> {$row->strain}<end>";
					if ($row->sub_strain) {
						$blob .= " &gt; <highlight>{$row->sub_strain}<end>";
					}
				} else {
					$blob .= "\n<pagebreak><tab><highlight>Unknown/General<end>";
				}
				$blob .= " &gt; {$nanoLink}";
			} else {
				$gmiLink = ($this->nanoAddGMI && isset($row->crystal_id))
					? " [" . $this->text->makeChatcmd("GMI", "/tell <myname> gmi {$row->crystal_id}") . "]"
					: '';
				$crystalLink = isset($row->crystal_id)
					? $this->text->makeItem($row->crystal_id, $row->crystal_id, $row->ql, "Crystal")
					: "Crystal";
				if (!empty($row->strain)) {
					$nanolineLink = $this->text->makeChatcmd("see all nanos", "/tell <myname> nanolines {$row->strain}");
					$blob .= "\n<pagebreak><header2>{$row->school} {$defColor}&gt;<end> {$row->strain}";
					if ($row->sub_strain) {
						$blob .= " {$defColor}&gt;<end> {$row->sub_strain}";
					}
					$blob .= "{$defColor} - [{$nanolineLink}]<end><end>\n";
				} else {
					$blob .= "\n<pagebreak><header2>Unknown/General<end>\n";
				}
				$info = "QL" . $this->text->alignNumber($row->ql, 3) . $gmiLink . " [{$crystalLink}] {$nanoLink} ({$row->location})";
				$blob .= "<tab>{$info}\n";
				$reqs = [];
				foreach (['mm', 'bm', 'pm', 'si', 'ts', 'mc'] as $skill) {
					if (isset($row->{$skill})) {
						$reqs []= strtoupper($skill) . ": {$row->{$skill}}";
					}
				}
				if (isset($row->min_level)) {
					$reqs []= "Level: {$row->min_level}";
				}
				if (isset($row->spec)) {
					$reqs []= "Spec: {$row->spec}";
				}
				if ($row->nano_deck) {
					$reqs []= "Nanodeck";
				}
				$reqs []= "Nanocost: {$row->nano_cost}";
				$blob .= "<tab>" . join(", ", $reqs) . "\n";
			}
		}
		$blob .= $this->getFooter();
		return $blob;
	}

	/** Show all nanos of a nanoline grouped by sub-strain */
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
				$msg = "<highlight>{$prof}<end> is not playable as froob.";
				$context->reply($msg);
				return;
			}
			$query->where("froob_friendly", true);
		}

		/** @var Collection<Nano> */
		$data = $query->asObj(Nano::class);
		if ($data->isEmpty()) {
			$msg = "No nanoline named <highlight>{$nanoline}<end> found.";
			if ($prof !== null) {
				$msg = "No nanoline named <highlight>{$nanoline}<end> found for <highlight>{$prof}<end>.";
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
			$gmiLink = ($this->nanoAddGMI && isset($nano->crystal_id))
				? " [" . $this->text->makeChatcmd("GMI", "/tell <myname> gmi {$nano->crystal_id}") . "]"
				: '';
			$crystalLink = isset($nano->crystal_id)
				? $this->text->makeItem($nano->crystal_id, $nano->crystal_id, $nano->ql, "Crystal")
				: "Crystal";
			$blob .= "<tab>" . $this->text->alignNumber($nano->ql, 3) . $gmiLink . " [{$crystalLink}] {$nanoLink} ({$nano->location})\n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("All {$data[0]->strain} Nanos", $blob);
		if ($prof !== null) {
			$msg = $this->text->makeBlob("All {$data[0]->strain} Nanos for {$prof}", $blob);
		}

		$context->reply($msg);
	}

	/**
	 * List all nanolines for a profession, grouped by school
	 *
	 * @param string     $profession The full name of the profession
	 * @param bool       $froobOnly  If true, only show nanolines containing nanos a froob can use
	 * @param CmdContext $context    Object to send the reply to
	 */
	private function nanolinesList(string $profession, bool $froobOnly, CmdContext $context): void {
		$query = $this->db->table("nanos")
			->whereIlike("professions", "%{$profession}%")
			->orderBy("school")
			->orderBy("strain")
			->select("school", "strain")->distinct();
		if ($froobOnly) {
			if ($profession !== null && in_array($profession, ["Keeper", "Shade"])) {
				$msg = "<highlight>{$profession}<end> is not playable as froob.";
				$context->reply($msg);
				return;
			}
			$query->where("froob_friendly", true);
		}

		/** @var SchoolAndStrain[] */
		$data = $query->asObj(SchoolAndStrain::class)->toArray();

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
			$blob .= "<tab>" . $this->text->makeChatcmd($strain, "/tell <myname> {$command} {$shortProf} > {$row->strain}");
			$blob .= "\n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("{$profession} Nanolines", $blob);

		$context->reply($msg);
	}

	private function getFooter(): string {
		return "\n\nNanos DB originally provided by Saavick & Lucier, now enhanced with AOIA+ data";
	}
}
