<?php declare(strict_types=1);

namespace Nadybot\Modules\WHEREIS_MODULE;

use DateTime;
use DateTimeZone;
use Exception;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
};

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'spawntime',
		accessLevel: 'guest',
		description: 'Show (re)spawntimers',
		alias: 'spawn',
	)
]
class SpawntimeController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

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
			if ($row->playfield_id !== 0 && $row->xcoord !== 0 && $row->ycoord !== 0) {
				$blob .= " [" . $row->toWaypoint() . "]";
			}
			$blob .= "\n\n";
		}
		$msg = $this->text->makeBlob("locations (" . count($spawntime->coordinates).")", $blob);
		if (is_array($msg)) {
			throw new Exception("Too many spawn locations for {$spawntime->mob}.");
		}
		return $msg;
	}

	/** List all spawn times */
	#[NCA\HandlesCommand("spawntime")]
	public function spawntimeListCommand(CmdContext $context): void {
		$spawnTimes = $this->db->table("spawntime")->asObj(Spawntime::class);
		if ($spawnTimes->isEmpty()) {
			$msg = 'There are currently no spawntimes in the database.';
			$context->reply($msg);
			return;
		}
		$timeLines = $this->spawntimesToLines($spawnTimes);
		$msg = $this->text->makeBlob('All known spawntimes', $timeLines->join("\n"));
		$context->reply($msg);
	}

	/** Search for spawn times */
	#[NCA\HandlesCommand("spawntime")]
	public function spawntimeSearchCommand(CmdContext $context, string $search): void {
		$tokens = explode(" ", $search);
		$query = $this->db->table("spawntime");
		$this->db->addWhereFromParams($query, $tokens, "mob");
		$this->db->addWhereFromParams($query, $tokens, "placeholder", "or");
		$this->db->addWhereFromParams($query, $tokens, "alias", "or");
		$spawnTimes = $query->asObj(Spawntime::class);
		if ($spawnTimes->isEmpty()) {
			$msg = "No spawntime matching <highlight>{$search}<end>.";
			$context->reply($msg);
			return;
		}
		$timeLines = $this->spawntimesToLines($spawnTimes);
		$count = $timeLines->count();
		if ($count === 1) {
			$msg = $timeLines->first();
		} elseif ($count < 4) {
			$msg = "Spawntimes matching <highlight>{$search}<end>:\n".
				$timeLines->join("\n");
		} else {
			$msg = $this->text->makeBlob(
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
			$time = new DateTime('now', new DateTimeZone('UTC'));
			$time->setTimestamp($row->spawntime);
			$line .= "<orange>" . $time->format('H\hi\ms\s') . "<end>";
		} else {
			$line .= "<orange>&lt;unknown&gt;<end>";
		}
		$line = preg_replace('/00[hms]/', '', $line);
		$line = preg_replace('/>0/', '>', $line);
		$flags = [];
		if ($row->can_skip_spawn) {
			$flags[] = 'can skip spawn';
		}
		if (isset($row->placeholder) && strlen($row->placeholder)) {
			$flags[] = "placeholder: " . $row->placeholder;
		}
		if (count($flags)) {
			$line .= ' (<highlight>' . join(', ', $flags) . '<end>)';
		}
		if ($displayDirectly === true && $row->coordinates->count()) {
			$line .= " [" . $this->getLocationBlob($row) . "]";
		} elseif ($row->coordinates->count() > 1) {
			$line .= " [" .
				$this->text->makeChatcmd(
					"locations (" . count($row->coordinates) . ")",
					"/tell <myname> whereis " . $row->mob
				).
				"]";
		} elseif ($row->coordinates->count() === 1) {
			/** @var WhereisResult */
			$coords = $row->coordinates->firstOrFail();
			if ($coords->playfield_id != 0 && $coords->xcoord != 0 && $coords->ycoord != 0) {
				$line .= " [". $coords->toWaypoint() . "]";
			}
		}
		return $line;
	}

	/**
	 * @param Collection<Spawntime> $spawnTimes
	 *
	 * @return Collection<string>
	 */
	protected function spawntimesToLines(Collection $spawnTimes): Collection {
		$mobs = $this->whereisController->getAll();
		$spawnTimes->each(function (Spawntime $spawn) use ($mobs) {
			$spawn->coordinates = $mobs->filter(
				function (WhereisResult $row) use ($spawn): bool {
					return strncasecmp($row->name, $spawn->mob, strlen($spawn->mob)) === 0;
				}
			)->values();
		});
		$displayDirectly = $spawnTimes->count() < 4;

		/** @var Collection<string> */
		$result = $spawnTimes->map(function (Spawntime $spawn) use ($displayDirectly): string {
			return $this->getMobLine($spawn, $displayDirectly);
		});
		return $result;
	}
}
