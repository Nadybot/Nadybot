<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PROFILE;

use Nadybot\Core\CommandReply;

class ProfileCommandReply implements CommandReply {
	public string $result = "";

	public function reply($msg): void {
		$this->result .= $msg . "\n";
	}
}
