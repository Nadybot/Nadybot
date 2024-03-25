<?php declare(strict_types=1);

namespace Nadybot\Modules\MOB_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\{RouteHopColor, RouteHopFormat};
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{DB, MessageHub, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2023_03_31_21_14_36)]
class SetRouteFormat implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$rhf = new RouteHopFormat(
			hop: 'mobs',
			render: false,
			format: 'MOBS',
		);
		$db->insert(Source::DB_TABLE, $rhf);

		$rhc = new RouteHopColor(
			hop: 'mobs',
			tag_color: '00A9B5',
		);
		$db->insert(MessageHub::DB_TABLE_COLORS, $rhc);
	}
}
