<?php declare(strict_types=1);

namespace Nadybot\Core;

use EventSauce\ObjectHydrator\DoNotSerialize;
use Nadybot\Core\Attributes\DB\Table;
use ReflectionClass;
use ValueError;

/** This is an abstract class for a row of a table of the database */
abstract class DBTable extends DBRow {
	/**
	 * Get the name of the table represented by this class
	 *
	 * @throws ValueError if there is no table defined
	 */
	#[DoNotSerialize]
	public static function getTable(?string $as=null): string {
		$refClass = new ReflectionClass(static::class);
		$tableDefs = $refClass->getAttributes(Table::class);
		if (!count($tableDefs)) {
			throw new ValueError('The class ' . static::class . " doesn't have a table defined.");
		}
		$tableName = $tableDefs[0]->newInstance()->getName();
		if (isset($as)) {
			$tableName .= " AS {$as}";
		}
		return $tableName;
	}

	/** Get the name of this database table or `null` if not defined */
	#[DoNotSerialize]
	public static function tryGetTable(?string $as=null): ?string {
		try {
			return self::getTable($as);
		} catch (\Throwable) {
		}
		return null;
	}
}
