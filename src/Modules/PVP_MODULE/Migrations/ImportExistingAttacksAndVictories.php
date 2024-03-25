<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Faction, SchemaMigration};
use Nadybot\Modules\PVP_MODULE\{DBOutcome, DBTowerAttack, NotumWarsController};
use Psr\Log\LoggerInterface;
use stdClass;

#[NCA\Migration(order: 20_230_309_083_420)]
class ImportExistingAttacksAndVictories implements SchemaMigration {
	private const CHUNK_SIZE = 100;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$offset = 0;
		if (!$db->schema()->hasTable('tower_attack_<myname>')) {
			return;
		}
		do {
			$processed = 0;
			$db->table('tower_attack_<myname>')
				->orderBy('time')
				->offset($offset)
				->limit(self::CHUNK_SIZE)
				->get()
				->each(static function (stdClass $old) use ($db, &$processed): void {
					$processed++;
					try {
						$attack = new DBTowerAttack(
							timestamp: $old->time,
							playfield_id: $old->playfield_id,
							location_x: $old->x_coords,
							location_y: $old->y_coords,
							site_id: $old->site_number,
							ql: null,
							att_org: $old->att_guild_name,
							att_faction: $old->att_faction,
							att_name: $old->att_player,
							att_level: $old->att_level,
							att_ai_level: $old->att_ai_level,
							att_profession: $old->att_profession,
							def_org: $old->def_guild_name,
							def_faction: $old->def_faction,
						);
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
			$db->table('tower_victory_<myname>', 'tv')
				->join('tower_attack_<myname> AS ta', 'tv.attack_id', '=', 'ta.id')
				->orderBy('time')
				->offset($offset)
				->limit(self::CHUNK_SIZE)
				->select(['tv.*', 'ta.playfield_id', 'ta.site_number'])
				->get()
				->each(static function (stdClass $old) use ($db, &$processed): void {
					$processed++;
					try {
						$outcome = new DBOutcome(
							timestamp: $old->time,
							attacker_org: $old->win_guild_name,
							attacker_faction: Faction::tryFrom($old->win_faction??''),
							losing_org: $old->lose_guild_name,
							losing_faction: Faction::tryFrom($old->lose_faction??'') ?? Faction::Unknown,
							playfield_id: $old->playfield_id,
							site_id: $old->site_number,
						);
						$db->insert(NotumWarsController::DB_OUTCOMES, $outcome, null);
					} catch (\Throwable $e) {
						// Ignore incomplete data for now
					}
				});
			$offset += $processed;
		} while ($processed === self::CHUNK_SIZE);
	}
}
