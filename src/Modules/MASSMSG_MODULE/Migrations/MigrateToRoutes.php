<?php declare(strict_types=1);

namespace Nadybot\Modules\MASSMSG_MODULE\Migrations;

use Nadybot\Core\{
	DB,
	Routing\Source,
	Types\SchemaMigration,
};
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\Route;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2022_03_29_13_25_03)]
class MigrateToRoutes implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$botName = $db->getMyname();

		$channels = ['aoorg', "aopriv({$botName})"];
		$types = ['mass-message', 'mass-invite'];
		foreach ($channels as $channel) {
			foreach ($types as $type) {
				$route = [
					'source' => Source::SYSTEM . "({$type})",
					'destination' => $channel,
					'two_way' => false,
				];
				$db->table(Route::getTable())->insert($route);
			}
		}
	}
}
