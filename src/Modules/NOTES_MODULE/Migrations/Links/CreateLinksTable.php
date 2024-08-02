<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE\Migrations\Links;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Nadybot\Modules\NOTES_MODULE\Link;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2021_04_27_05_36_08, shared: true)]
class CreateLinksTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Link::getTable();
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, static function (Blueprint $table): void {
				$table->id('id')->change();
			});
			return;
		}
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->id();
			$table->string('name', 25);
			$table->string('website', 255);
			$table->string('comments', 255);
			$table->integer('dt');
		});
	}
}
