<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
	Types\Playfield,
};

/**
 * @author Jaqueme
 * Database adapted from one originally compiled by Malosar for BeBot
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'whereis',
		accessLevel: 'guest',
		description: 'Shows where places and NPCs are',
	)
]
class WhereisController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/whereis.csv');
	}

	/** @return Collection<int,Whereis> */
	public function getByName(string ...$name): Collection {
		return $this->db->table(Whereis::getTable())
			->whereIn('name', $name)
			->asObj(Whereis::class);
	}

	/** @return Collection<int,Whereis> */
	public function getAll(): Collection {
		return $this->db->table(Whereis::getTable())->asObj(Whereis::class);
	}

	/** Show the location of NPCs or places */
	#[NCA\HandlesCommand('whereis')]
	#[NCA\Help\Example('<symbol>whereis elmer ragg')]
	#[NCA\Help\Example('<symbol>whereis prisoner')]
	#[NCA\Help\Example('<symbol>whereis 12m')]
	public function whereisCommand(CmdContext $context, string $search): void {
		$search = strtolower($search);
		$words = explode(' ', $search);
		$query = $this->db->table(Whereis::getTable());
		$this->db->addWhereFromParams($query, $words, 'name');
		$this->db->addWhereFromParams($query, $words, 'keywords', 'or');

		$lines = $query->asObj(Whereis::class)
			->map(static function (Whereis $npc): string {
				$line = "<pagebreak><header2>{$npc->name}<end>\n".
					"<tab>{$npc->answer}";
				if ($npc->playfield !== Playfield::Unknown && $npc->xcoord !== 0 && $npc->ycoord !== 0) {
					$line .= ' ' . $npc->toWaypoint();
				}
				return $line;
			});
		$count = $lines->count();

		if ($count === 0) {
			$msg = 'There were no matches for your search.';
			$context->reply($msg);
			return;
		}
		$blob = $lines->join("\n\n");

		$msg = Text::makeBlob("Matches for \"{$search}\" ({$count})", $blob);
		$context->reply($msg);
	}
}
