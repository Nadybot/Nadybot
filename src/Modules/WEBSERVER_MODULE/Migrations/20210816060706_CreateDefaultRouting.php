<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	MessageHub,
	Routing\Source,
	SchemaMigration,
};
use Psr\Log\LoggerInterface;

class CreateDefaultRouting implements SchemaMigration {
	#[NCA\Inject]
	private MessageHub $messageHub;

	#[NCA\Inject]
	private BotConfig $config;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = $this->messageHub::DB_TABLE_ROUTES;
		$route = [
			"source" => "web",
			"destination" => Source::PRIV . "(" . $this->config->main->character . ")",
			"two_way" => true,
		];
		$db->table($table)->insert($route);

		$route = [
			"source" => "web",
			"destination" => Source::ORG,
			"two_way" => true,
		];
		$db->table($table)->insert($route);
	}
}
