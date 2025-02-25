<?php declare(strict_types=1);

namespace Nadybot\Core\DB;

/** This represents a supported database type of Nadybot */
enum DBType: string {
	case SQLite = 'sqlite';
	case MySQL = 'mysql';
	case PostgreSQL = 'postgresql';
	case MSSQL = 'mssql';
}
