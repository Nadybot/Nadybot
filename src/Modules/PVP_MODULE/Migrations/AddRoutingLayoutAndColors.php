<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\{RouteHopColor, RouteHopFormat};
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_03_14_06_12_25)]
class AddRoutingLayoutAndColors implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		if ($db->table(RouteHopFormat::getTable())->whereIlike('hop', 'pvp%')->exists()) {
			return;
		}
		$db->table(RouteHopFormat::getTable())->insert([
			'hop' => 'pvp',
			'render' => true,
			'format' => 'PVP',
		]);

		if ($db->table(RouteHopColor::getTable())->whereIlike('hop', 'pvp%')->exists()) {
			return;
		}
		$db->table(RouteHopColor::getTable())->insert([
			'hop' => 'pvp',
			'tag_color' => 'F06AED',
		]);
	}
}
