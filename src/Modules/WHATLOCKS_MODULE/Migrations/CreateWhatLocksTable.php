<?php declare(strict_types=1);

namespace Nadybot\Modules\WHATLOCKS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_28_09_04_55, shared: true)]
class CreateWhatLocksTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = 'what_locks';
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->integer('item_id')->index();
			$table->integer('skill_id')->index();
			$table->integer('duration')->index();
		});
	}
}
