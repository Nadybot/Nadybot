<?php declare(strict_types=1);

namespace Nadybot\Modules\WHOMPAH_MODULE;

use Nadybot\Core\CommandAlias;
use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\Text;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'whompah',
 *		accessLevel = 'all',
 *		description = 'Shows the whompah route from one city to another',
 *		help        = 'whompah.txt'
 *	)
 */
class WhompahController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public CommandAlias $commandAlias;
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'whompah_cities');
		
		$this->commandAlias->register($this->moduleName, 'whompah', 'whompahs');
		$this->commandAlias->register($this->moduleName, 'whompah', 'whompa');
		$this->commandAlias->register($this->moduleName, 'whompah', 'whompas');
	}
	
	/**
	 * @HandlesCommand("whompah")
	 * @Matches("/^whompah$/i")
	 */
	public function whompahListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sql = "SELECT * FROM `whompah_cities` ORDER BY city_name ASC";
		/** @var WhompahCity[] */
		$data = $this->db->fetchAll(WhompahCity::class, $sql);

		$blob = "<header2>All known cities with Whom-Pahs<end>\n";
		foreach ($data as $row) {
			$cityLink = $this->text->makeChatcmd($row->short_name, "/tell <myname> whompah {$row->short_name}");
			$blob .= "<tab>{$row->city_name} ({$cityLink})\n";
		}
		$blob .= "\nWritten By Tyrence (RK2)\nDatabase from a Bebot module written by POD13";

		$msg = $this->text->makeBlob('Whompah Cities', $blob);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("whompah")
	 * @Matches("/^whompah (.+) (.+)$/i")
	 */
	public function whompahTravelCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$startCity = $this->findCity($args[1]);
		$endCity   = $this->findCity($args[2]);

		if ($startCity === null) {
			$msg = "Error! Could not find city <highlight>{$args[1]}<end>.";
			$sendto->reply($msg);
			return;
		}
		if ($endCity === null) {
			$msg = "Error! Could not find city <highlight>$args[2]<end>.";
			$sendto->reply($msg);
			return;
		}

		$whompahs = $this->buildWhompahNetwork();

		$whompah = clone $endCity;
		$whompah->visited = true;
		$obj = $this->findWhompahPath([$whompah], $whompahs, $startCity->id);

		if ($obj === null) {
			$msg = "There was an error while trying to find the whompah path.";
			$sendto->reply($msg);
			return;
		}
		$cities = [];
		while ($obj !== null) {
			$cities []= $obj;
			$obj = $obj->previous;
		}
		$cityList = $this->getColoredNamelist($cities);
		$msg = implode(" -> ", $cityList);

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("whompah")
	 * @Matches("/^whompah (.+)$/i")
	 */
	public function whompahDestinationsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$city = $this->findCity($args[1]);

		if ($city === null) {
			$msg = "Error! Could not find city <highlight>{$args[1]}<end>.";
			$sendto->reply($msg);
			return;
		}

		$sql = "SELECT w2.* FROM whompah_cities_rel w1 ".
			"JOIN whompah_cities w2 ON w1.city2_id = w2.id ".
			"WHERE w1.city1_id = ? ORDER BY w2.city_name ASC";
		/** @var WhompahCity[] */
		$cities = $this->db->fetchAll(WhompahCity::class, $sql, $city->id);

		$msg = "From <highlight>{$city->city_name}<end> you can get to\n- " .
			implode("\n- ", $this->getColoredNamelist($cities));

		$sendto->reply($msg);
	}

	/**
	 * @param WhompahCity[] $cities
	 * @return string[]
	 */
	protected function getColoredNamelist(array $cities): array {
		return array_map(function(WhompahCity $city) {
			$faction = strtolower($city->faction);
			if ($faction === 'neutral') {
				$faction = 'green';
			}
			return "<$faction>$city->city_name<end>";
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
		$sql = "SELECT * FROM whompah_cities ".
			"WHERE city_name LIKE ? OR short_name LIKE ?";
		return $this->db->fetch(WhompahCity::class, $sql, $search, $search);
	}

	/**
	 * @return array<int,WhompahCity>
	 */
	public function buildWhompahNetwork(): array {
		/** @var array<int,WhompahCity> */
		$whompahs = [];

		$sql = "SELECT * FROM `whompah_cities`";
		/** @var WhompahCity[] */
		$cities = $this->db->fetchAll(WhompahCity::class, $sql);
		foreach ($cities as $city) {
			$whompahs[$city->id] = $city;
		}

		$sql = "SELECT city1_id, city2_id FROM whompah_cities_rel";
		$cities = $this->db->query($sql);
		foreach ($cities as $city) {
			$whompahs[$city->city1_id]->connections[] = (int)$city->city2_id;
		}

		return $whompahs;
	}
}
