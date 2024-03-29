<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Migrations;

use Nadybot\Core\DBSchema\{RouteHopColor, RouteHopFormat};
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{DB, LoggerWrapper, MessageHub, SchemaMigration};

class DefineDiscordRouteFormat implements SchemaMigration {
	public function migrate(LoggerWrapper $logger, DB $db): void {
		$rhf = new RouteHopFormat();
		$rhf->hop = "discord";
		$rhf->render = false;
		$rhf->format = 'DISCORD';
		$db->insert(Source::DB_TABLE, $rhf);

		$rhc = new RouteHopColor();
		$rhc->hop = 'discord';
		$rhc->tag_color = 'C3C3C3';
		$db->insert(MessageHub::DB_TABLE_COLORS, $rhc);

		$route = [
			"source" => "discord(*)",
			"destination" => Source::ORG,
			"two_way" => false,
		];
		$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);
	}
}
