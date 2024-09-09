<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE\Migrations;

use Illuminate\Database\Schema\Blueprint;
use Nadybot\Core\Attributes as NCA;
use Nadybot\Core\{DB, Types\SchemaMigration};
use Nadybot\Modules\ORGLIST_MODULE\Organization;
use Psr\Log\LoggerInterface;

#[NCA\Migration(order: 2024_04_10_14_58_00, shared: true)]
class CreateOrganizationsTable implements SchemaMigration {
	public function migrate(LoggerInterface $logger, DB $db): void {
		$table = Organization::getTable();
		$db->schema()->dropIfExists($table);
		$db->schema()->create($table, static function (Blueprint $table): void {
			$table->unsignedInteger('id')->primary();
			$table->string('name', 40)->index();
			$table->string('faction', 10);
			$table->unsignedInteger('num_members');
			$table->string('index', 6)->index();
			$table->string('governing_form', 10);
		});
	}
}
