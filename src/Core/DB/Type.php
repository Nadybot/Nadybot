<?php declare(strict_types=1);

namespace Nadybot\Core\DB;

enum Type: string {
	case SQLite = 'sqlite';
	case MySQL = 'mysql';
	case PostgreSQL = 'postgresql';
	case MSSQL = 'mssql';
}
