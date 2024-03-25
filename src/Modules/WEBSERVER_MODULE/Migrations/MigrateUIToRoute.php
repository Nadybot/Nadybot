<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	DBSchema\Route,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_08_19_18_44_32)]
class MigrateUIToRoute implements SchemaMigration {
	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private MessageHub $messageHub;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = $this->messageHub::DB_TABLE_ROUTES;
		if (strlen($this->config->general->orgName)) {
			$destination = Source::ORG;
		} else {
			$destination = Source::PRIV . "({$this->config->main->character})";
		}
		$route = new Route(
			source: Source::SYSTEM . '(webui)',
			destination: $destination,
		);
		$db->insert($table, $route);
	}
}
