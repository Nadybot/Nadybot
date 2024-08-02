<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Nadybot\Core\DBSchema\{RouteHopColor, RouteHopFormat};
use Nadybot\Core\{Attributes as NCA, DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_03_08_16_39_57)]
class SetSiteTrackerRoutingFormat implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$towerColor = 'F06AED';
		$hopColor = [
			'hop' => 'site-tracker',
			'tag_color' => $towerColor,
			'text_color' => null,
		];
		$db->table(RouteHopColor::getTable())->insert($hopColor);

		$hopFormat = [
			'hop' => 'site-tracker',
			'format' => 'SITE-TRACKER-%s',
			'render' => true,
		];
		$db->table(RouteHopFormat::getTable())->insert($hopFormat);
	}
}
