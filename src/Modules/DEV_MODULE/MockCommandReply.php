<?php declare(strict_types=1);

namespace Nadybot\Modules\DEV_MODULE;

use Nadybot\Core\CommandReply;

class MockCommandReply implements CommandReply {
	public function reply($msg): void {
	}
}
