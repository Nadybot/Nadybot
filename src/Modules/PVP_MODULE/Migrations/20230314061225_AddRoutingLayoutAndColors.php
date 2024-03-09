<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Migrations;

use Nadybot\Core\DBSchema\{RouteHopColor, RouteHopFormat};
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{DB, MessageHub, SchemaMigration};
use Psr\Log\LoggerInterface;

class AddRoutingLayoutAndColors implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		if ($db->table(Source::DB_TABLE)->whereIlike("hop", "pvp%")->exists()) {
			return;
		}
		$rhf = new RouteHopFormat();
		$rhf->hop = "pvp";
		$rhf->render = true;
		$rhf->format = 'PVP';
		$db->insert(Source::DB_TABLE, $rhf);

		if ($db->table(MessageHub::DB_TABLE_COLORS)->whereIlike("hop", "pvp%")->exists()) {
			return;
		}
		$rhc = new RouteHopColor();
		$rhc->hop = 'pvp';
		$rhc->tag_color = 'F06AED';
		$db->insert(MessageHub::DB_TABLE_COLORS, $rhc);
	}
}
