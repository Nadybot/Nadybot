<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	QueryBuilder,
	Text,
	Types\ImplantSlot,
	Types\Profession,
};
use Nadybot\Modules\ITEMS_MODULE\{
	Skill,
	WhatBuffsController,
};

/**
 * @author Tyrence (RK2)
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Premade'),
	NCA\DefineCommand(
		command: 'premade',
		accessLevel: 'guest',
		description: 'Searches for implants out of the premade implants booths',
	)
]
class PremadeImplantController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private WhatBuffsController $whatBuffsController;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/premade_implant.csv');
	}

	/** Search for implants by profession, slot, or modifier in the premade implant booth */
	#[NCA\HandlesCommand('premade')]
	#[NCA\Help\Example('<symbol>premade agent')]
	#[NCA\Help\Example('<symbol>premade cl')]
	#[NCA\Help\Example('<symbol>premade rwrist')]
	public function premadeCommand(CmdContext $context, string $search): void {
		$searchTerms = strtolower($search);
		$results = null;

		$profession = Profession::tryByName($searchTerms);
		if (isset($profession)) {
			$searchTerms = $profession->value;
			$results = $this->searchByProfession($profession);
		} elseif (PImplantSlot::matches($searchTerms)) {
			$results = $this->searchBySlot((new PImplantSlot($searchTerms))());
		} else {
			$results = $this->searchByModifier($searchTerms);
		}

		if (count($results)) {
			$blob = trim($this->formatResults($results));
			$msg = $this->text->makeBlob("Implant Search Results for '{$searchTerms}'", $blob);
		} else {
			$msg = 'No results found.';
		}

		$context->reply($msg);
	}

	/** @return Collection<int,PremadeSearchResult> */
	public function searchByProfession(Profession $profession): Collection {
		return $this->getBaseQuery()->where('p.profession_id', $profession->toNumber())
			->asObj(PremadeSearchResult::class);
	}

	/** @return Collection<int,PremadeSearchResult> */
	public function searchBySlot(ImplantSlot $slot): Collection {
		return $this->getBaseQuery()->where('i.short_name', $slot->designSlotName())
			->asObj(PremadeSearchResult::class);
	}

	/** @return Collection<int,PremadeSearchResult> */
	public function searchByModifier(string $modifier): Collection {
		$skills = $this->whatBuffsController->searchForSkill($modifier);
		if (!count($skills)) {
			/** @var Collection<int,PremadeSearchResult> */
			$empty = new Collection();
			return $empty;
		}
		$skillIds = array_map(
			static function (Skill $s): int {
				return $s->id;
			},
			$skills
		);
		$query = $this->getBaseQuery()
			->whereIn('cs.skill_id', $skillIds)
			->orWhereIn('cb.skill_id', $skillIds)
			->orWhereIn('cf.skill_id', $skillIds);

		return $query->asObj(PremadeSearchResult::class);
	}

	/** @param iterable<PremadeSearchResult> $implants */
	public function formatResults(iterable $implants): string {
		$blob = '';

		/** @var array<string,list<PremadeSearchResult>> */
		$slotMap = [];
		foreach ($implants as $implant) {
			$slotMap[$implant->slot] ??= [];
			$slotMap[$implant->slot] []= $implant;
		}
		foreach ($slotMap as $slot => $slotImplants) {
			$blob .= "<header2>{$slot}<end>\n";
			foreach ($slotImplants as $implant) {
				$blob .= $this->getFormattedLine($implant);
			}
		}

		return $blob;
	}

	public function getFormattedLine(PremadeSearchResult $implant): string {
		return "<tab><highlight>{$implant->profession->name}<end> ({$implant->ability})\n".
			"<tab>S: {$implant->shiny}\n".
			"<tab>B: {$implant->bright}\n".
			"<tab>F: {$implant->faded}\n\n";
	}

	protected function getBaseQuery(): QueryBuilder {
		$query = $this->db->table(PremadeImplant::getTable(), 'p')
			->join(ImplantType::getTable(as: 'i'), 'p.implant_type_id', 'i.implant_type_id')
			->join(Ability::getTable(as: 'a'), 'p.ability_id', 'a.ability_id')
			->join(Cluster::getTable(as: 'cs'), 'p.shiny_cluster_id', 'cs.cluster_id')
			->join(Cluster::getTable(as: 'cb'), 'p.bright_cluster_id', 'cb.cluster_id')
			->join(Cluster::getTable(as: 'cf'), 'p.faded_cluster_id', 'cf.cluster_id')
			->orderBy('slot')
			->select(['i.name AS slot', 'p.profession_id', 'a.name as ability']);
		$query->selectRaw(
			'CASE WHEN ' . $query->grammar->wrap('cs.cluster_id') . ' = 0 '.
			'THEN ? '.
			'ELSE ' .$query->grammar->wrap('cs.long_name'). ' '.
			'END AS ' . $query->grammar->wrap('shiny')
		)->addBinding('N/A', 'select');
		$query->selectRaw(
			'CASE WHEN ' . $query->grammar->wrap('cb.cluster_id') . ' = 0 '.
			'THEN ? '.
			'ELSE ' .$query->grammar->wrap('cb.long_name'). ' '.
			'END AS ' . $query->grammar->wrap('bright')
		)->addBinding('N/A', 'select');
		$query->selectRaw(
			'CASE WHEN ' . $query->grammar->wrap('cf.cluster_id') . ' = 0 '.
			'THEN ? '.
			'ELSE ' .$query->grammar->wrap('cf.long_name'). ' '.
			'END AS ' . $query->grammar->wrap('faded')
		)->addBinding('N/A', 'select');
		return $query;
	}
}
