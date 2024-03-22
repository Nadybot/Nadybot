<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PROFILE;

use Nadybot\Core\CommandReply;

class ProfileCommandReply implements CommandReply {
	public string $result = '';

	public function reply($msg): void {
		foreach ((array)$msg as $chunk) {
			$this->result .= $chunk . "\n";
		}
	}
}
