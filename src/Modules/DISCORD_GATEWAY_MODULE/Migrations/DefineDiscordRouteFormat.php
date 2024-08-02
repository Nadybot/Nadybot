<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\{Route, RouteHopColor, RouteHopFormat};
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_05_15_14_37_04)]
class DefineDiscordRouteFormat implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$db->table(RouteHopFormat::getTable())->insert([
			'hop' => 'discord',
			'render' => false,
			'format' => 'DISCORD',
		]);

		$db->table(RouteHopColor::getTable())->insert([
			'hop' => 'discord',
			'tag_color' => 'C3C3C3',
		]);

		$db->table(Route::getTable())->insert([
			'source' => 'discord(*)',
			'destination' => Source::ORG,
			'two_way' => false,
		]);
	}
}
