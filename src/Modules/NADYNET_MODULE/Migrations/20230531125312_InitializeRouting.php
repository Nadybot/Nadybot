<?php declare(strict_types=1);

namespace Nadybot\Modules\NADYNET_MODULE\Migrations;

use Nadybot\Core\DBSchema\{RouteHopColor, RouteHopFormat};
use Nadybot\Core\Routing\Source;
use Nadybot\Core\{Attributes as NCA, ConfigFile, DB, LoggerWrapper, MessageHub, SchemaMigration};

class InitializeRouting implements SchemaMigration {
	#[NCA\Inject]
	public ConfigFile $config;

	public function migrate(LoggerWrapper $logger, DB $db): void {
		$hops = ["web", strlen($this->config->orgName) ? "aoorg" : "aopriv({$this->config->name})"];
		foreach ($hops as $hop) {
			$route = [
				"source" => 'nadynet(*)',
				"destination" => $hop,
				"two_way" => false,
			];
			$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);

			$route = [
				"source" => $hop,
				"destination" => 'nadynet',
				"two_way" => false,
			];
			$db->table(MessageHub::DB_TABLE_ROUTES)->insert($route);
		}

		$rhf = new RouteHopFormat();
		$rhf->hop = "nadynet";
		$rhf->render = true;
		$rhf->format = 'nadynet@%s';
		$db->insert(Source::DB_TABLE, $rhf);

		$rhc = new RouteHopColor();
		$rhc->hop = 'nadynet';
		$rhc->tag_color = '00EFFF';
		$rhc->text_color = '00BFFF';
		$db->insert(MessageHub::DB_TABLE_COLORS, $rhc);
	}
}
