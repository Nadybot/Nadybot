<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PROFILE;

use Nadybot\Core\Types\CommandReply;

class ProfileCommandReply implements CommandReply {
	public string $result = '';

	public function reply(string|array $msg): void {
		foreach ((array)$msg as $chunk) {
			$this->result .= $chunk . "\n";
		}
	}
}
