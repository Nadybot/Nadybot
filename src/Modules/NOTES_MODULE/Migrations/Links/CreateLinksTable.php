<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE\Migrations\Links;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, SchemaMigration};
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 20210427053608)]
class CreateLinksTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = "links";
		if ($db->schema()->hasTable($table)) {
			$db->schema()->table($table, function (Blueprint $table): void {
				$table->id("id")->change();
			});
			return;
		}
		$db->schema()->create($table, function (Blueprint $table): void {
			$table->id();
			$table->string("name", 25);
			$table->string("website", 255);
			$table->string("comments", 255);
			$table->integer("dt");
		});
	}
}
