<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Illuminate\Support\Collection;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	ParamClass\PWord,
	Safe,
	Text,
	Types\ImplantSlot,
};
use Nadybot\Modules\ITEMS_MODULE\ItemsController;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Pocketboss'),
	NCA\DefineCommand(
		command: 'pocketboss',
		accessLevel: 'guest',
		description: 'Shows what symbiants a pocketboss drops',
		alias: 'pb'
	),
	NCA\DefineCommand(
		command: 'symbiant',
		accessLevel: 'guest',
		description: 'Shows which pocketbosses drop a symbiant',
		alias: 'symb'
	)
]
class PocketbossController extends ModuleInstance {
	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private ItemsController $itemsController;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/pocketboss.csv');
	}

	/** Show a list of Symbiants that a Pocketboss drops */
	#[NCA\HandlesCommand('pocketboss')]
	public function pocketbossCommand(CmdContext $context, string $search): void {
		$data = $this->pbSearchResults($search);
		$numrows = count($data);
		$blob = '';
		if ($numrows === 0) {
			$msg = 'Could not find any pocket bosses that matched your search criteria.';
		} elseif ($numrows === 1) {
			$name = $data[0]->pb;
			$blob .= $this->singlePbBlob($name);
			$msg = $this->text->makeBlob("Remains of {$name}", $blob);
		} else {
			$blob = '';
			foreach ($data as $row) {
				$pbLink = Text::makeChatcmd($row->pb, "/tell <myname> pocketboss {$row->pb}");
				$blob .= $pbLink . "\n";
			}
			$msg = $this->text->makeBlob("Search results for {$search} ({$numrows})", $blob);
		}
		$context->reply($msg);
	}

	public function singlePbBlob(string $name): string {
		$data = $this->db->table(Pocketboss::getTable())
			->where('pb', $name)
			->orderBy('ql')
			->asObj(Pocketboss::class);
		if ($data->isEmpty()) {
			return '';
		}
		$symbs = '';
		foreach ($data as $symb) {
			if ($symb->type === 'Special') {
				$name = $this->itemsController->findById($symb->itemid)?->getName() ?? 'Unknown';
			} elseif (in_array($symb->line, ['Alpha', 'Beta'], true)) {
				$name = "Xan {$symb->slot} Symbiant, {$symb->type} Unit {$symb->line}";
			} else {
				$name = "{$symb->line} {$symb->slot} Symbiant, {$symb->type} Unit Aban";
			}
			$symbs .= '<tab>* ' . Text::makeItem($symb->itemid, $symb->itemid, $symb->ql, $name);
			if ($symb->ql !== 1) {
				$symbs .= " ({$symb->ql})";
			}
			$symbs .= "\n";
		}
		assert(isset($symb));

		$blob = "<header2>Basic info<end>\n";
		$blob .= "<tab>Location: <highlight>{$symb->pb_location}<end>";
		if ($symb->bp_location !== $symb->pb_location) {
			$blob .= ", <highlight>{$symb->bp_location}<end>";
		}
		$blob .= "\n<tab>Found on: <highlight>{$symb->bp_mob}<end>, Level <highlight>{$symb->bp_lvl}<end>\n\n";
		$blob .= "<header2>Symbiants<end>\n".
			$symbs;

		return $blob;
	}

	/** @return list<Pocketboss> */
	public function pbSearchResults(string $search): array {
		if ($search === 'tnh') {
			$search = 'The Night Heart';
		}
		$row = $this->db->table(Pocketboss::getTable())
			->whereIlike('pb', $search)
			->orderBy('pb')
			->limit(1)
			->asObj(Pocketboss::class)
			->first();
		if ($row !== null) {
			return [$row];
		}

		$query = $this->db->table(Pocketboss::getTable())
			->orderBy('pb');
		$tmp = explode(' ', $search);
		$this->db->addWhereFromParams($query, $tmp, 'pb');

		return $query->asObj(Pocketboss::class)
			->groupBy('pb')
			->map(static fn (Collection $col): Pocketboss => $col->firstOrFail())
			->values()
			->toList();
	}

	/**
	 * Show a list of symbiants and which pocketboss drops it
	 *
	 * The arguments are either a slot name (rhand), a type (artillery) or a line (living).
	 * You can use 1, 2 or 3 of these arguments or their abbreviations in any order to search.
	 */
	#[NCA\HandlesCommand('symbiant')]
	#[NCA\Help\Example('<symbol>symbiant brain alpha arti')]
	#[NCA\Help\Example('<symbol>symbiant alpha rhand')]
	#[NCA\Help\Example('<symbol>symbiant inf')]
	#[NCA\Help\Example('<symbol>symbiant inf living')]
	#[NCA\Help\Example('<symbol>symbiant beta control')]
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

		/** @var list<string> */
		$args = Safe::removeNull([$args[1], $args[2]??null, $args[3]??null]);
		$paramCount = count($args);

		$slot = '%';
		$symbtype = '%';
		$line = '%';

		$lines = $this->db->table(Pocketboss::getTable())->select('line')->distinct()
			->pluckStrings('line');

		$impDesignSlot = null;
		for ($i = 0; $i < $paramCount; $i++) {
			try {
				$impSlot = ImplantSlot::byName($args[$i]);
				$impDesignSlot = $impSlot->designSlotName();
				$slot = $impSlot->longName();
				continue;
			} catch (\Throwable) {
			}
			// check if it's a line
			foreach ($lines as $l) {
				if (strtolower($l) === strtolower($args[$i])) {
					$line = $l;
					continue 2;
				}
			}

			try {
				$symbtype = SymbiantType::byName($args[$i])->name;
				continue;
			} catch (\Throwable) {
			}

			// check if it's a line, but be less strict this time
			$matchingLines = $lines->filter(
				static function (string $line) use ($args, $i): bool {
					return strncasecmp($line, $args[$i], strlen($args[$i])) === 0;
				}
			);
			if (count($matchingLines) === 1) {
				$line = $matchingLines->firstOrFail();
				break;
			}
			$context->reply(
				"I cannot find any symbiant line, location or type '<highlight>{$args[$i]}<end>'."
			);
			return;
		}

		$query = $this->db->table(Pocketboss::getTable())
			->whereIlike('slot', $slot)
			->whereIlike('type', $symbtype)
			->whereIlike('line', $line)
			->orderByDesc('ql');
		$query->orderByRaw($query->grammar->wrap('line') . ' = ? desc')
			->addBinding('Alpha')
			->orderByRaw($query->grammar->wrap('line') . ' = ? desc')
			->addBinding('Beta')
			->orderBy('type');

		$data = $query->asObj(Pocketboss::class);
		$numrows = count($data);
		if ($numrows === 0) {
			$msg = 'Could not find any symbiants that matched your search criteria.';
			$context->reply($msg);
			return;
		}
		$implantDesignerLink = Text::makeChatcmd('implant designer', '/tell <myname> implantdesigner');
		$blob = "Click '[add]' to add symbiant to {$implantDesignerLink}.\n\n";

		/** @param Collection<int,Pocketboss> $rows */
		$blob = $data->groupBy('itemid')
			->map(function (Collection $rows, int $itemid) use (&$impDesignSlot): string {
				$symbiant = $rows->firstOrFail();
				if ($symbiant->type === 'Special') {
					$name = $this->itemsController->findById($symbiant->itemid)?->getName() ?? 'Unknown';
				} elseif (in_array($symbiant->line, ['Alpha', 'Beta'], true)) {
					$name = "Xan {$symbiant->slot} Symbiant, {$symbiant->type} Unit {$symbiant->line}";
				} else {
					$name = "{$symbiant->line} {$symbiant->slot} Symbiant, {$symbiant->type} Unit Aban";
				}
				$blob = '<pagebreak>' . Text::makeItem($symbiant->itemid, $symbiant->itemid, $symbiant->ql, $name);
				if ($symbiant->ql !== 1) {
					$blob .= " ({$symbiant->ql})";
				}
				if (isset($impDesignSlot)) {
					$impDesignerAddLink = Text::makeChatcmd('add', "/tell <myname> implantdesigner {$impDesignSlot} symb {$name}");
					$blob .= " [{$impDesignerAddLink}]";
				}
				$blob .= "\n";
				$blob .= 'Found on '.
					$rows->map(static function (Pocketboss $boss): string {
						return Text::makeChatcmd($boss->pb, "/tell <myname> pb {$boss->pb}");
					})->join(', ', ', and ');
				return $blob;
			})->join("\n\n");
		$msg = $this->text->makeBlob("Symbiant Search Results ({$numrows})", $blob);
		$context->reply($msg);
	}
}
