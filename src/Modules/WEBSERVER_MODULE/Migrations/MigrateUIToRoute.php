<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	DBSchema\Route,
	Routing\Source,
	SchemaMigration,
};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_08_19_18_44_32)]
class MigrateUIToRoute implements SchemaMigration {
	#[NCA\Inject]
	private BotConfig $config;

	public function migrate(LoggerInterface $logger, DB $db): void {
		if (strlen($this->config->general->orgName)) {
			$destination = Source::ORG;
		} else {
			$destination = Source::PRIV . "({$this->config->main->character})";
		}
		$db->table(Route::getTable())->insert([
			'source' => Source::SYSTEM . '(webui)',
			'destination' => $destination,
		]);
	}
}
