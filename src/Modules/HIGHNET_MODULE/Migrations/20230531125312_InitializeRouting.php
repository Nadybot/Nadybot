<?php declare(strict_types=1);

namespace Nadybot\Modules\HIGHNET_MODULE\Migrations;

use Nadybot\Core\DBSchema\{RouteHopColor, RouteHopFormat};
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{Attributes as NCA, Config\BotConfig, DB, LoggerWrapper, MessageHub, SchemaMigration};

class InitializeRouting implements SchemaMigration {
	#[NCA\Inject]
	public BotConfig $config;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$hops = ["web", strlen($this->config->general->orgName) ? "aoorg" : "aopriv({$this->config->main->character})"];
		foreach ($hops as $hop) {
			$route = [
				"source" => 'highnet(*)',
				"destination" => $hop,
				"two_way" => false,
			];
			$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);

			$route = [
				"source" => $hop,
				"destination" => 'highnet',
				"two_way" => false,
			];
			$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);
		}

		$rhf = new RouteHopFormat();
		$rhf->hop = "highnet";
		$rhf->render = true;
		$rhf->format = '@%s';
		$db->insert(Source::DB_TABLE, $rhf);

		$rhc = new RouteHopColor();
		$rhc->hop = 'highnet';
		$rhc->tag_color = '00EFFF';
		$rhc->text_color = '00BFFF';
		$db->insert(MessageHub::DB_TABLE_COLORS, $rhc);
	}
}
