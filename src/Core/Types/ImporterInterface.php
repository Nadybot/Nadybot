<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use Nadybot\Core\DB;
use Psr\Log\LoggerInterface;

interface ImporterInterface {
	/**
	 * @param list<object>         $data    The data to import, already proper classes
	 * @param array<string,string> $rankMap A mapping of import rank to bot rank
	 */
	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void;
}
