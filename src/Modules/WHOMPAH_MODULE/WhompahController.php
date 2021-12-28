<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOMPAH_MODULE;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Support\Collection;
use Nadybot\Core\CmdContext;
use Nadybot\Core\CommandAlias;
use Nadybot\Core\DB;
use Nadybot\Core\ParamClass\PWord;
use Nadybot\Core\Text;

/**
 * @author Tyrence (RK2)
 * Commands this controller contains:
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: "whompah",
		accessLevel: "all",
		description: "Shows the whompah route from one city to another",
		help: "whompah.txt"
	)
]
class WhompahController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public CommandAlias $commandAlias;

	/**
	 * This handler is called on bot startup.
	 */
	#[NCA\Setup]
	public function setup(): void {
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/whompah_cities.csv");
		$this->db->loadCSVFile($this->moduleName, __DIR__ . "/whompah_cities_rel.csv");

		$this->commandAlias->register($this->moduleName, 'whompah', 'whompahs');
		$this->commandAlias->register($this->moduleName, 'whompah', 'whompa');
		$this->commandAlias->register($this->moduleName, 'whompah', 'whompas');
	}

	/**
	 * Shows a list of known cities
	 */
	#[NCA\HandlesCommand("whompah")]
	public function whompahListCommand(CmdContext $context): void {
		/** @var Collection<WhompahCity> */
		$data = $this->db->table("whompah_cities")->orderBy("city_name")->asObj();

		$blob = "<header2>All known cities with Whom-Pahs<end>\n";
		foreach ($data as $row) {
			$cityLink = $this->text->makeChatcmd($row->short_name, "/tell <myname> whompah {$row->short_name}");
			$blob .= "<tab>{$row->city_name} ({$cityLink})\n";
		}
		$blob .= "\nWritten By Tyrence (RK2)\nDatabase from a Bebot module written by POD13";

		$msg = $this->text->makeBlob('Whompah Cities', $blob);

		$context->reply($msg);
	}

	/**
	 * Searches for a whompah-route from start to end
	 */
	#[NCA\HandlesCommand("whompah")]
	public function whompahTravelCommand(CmdContext $context, PWord $start, PWord $end): void {
		$startCity = $this->findCity($start());
		$endCity   = $this->findCity($end());

		if ($startCity === null) {
			$msg = "Error! Could not find city <highlight>{$start}<end>.";
			$context->reply($msg);
			return;
		}
		if ($endCity === null) {
			$msg = "Error! Could not find city <highlight>{$end}<end>.";
			$context->reply($msg);
			return;
		}

		$whompahs = $this->buildWhompahNetwork();

		$whompah = clone $endCity;
		$whompah->visited = true;
		$obj = $this->findWhompahPath([$whompah], $whompahs, $startCity->id);

		if ($obj === null) {
			$msg = "There was an error while trying to find the whompah path.";
			$context->reply($msg);
			return;
		}
		$cities = [];
		while ($obj !== null) {
			$cities []= $obj;
			$obj = $obj->previous;
		}
		$cityList = $this->getColoredNamelist($cities);
		$msg = implode(" -> ", $cityList);

		$context->reply($msg);
	}

	/**
	 * Shows all whompah-connections of a city
	 */
	#[NCA\HandlesCommand("whompah")]
	public function whompahDestinationsCommand(CmdContext $context, string $cityName): void {
		$city = $this->findCity($cityName);

		if ($city === null) {
			$msg = "Error! Could not find city <highlight>{$cityName}<end>.";
			$context->reply($msg);
			return;
		}

		/** @var WhompahCity[] */
		$cities = $this->db->table("whompah_cities_rel AS w1")
			->join("whompah_cities AS w2", "w1.city2_id", "w2.id")
			->where("w1.city1_id", $city->id)
			->orderBy("w2.city_name")
			->select("w2.*")
			->asObj(WhompahCity::class)->toArray();

		$msg = "From <highlight>{$city->city_name}<end> ({$city->short_name}) you can get to\n- " .
			implode("\n- ", $this->getColoredNamelist($cities, true));

		$context->reply($msg);
	}

	/**
	 * @param WhompahCity[] $cities
	 * @return string[]
	 */
	protected function getColoredNamelist(array $cities, bool $addShort=false): array {
		return array_map(function(WhompahCity $city) use ($addShort): string {
			$faction = strtolower($city->faction);
			if ($faction === 'neutral') {
				$faction = 'green';
			}
			$coloredName = "<{$faction}>{$city->city_name}<end>";
			if ($addShort) {
				$coloredName .= " ({$city->short_name})";
			}
			return $coloredName;
		}, $cities);
	}

	/**
	 * @param WhompahCity[] $queue
	 * @param array<int,WhompahCity> $whompahs
	 * @param int $endCity
	 * @return ?WhompahCity
	 */
	public function findWhompahPath(array $queue, array $whompahs, int $endCity): ?WhompahCity {
		$currentWhompah = array_shift($queue);

		if ($currentWhompah === null) {
			return null;
		}

		if ($currentWhompah->id === $endCity) {
			return $currentWhompah;
		}

		foreach ($whompahs[$currentWhompah->id]->connections as $city2Id) {
			if ($whompahs[$city2Id]->visited !== true) {
				$whompahs[$city2Id]->visited = true;
				$nextWhompah = clone $whompahs[$city2Id];
				$nextWhompah->previous = $currentWhompah;
				$queue []= $nextWhompah;
			}
		}

		return $this->findWhompahPath($queue, $whompahs, $endCity);
	}

	public function findCity(string $search): ?WhompahCity {
		$q1 = $this->db->table("whompah_cities")->whereIlike("city_name", $search)
			->orWhereIlike("short_name", $search);
		$q2 = $this->db->table("whompah_cities")->whereIlike("city_name", "%{$search}%")
			->orWhereIlike("short_name", "%{$search}%");
		return $q1->asObj(WhompahCity::class)->first()
			?: $q2->asObj(WhompahCity::class)->first();
	}

	/**
	 * @return array<int,WhompahCity>
	 */
	public function buildWhompahNetwork(): array {
		/** @var array<int,WhompahCity> */
		$whompahs = $this->db->table("whompah_cities")->asObj(WhompahCity::class)
			->keyBy("id")->toArray();

		$this->db->table("whompah_cities_rel")->orderBy("city1_id")
			->each(function(object $city) use ($whompahs) {
				$whompahs[$city->city1_id]->connections ??= [];
				$whompahs[$city->city1_id]->connections[] = (int)$city->city2_id;
			});

		return $whompahs;
	}
}
