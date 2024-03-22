<?php declare(strict_types=1);

namespace Nadybot\Modules\WHATLOCKS_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20_210_428_090_455, shared: true)]
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
