<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\{RouteHopColor, RouteHopFormat};
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{DB, MessageHub, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_230_314_061_225)]
class AddRoutingLayoutAndColors implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		if ($db->table(Source::DB_TABLE)->whereIlike('hop', 'pvp%')->exists()) {
			return;
		}
		$rhf = new RouteHopFormat(
			hop: 'pvp',
			render: true,
			format: 'PVP',
		);
		$db->insert(Source::DB_TABLE, $rhf);

		if ($db->table(MessageHub::DB_TABLE_COLORS)->whereIlike('hop', 'pvp%')->exists()) {
			return;
		}
		$rhc = new RouteHopColor(
			hop: 'pvp',
			tag_color: 'F06AED',
		);
		$db->insert(MessageHub::DB_TABLE_COLORS, $rhc);
	}
}
