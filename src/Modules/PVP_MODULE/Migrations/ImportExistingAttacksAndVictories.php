<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\PVP_MODULE\{DBOutcome, DBTowerAttack, NotumWarsController};
use Psr\Log\LoggerInterface;
use stdClass;

#[NCA\MigrationOrder(20230309083420)]
class ImportExistingAttacksAndVictories implements SchemaMigration {
	private const CHUNK_SIZE = 100;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$offset = 0;
		if (!$db->schema()->hasTable("tower_attack_<myname>")) {
			return;
		}
		do {
			$processed = 0;
			$db->table("tower_attack_<myname>")
				->orderBy("time")
				->offset($offset)
				->limit(self::CHUNK_SIZE)
				->get()
				->each(function (stdClass $old) use ($db, &$processed): void {
					$processed++;
					$attack = new DBTowerAttack();
					try {
						$attack->timestamp = $old->time;
						$attack->att_org = $old->att_guild_name;
						$attack->att_faction = $old->att_faction;
						$attack->att_name = $old->att_player;
						$attack->att_level = $old->att_level;
						$attack->att_ai_level = $old->att_ai_level;
						$attack->att_profession = $old->att_profession;
						$attack->def_org = $old->def_guild_name;
						$attack->def_faction = $old->def_faction;
						$attack->playfield_id = $old->playfield_id;
						$attack->site_id = $old->site_number;
						$attack->location_x = $old->x_coords;
						$attack->location_y = $old->y_coords;
						$db->insert(NotumWarsController::DB_ATTACKS, $attack, null);
					} catch (\Throwable $e) {
						// Ignore incomplete data for now
					}
				});
			$offset += $processed;
		} while ($processed === self::CHUNK_SIZE);

		$offset = 0;
		do {
			$processed = 0;
			$db->table("tower_victory_<myname>", "tv")
				->join("tower_attack_<myname> AS ta", "tv.attack_id", "=", "ta.id")
				->orderBy("time")
				->offset($offset)
				->limit(self::CHUNK_SIZE)
				->select(["tv.*", "ta.playfield_id", "ta.site_number"])
				->get()
				->each(function (stdClass $old) use ($db, &$processed): void {
					$processed++;
					$outcome = new DBOutcome();
					try {
						$outcome->timestamp = $old->time;
						$outcome->attacker_org = $old->win_guild_name;
						$outcome->attacker_faction = $old->win_faction;
						$outcome->losing_org = $old->lose_guild_name;
						$outcome->losing_faction = $old->lose_faction;
						$outcome->playfield_id = $old->playfield_id;
						$outcome->site_id = $old->site_number;
						$db->insert(NotumWarsController::DB_OUTCOMES, $outcome, null);
					} catch (\Throwable $e) {
						// Ignore incomplete data for now
					}
				});
			$offset += $processed;
		} while ($processed === self::CHUNK_SIZE);
	}
}
