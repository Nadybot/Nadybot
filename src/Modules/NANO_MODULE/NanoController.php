<?php

declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Parameter\Str,
	CmdContext,
	CommandAlias,
	DB,
	Exceptions\UserException,
	ModuleInstance,
	Safe,
	SettingManager,
	Text,
	Types\AccessLevel,
	Types\Profession,
};
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;

/**
 * @author Nadyita (RK5)
 * @author Tyrence (RK2)
 * @author Healnjoo (RK2)
 * @author Mdkdoc420 (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\HasTests,
	NCA\DefineCommand(
		command: 'nano',
		accessLevel: AccessLevel::Guest,
		description: 'Searches for a nano and tells you were to get it',
	),
	NCA\DefineCommand(
		command: 'nanolines',
		accessLevel: AccessLevel::Guest,
		description: 'Shows nanos based on nanoline',
		alias: 'nl'
	),
	NCA\DefineCommand(
		command: 'nanolinesfroob',
		accessLevel: AccessLevel::Guest,
		description: 'Shows nanos for froobs based on nanoline ',
		alias: 'nlf'
	),
	NCA\DefineCommand(
		command: 'nanoloc',
		accessLevel: AccessLevel::Guest,
		description: 'Browse nanos by location',
	),
	NCA\DefineCommand(
		command: 'bestnanos',
		accessLevel: AccessLevel::Guest,
		description: 'Show the best nanos for your level and their requirements',
		alias: 'bn'
	),
	NCA\DefineCommand(
		command: 'bestnanosfroob',
		accessLevel: AccessLevel::Guest,
		description: 'Show the best froob-nanos for your level and their requirements',
		alias: 'bnf'
	),
]
class NanoController extends ModuleInstance {
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
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private SettingManager $settingManager;

	#[NCA\Inject]
	private PlayerManager $playerManager;

	#[NCA\Inject]
	private CommandAlias $commandAlias;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/nanos.csv');
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/nano_lines.csv');

		$this->nanolines = $this->db->table(Nanoline::getTable())
			->asObj(Nanoline::class)
			->keyBy('strain_id')
			->toArray();
		$this->commandAlias->register($this->moduleName, 'bestnanos long', 'bnl');
		$this->commandAlias->register($this->moduleName, 'bestnanosfroob long', 'bnfl');
	}

	/** Search for a nano by name */
	#[NCA\HandlesCommand('nano')]
	#[NCA\Help\Group('nano')]
	public function nanoCommand(CmdContext $context, string $search): void {
		$search = htmlspecialchars_decode($search);
		$query = $this->db->table(Nano::getTable())
			->orderBy('strain')
			->orderBy('sub_strain')
			->orderBy('sort_order')
			->limit($this->maxnano);
		$tmp = explode(' ', $search);
		$this->db->addWhereFromParams($query, $tmp, 'nano_name');

		$data = $query->asObj(Nano::class);

		$count = $data->count();
		if ($count === 0) {
			$msg = 'No nanos found.';
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
				if (strlen($row->strain) > 0) {
					$nanolineLink = Text::makeChatcmd('see all nanos', "/tell <myname> nanolines {$row->strain}");
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
				? ' [' . Text::makeChatcmd('GMI', "/tell <myname> gmi {$row->crystal_id}") . ']'
				: '';
			$idLink = ($this->nanoAddID && isset($row->nano_id))
				? ' ID ' . Text::alignNumber($row->nano_id, 6)
				: '';
			$crystalLink = $row->getCrystalLink() ?? 'Crystal';
			$info = 'QL' . Text::alignNumber($row->ql, 3) . $gmiLink . $idLink . " [{$crystalLink}] {$nanoLink} ({$row->location})";
			$info .= ' - ' . implode(', ', Text::arraySprintf('<highlight>%s<end>', ...$row->professions));
			$blob .= "<tab>{$info}\n";
		}
		$blob .= $this->getFooter();
		$msg = Text::makeBlob("Nano Search Results ({$count})", $blob);
		if (count($data) === 1) {
			assert(isset($info, $gmiLink));

			$popup = Text::makeBlob('details', $blob);

			/** @psalm-suppress PossiblyInvalidOperand */
			$msg = str_replace($gmiLink, '', $info) . " [{$popup}]";
		}

		$context->reply($msg);
	}

	/** Show all professions that have nanolines */
	#[NCA\HandlesCommand('nanolines')]
	#[NCA\Help\Group('nanolines')]
	public function nanolinesListProfsCommand(CmdContext $context): void {
		$this->listNanolineProfs($context, false);
	}

	/** Show all froob professions that have nanolines */
	#[NCA\HandlesCommand('nanolinesfroob')]
	#[NCA\Help\Group('nanolines')]
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
		$query = $this->db->table(Nano::getTable())
			->where('professions', 'not like', '%:%')
			->orderBy('professions')
			->select('professions')->distinct();
		if ($froobOnly) {
			$query->whereNotIn('professions', ['Keeper', 'Shade']);
		}

		$profs = $query->pluckStrings('professions');

		$blob = "<header2>Choose a profession<end>\n";
		$command = $froobOnly ? 'nanolinesfroob' : 'nanolines';
		foreach ($profs as $prof) {
			$blob .= '<tab>' . Text::makeChatcmd($prof, "/tell <myname> {$command} {$prof}");
			$blob .= "\n";
		}
		$blob .= $this->getFooter();
		$msg = Text::makeBlob('Nanolines', $blob);

		$context->reply($msg);
	}

	/** Show all nanos in a given nano line */
	#[NCA\HandlesCommand('nanolines')]
	#[NCA\Help\Group('nanolines')]
	public function nanolinesListCommand(CmdContext $context, string $nanoLine): void {
		$this->listNanolines($context, false, $nanoLine);
	}

	/** Show all froob-usable nanos in a given nano line */
	#[NCA\HandlesCommand('nanolinesfroob')]
	#[NCA\Help\Group('nanolines')]
	public function nanolinesFroobListCommand(CmdContext $context, string $nanoLine): void {
		$this->listNanolines($context, true, $nanoLine);
	}

	public function listNanolines(CmdContext $context, bool $froobOnly, string $arg): void {
		$arg = html_entity_decode($arg);
		$nanoArgs = explode(' > ', $arg);
		$profArg = array_shift($nanoArgs);
		$profession = Profession::tryFromName($profArg)?->value;
		if (in_array($profArg, ['general', 'General'], true)) {
			$profession = 'General';
		}
		if (!isset($profession)) {
			$this->nanolinesShow($arg, null, $froobOnly, $context);
		} elseif (count($nanoArgs)) {
			$this->nanolinesShow(implode(' > ', $nanoArgs), $profession, $froobOnly, $context);
		} else {
			$this->nanolinesList($profession, $froobOnly, $context);
		}
	}

	/** Show a list of nano locations */
	#[NCA\HandlesCommand('nanoloc')]
	#[NCA\Help\Group('nano')]
	public function nanolocListCommand(CmdContext $context): void {
		$query = $this->db->table(Nano::getTable())
			->groupBy('location')
			->orderBy('location')
			->select('location');
		$query->addSelect($query->raw($query->colFunc('COUNT', 'location', 'count')));

		$data = $query->asObj(LocationCount::class);
		$nanoCount = [];
		foreach ($data as $row) {
			$locations = Safe::pregSplit("/\s*\/\s*/", $row->location);
			foreach ($locations as $loc) {
				$nanoCount[$loc] = ($nanoCount[$loc]??0) + $row->count;
			}
		}
		ksort($nanoCount);

		/** @var array<string,int> $nanoCount */

		$blob = "<header2>All nano locations<end>\n";
		foreach ($nanoCount as $loc => $count) {
			$blob .= '<tab>' . Text::makeChatcmd(
				$loc,
				"/tell <myname> nanoloc {$loc}"
			) . " ({$count}) \n";
		}
		$blob .= $this->getFooter();
		$msg = Text::makeBlob('Nano Locations', $blob);
		$context->reply($msg);
	}

	/** Search for a nano by location */
	#[NCA\HandlesCommand('nanoloc')]
	#[NCA\Help\Group('nano')]
	public function nanolocViewCommand(CmdContext $context, string $location): void {
		$nanos = $this->db->table(Nano::getTable())
			->whereIlike('location', $location)
			->orWhereIlike('location', "%/{$location}")
			->orWhereIlike('location', "{$location}/%")
			->orderBy('nano_name')
			->asObj(Nano::class);

		$count = $nanos->count();
		if ($count === 0) {
			$nanos = $this->db->table(Nano::getTable())
				->whereIlike('location', "%{$location}%")
				->orderBy('nano_name')
				->asObj(Nano::class);
			$count = $nanos->count();
		}
		if ($count === 0) {
			$msg = 'No nanos found.';
			$context->reply($msg);
			return;
		}

		$blob = '';
		foreach ($nanos as $nano) {
			$nanoLink = $this->makeNanoLink($nano);
			$gmiLink = ($this->nanoAddGMI && isset($nano->crystal_id))
				? ' [' . Text::makeChatcmd('GMI', "/tell <myname> gmi {$nano->crystal_id}") . ']'
				: '';
			$crystalLink = $nano->getCrystalLink() ?? 'Crystal';
			$blob .= 'QL' . Text::alignNumber($nano->ql, 3) . $gmiLink . " [{$crystalLink}] {$nanoLink}";
			if (count($nano->professions)) {
				$blob .= ' - ' . implode(', ', Text::arraySprintf('<highlight>%s<end>', ...$nano->professions));
			}
			$blob .= "\n";
		}

		$msg = Text::makeBlob("Nanos for Location '{$location}' ({$count})", $blob);
		$context->reply($msg);
	}

	/** Show a list of the best nanos and the requirements to cast them */
	#[NCA\HandlesCommand('bestnanos')]
	#[NCA\HandlesCommand('bestnanosfroob')]
	#[NCA\Help\Group('nano')]
	public function bestNanosCommand(
		CmdContext $context,
		#[Str('long')] ?string $long
	): void {
		$whois = $this->playerManager->byName($context->char->name);
		if (!isset($whois) || !isset($whois->profession) || !isset($whois->level)) {
			$context->reply('Could not retrieve whois info for you.');
			return;
		}

		$this->showBestNanosCommand(
			$context,
			$whois->profession,
			$whois->level,
			$context->getCommand() === 'bestnanosfroob',
			!isset($long)
		);
	}

	/** Show a list of the best nanos and the requirements to cast them */
	#[NCA\HandlesCommand('bestnanos')]
	#[NCA\HandlesCommand('bestnanosfroob')]
	#[NCA\Help\Group('nano')]
	public function bestNanos2Command(
		CmdContext $context,
		#[Str('long')] ?string $long,
		Profession $profession,
		int $level,
	): void {
		$this->showBestNanosCommand(
			$context,
			$profession,
			$level,
			$context->getCommand() === 'bestnanosfroob',
			!isset($long)
		);
	}

	/** Show a list of the best nanos and the requirements to cast them */
	#[NCA\HandlesCommand('bestnanos')]
	#[NCA\HandlesCommand('bestnanosfroob')]
	#[NCA\Help\Group('nano')]
	public function bestNanos3Command(
		CmdContext $context,
		#[Str('long')] ?string $long,
		int $level,
		Profession $profession,
	): void {
		$this->showBestNanosCommand(
			$context,
			$profession,
			$level,
			$context->getCommand() === 'bestnanosfroob',
			!isset($long)
		);
	}

	/** Creates a link to a nano - not a crystal */
	public function makeNanoLink(Nano $nano): string {
		return "<a href='itemid://53019/{$nano->nano_id}'>{$nano->nano_name}</a>";
	}

	/** @return Collection<int,Nanoline> */
	public function getNanoLinesByIds(int ...$ids): Collection {
		return $this->db->table(Nanoline::getTable())
			->whereIn('strain_id', $ids)
			->asObj(Nanoline::class);
	}

	public function getNanoLineById(int $id): ?Nanoline {
		return $this->nanolines[$id] ?? null;
	}

	/** @return Collection<int,Nano> */
	private function getBestNanos(Profession $profession, int $level, bool $froobOnly): Collection {
		if ($level < 1 || $level > 220) {
			throw new UserException('Level has to be between 1 and 220.');
		}

		$query = $this->db->table(Nano::getTable())
			->orderBy('school')
			->orderBy('strain')
			->orderBy('sub_strain')
			->orderBy('sort_order')
			->whereIlike('professions', "%{$profession->value}%")
			->where(static function (Builder $query) use ($level): void {
				$query->where('min_level', '<=', $level)
					->orWhereNull('min_level');
			});
		if ($froobOnly) {
			$query->where('froob_friendly', true);
		}

		/** @var array<string,Nano> */
		$nanos = $query
			->asObj(Nano::class)
			->reduce(static function (array $nanos, Nano $nano): array {
				$key = "{$nano->school}|{$nano->strain}|{$nano->sub_strain}";
				$nanos[$key] ??= $nano;
				return $nanos;
			}, []);

		/** @var Collection<int,Nano> */
		$bestNanos = collect(array_values($nanos));
		return $bestNanos;
	}

	/** @param Collection<int,Nano> $nanos */
	private function getNanoSkillsNeeded(Collection $nanos): NanoSkillsNeeded {
		return $nanos->reduce(
			static function (NanoSkillsNeeded $max, Nano $nano): NanoSkillsNeeded {
				if ((int)$nano->mc > (int)$max->mc) {
					$max->mc = $nano->mc;
				}
				if ((int)$nano->ts > (int)$max->ts) {
					$max->ts = $nano->ts;
				}
				if ((int)$nano->mm > (int)$max->mm) {
					$max->mm = $nano->mm;
				}
				if ((int)$nano->bm > (int)$max->bm) {
					$max->bm = $nano->bm;
				}
				if ((int)$nano->pm > (int)$max->pm) {
					$max->pm = $nano->pm;
				}
				if ((int)$nano->si > (int)$max->si) {
					$max->si = $nano->si;
				}
				return $max;
			},
			new NanoSkillsNeeded(mc: 0, ts: 0, mm: 0, bm: 0, pm: 0, si: 0),
		);
	}

	private function showBestNanosCommand(
		CmdContext $context,
		Profession $profession,
		int $level,
		bool $froobOnly,
		bool $compact,
	): void {
		if ($level < 1 || $level > 220) {
			$context->reply('Level has to be between 1 and 220.');
			return;
		}
		$nanos = $this->getBestNanos($profession, $level, $froobOnly);
		$count = $nanos->count();
		$blob = $this->renderBestNanos($nanos, $compact);
		$froobPrefix = '';
		if ($froobOnly) {
			$froobPrefix = 'froob-';
		}
		if ($compact) {
			$cmdName = 'bestnanos';
			if ($froobOnly) {
				$cmdName = 'bestnanosfroob';
			}
			$blob = Text::makeChatcmd(
				'Show verbose list',
				"/tell <myname> {$cmdName} long {$profession->short()} {$level}",
			) . "\n\n{$blob}";
		}
		$msg = Text::makeBlob("Best available nanos for a level {$level} {$froobPrefix}{$profession->value} ({$count})", $blob);

		$context->reply($msg);
	}

	/** @param Collection<int,Nano> $nanos */
	private function renderBestNanos(Collection $nanos, bool $compact): string {
		$nanoSkillsNeeded = $this->getNanoSkillsNeeded($nanos);
		$blob = '<header2>Required Nanoskills<end>'.
			"\n".
			'<tab>MM: ' . Text::alignNumber($nanoSkillsNeeded->mm, 4, 'highlight', true).
			'<tab>BM: ' . Text::alignNumber($nanoSkillsNeeded->bm, 4, 'highlight', true).
			"\n".
			'<tab>PM: ' . Text::alignNumber($nanoSkillsNeeded->pm, 4, 'highlight', true).
			'<tab>SI: ' . Text::alignNumber($nanoSkillsNeeded->si, 4, 'highlight', true).
			"\n".
			'<tab>TS: ' . Text::alignNumber($nanoSkillsNeeded->ts, 4, 'highlight', true).
			'<tab>MC: ' . Text::alignNumber($nanoSkillsNeeded->mc, 4, 'highlight', true).
			"\n\n";
		$defColor = $this->settingManager->getString('default_window_color');
		if ($compact) {
			$blob .= '<header2>Best nanos in each line<end>';
		}
		foreach ($nanos as $row) {
			/** @var Nano $row */
			$nanoLink = $this->makeNanoLink($row);
			if ($compact) {
				if (strlen($row->strain) > 0) {
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
					? ' [' . Text::makeChatcmd('GMI', "/tell <myname> gmi {$row->crystal_id}") . ']'
					: '';
				$crystalLink = $row->getCrystalLink() ?? 'Crystal';
				if (strlen($row->strain) > 0) {
					$nanolineLink = Text::makeChatcmd('see all nanos', "/tell <myname> nanolines {$row->strain}");
					$blob .= "\n<pagebreak><header2>{$row->school} {$defColor}&gt;<end> {$row->strain}";
					if ($row->sub_strain) {
						$blob .= " {$defColor}&gt;<end> {$row->sub_strain}";
					}
					$blob .= "{$defColor} - [{$nanolineLink}]<end><end>\n";
				} else {
					$blob .= "\n<pagebreak><header2>Unknown/General<end>\n";
				}
				$info = 'QL' . Text::alignNumber($row->ql, 3) . $gmiLink . " [{$crystalLink}] {$nanoLink} ({$row->location})";
				$blob .= "<tab>{$info}\n";
				$reqs = [];
				foreach (NanoSkill::cases() as $skill) {
					$requirement = $row->getRequirement($skill);
					if (isset($requirement)) {
						$reqs []= "{$skill->name}: {$requirement}";
					}
				}
				if (isset($row->min_level)) {
					$reqs []= "Level: {$row->min_level}";
				}
				if (isset($row->spec)) {
					$reqs []= "Spec: {$row->spec}";
				}
				if ($row->nano_deck) {
					$reqs []= 'Nanodeck';
				}
				$reqs []= "Nanocost: {$row->nano_cost}";
				$blob .= '<tab>' . implode(', ', $reqs) . "\n";
			}
		}
		$blob .= $this->getFooter();
		return $blob;
	}

	/** Show all nanos of a nanoline grouped by sub-strain */
	private function nanolinesShow(string $nanoline, ?string $prof, bool $froobOnly, CmdContext $context): void {
		$query = $this->db->table(Nano::getTable())
			->whereIlike('strain', $nanoline)
			->orderBy('sub_strain')
			->orderBy('sort_order');
		if ($prof !== null) {
			$query->whereIlike('professions', "%{$prof}%");
		}
		if ($froobOnly) {
			if ($prof !== null && in_array($prof, ['Keeper', 'Shade'], true)) {
				$msg = "<highlight>{$prof}<end> is not playable as froob.";
				$context->reply($msg);
				return;
			}
			$query->where('froob_friendly', true);
		}

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
			if ($nano->sub_strain !== '' && $nano->sub_strain !== $lastSubStrain) {
				$blob .= "\n<highlight>{$nano->sub_strain}<end>\n";
				$lastSubStrain = $nano->sub_strain;
			}
			$nanoLink = $this->makeNanoLink($nano);
			$gmiLink = ($this->nanoAddGMI && isset($nano->crystal_id))
				? ' [' . Text::makeChatcmd('GMI', "/tell <myname> gmi {$nano->crystal_id}") . ']'
				: '';
			$crystalLink = $nano->getCrystalLink() ?? 'Crystal';
			$blob .= '<tab>' . Text::alignNumber($nano->ql, 3) . $gmiLink . " [{$crystalLink}] {$nanoLink} ({$nano->location})\n";
		}
		$blob .= $this->getFooter();
		$msg = Text::makeBlob("All {$data[0]->strain} Nanos", $blob);
		if ($prof !== null) {
			$msg = Text::makeBlob("All {$data[0]->strain} Nanos for {$prof}", $blob);
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
		$query = $this->db->table(Nano::getTable())
			->whereIlike('professions', "%{$profession}%")
			->orderBy('school')
			->orderBy('strain')
			->select(['school', 'strain'])->distinct();
		if ($froobOnly) {
			if (in_array($profession, ['Keeper', 'Shade'], true)) {
				$msg = "<highlight>{$profession}<end> is not playable as froob.";
				$context->reply($msg);
				return;
			}
			$query->where('froob_friendly', true);
		}

		$data = $query->asObj(SchoolAndStrain::class);

		$shortProf = $profession;
		if ($profession !== 'General') {
			$shortProf = Profession::from($profession)->short();
		}
		$blob = '';
		$lastSchool = null;
		$command = 'nanolines';
		if ($froobOnly) {
			$command = 'nanolinesfroob';
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
			$blob .= '<tab>' . Text::makeChatcmd($strain, "/tell <myname> {$command} {$shortProf} > {$row->strain}");
			$blob .= "\n";
		}
		$blob .= $this->getFooter();
		$msg = Text::makeBlob("{$profession} Nanolines", $blob);

		$context->reply($msg);
	}

	private function getFooter(): string {
		return "\n\nNanos DB originally provided by Saavick & Lucier, now enhanced with AOIA+ data";
	}
}
