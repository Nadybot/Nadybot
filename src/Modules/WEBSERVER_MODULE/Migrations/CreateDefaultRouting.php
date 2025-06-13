<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE\Migrations;

use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DB,
	Routing\Source,
	Types\SchemaMigration,
};
use Nadybot\Core\DBSchema\Route;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_08_16_06_07_06)]
class CreateDefaultRouting implements SchemaMigration {
	#[NCA\Inject]
	private BotConfig $config;

	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Route::getTable();
		$route = [
			'source' => 'web',
			'destination' => Source::PRIV . '(' . $this->config->main->character . ')',
			'two_way' => true,
		];
		$db->table($table)->insert($route);

		$route = [
			'source' => 'web',
			'destination' => Source::ORG,
			'two_way' => true,
		];
		$db->table($table)->insert($route);
	}
}
