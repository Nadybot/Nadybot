<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\LoggerWrapper;

class MockCommandReply implements CommandReply {
	public LoggerWrapper $logger;

	public function reply($msg): void {
		if (isset($this->logger)) {
			foreach ((array)$msg as $result) {
				$this->logger->log('INFO', $result);
			}
		}
	}
}
