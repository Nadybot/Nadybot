<?php declare(strict_types=1);

namespace Nadybot\Core;

use Closure;
use Illuminate\Database\Schema\Builder;

/**
 * This is a wrapper class for the Illuminate Schem Builder
 *
 * This is needed, so we can handle Nadybot's special <myname> table names
 * without too much hassle.
 *
 * @method bool createDatabase(string $name) Create a database in the schema.
 * @method bool dropDatabaseIfExists(string $name) Drop a database from the schema if the database exists.
 * @method void dropAllTables() Drop all tables from the database.
 * @method void dropAllViews() Drop all views from the database.
 * @method void dropAllTypes() Drop all types from the database.
 * @method array getAllTables() Get all of the table names for the database.
 * @method void rename(string $from, string $to) Rename a table on the schema.
 * @method bool enableForeignKeyConstraints() Enable foreign key constraints.
 * @method bool disableForeignKeyConstraints() Disable foreign key constraints.
 * @method \Illuminate\Database\Connection getConnection() Get the database connection instance.
 * @method $this setConnection(\Illuminate\Database\Connection $connection) Set the database connection instance.
 * @method void blueprintResolver(\Closure $resolver) Set the Schema Blueprint resolver callback.
 */
class SchemaBuilder {
	public DB $nadyDB;
	public Builder $builder;

	public function __construct(Builder $builder) {
		$this->builder = $builder;
	}

	/** Create a database in the schema.  */
	public function create(string $table, Closure $callback): void {
		$table = $this->nadyDB->formatSql($table);
		$this->builder->create($table, $callback);
	}

	/** Modify a table on the schema.  */
	public function table(string $table, Closure $callback): void {
		$table = $this->nadyDB->formatSql($table);
		$this->builder->table($table, $callback);
	}

	/** Determine if the given table exists. */
	public function hasTable(string $table): bool {
		$table = $this->nadyDB->formatSql($table);
		return $this->builder->hasTable($table);
	}

	/** Drop a table from the schema. */
	public function drop(string $table): void {
		$table = $this->nadyDB->formatSql($table);
		$this->builder->drop($table);
	}

	/** Drop a table from the schema if it exists. */
	public function dropIfExists(string $table): void {
		$table = $this->nadyDB->formatSql($table);
		$this->builder->dropIfExists($table);
	}

	/** Determine if the given table has a given column. */
	public function hasColumn(string $table, string $column): bool {
		$table = $this->nadyDB->formatSql($table);
		return $this->builder->hasColumn($table, $column);
	}

	/** Determine if the given table has given columns.  */
	public function hasColumns(string $table, array $columns): bool {
		$table = $this->nadyDB->formatSql($table);
		return $this->builder->hasColumns($table, $columns);
	}

	/**
	 * Drop columns from a table schema.
	 *
	 * @param string $table
	 * @param string|array $columns
	 */
	public function dropColumns(string $table, $columns): void {
		$table = $this->nadyDB->formatSql($table);
		$this->builder->dropColumns($table, $columns);
	}

	/** Get the column listing for a given table. */
	public function getColumnListing(string $table) {
		$table = $this->nadyDB->formatSql($table);
		$this->builder->getColumnListing($table);
	}

	/** Get the data type for the given column name. */
	public function getColumnType(string $table, string $column) {
		$table = $this->nadyDB->formatSql($table);
		$this->builder->getColumnType($table, $column);
	}

	public function __call(string $name, array $arguments) {
		return $this->builder->$name(...$arguments);
	}
}
