<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\LIMITS\Migrations;

use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\DBSchema\RateIgnoreList;
use Nadybot\Core\{Collection, DB, Types\SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_24_21_20_23, shared: true)]
class MigrateWhitelistTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		if (!$db->schema()->hasTable('whitelist')) {
			return;
		}

		/** @var Collection<int,object{name:string,added_by:string,added_dt:int}&\stdClass> */
		$data = $db->table('whitelist')
			->select('name', 'added_by', 'added_dt')
			->orderBy('added_dt')
			->get();

		/** @param object{name:string,added_by:string,added_dt:int}&\stdClass $data*/
		$data->each(
			static function (object $data) use ($db): void {
				$db->table(RateIgnoreList::getTable())
					->insert([
						'name' => $data->name,
						'added_by' => $data->added_by,
						'added_dt' => $data->added_dt,
					]);
			}
		);
		$db->schema()->drop('whitelist');
	}
}
