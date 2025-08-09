<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

use DateTimeZone;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	Collection,
	DB,
	ModuleInstance,
	Safe,
	Text,
	Types\AccessLevel,
	Types\Playfield,
};
use Safe\DateTimeImmutable;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'spawntime',
		accessLevel: AccessLevel::Guest,
		description: 'Show (re)spawntimers',
		alias: 'spawn',
	)
]
class SpawntimeController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private WhereisController $whereisController;

	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/spawntime.csv');
	}

	public function getLocationBlob(Spawntime $spawntime): string {
		$blob = '';
		foreach ($spawntime->coordinates as $row) {
			$blob .= "<header2>{$row->name}<end>\n".
				"{$row->answer}";
			if ($row->playfield !== Playfield::Unknown && $row->xcoord !== 0 && $row->ycoord !== 0) {
				$blob .= ' [' . $row->toWaypoint() . ']';
			}
			$blob .= "\n\n";
		}
		$msg = Text::makeBlob('locations (' . count($spawntime->coordinates).')', $blob);
		return $msg;
	}

	/** List all spawn times */
	#[NCA\HandlesCommand('spawntime')]
	public function spawntimeListCommand(CmdContext $context): void {
		$spawnTimes = $this->db->table(Spawntime::getTable())->asObj(Spawntime::class);
		if ($spawnTimes->isEmpty()) {
			$msg = 'There are currently no spawntimes in the database.';
			$context->reply($msg);
			return;
		}
		$timeLines = $this->spawntimesToLines($spawnTimes);
		$msg = Text::makeBlob('All known spawntimes', $timeLines->join("\n"));
		$context->reply($msg);
	}

	/** Search for spawn times */
	#[NCA\HandlesCommand('spawntime')]
	public function spawntimeSearchCommand(CmdContext $context, string $search): void {
		$tokens = explode(' ', $search);
		$query = $this->db->table(Spawntime::getTable());
		$this->db->addWhereFromParams($query, $tokens, 'mob');
		$this->db->addWhereFromParams($query, $tokens, 'placeholder', 'or');
		$this->db->addWhereFromParams($query, $tokens, 'alias', 'or');
		$spawnTimes = $query->asObj(Spawntime::class);
		if ($spawnTimes->isEmpty()) {
			$msg = "No spawntime matching <highlight>{$search}<end>.";
			$context->reply($msg);
			return;
		}
		$timeLines = $this->spawntimesToLines($spawnTimes);
		$count = $timeLines->count();
		if ($count === 1) {
			$msg = $timeLines->firstOrFail();
		} elseif ($count < 4) {
			$msg = "Spawntimes matching <highlight>{$search}<end>:\n".
				$timeLines->join("\n");
		} else {
			$msg = Text::makeBlob(
				"Spawntimes for \"{$search}\" ({$count})",
				$timeLines->join("\n")
			);
		}
		$context->reply($msg);
	}

	/** Return the formatted entry for one mob */
	protected function getMobLine(Spawntime $row, bool $displayDirectly): string {
		$line = "{$row->mob}: ";
		if ($row->spawntime !== null) {
			$time = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
				->setTimestamp($row->spawntime);
			$line .= '<orange>' . $time->format('H\hi\ms\s') . '<end>';
		} else {
			$line .= '<orange>&lt;unknown&gt;<end>';
		}
		$line = Safe::pregReplace('/00[hms]/', '', $line);

		/** @var string */
		$line = str_replace('>0', '>', $line);

		/** @var list<string> */
		$flags = [];
		if ($row->can_skip_spawn) {
			$flags []= 'can skip spawn';
		}
		if (isset($row->placeholder) && strlen($row->placeholder)) {
			$flags []= 'placeholder: ' . $row->placeholder;
		}
		if (count($flags)) {
			$line .= ' (<highlight>' . implode(', ', $flags) . '<end>)';
		}
		if ($displayDirectly === true && $row->coordinates->count()) {
			$line .= ' [' . $this->getLocationBlob($row) . ']';
		} elseif ($row->coordinates->count() > 1) {
			$line .= ' [' .
				Text::makeChatcmd(
					'locations (' . count($row->coordinates) . ')',
					'/tell <myname> whereis ' . $row->mob
				).
				']';
		} elseif ($row->coordinates->count() === 1) {
			/** @var Whereis */
			$coords = $row->coordinates->firstOrFail();
			if ($coords->playfield !== Playfield::Unknown && $coords->xcoord !== 0 && $coords->ycoord !== 0) {
				$line .= ' ['. $coords->toWaypoint() . ']';
			}
		}
		return $line;
	}

	/**
	 * @param Collection<int,Spawntime> $spawnTimes
	 *
	 * @return Collection<int,string>
	 */
	protected function spawntimesToLines(Collection $spawnTimes): Collection {
		$mobs = $this->whereisController->getAll();
		$spawnTimes->each(static function (Spawntime $spawn) use ($mobs): void {
			$spawn->coordinates = $mobs->filter(
				static function (Whereis $row) use ($spawn): bool {
					return strncasecmp($row->name, $spawn->mob, strlen($spawn->mob)) === 0;
				}
			)->values();
		});
		$displayDirectly = $spawnTimes->count() < 4;

		$result = $spawnTimes->map(function (Spawntime $spawn) use ($displayDirectly): string {
			return $this->getMobLine($spawn, $displayDirectly);
		});
		return $result;
	}
}
