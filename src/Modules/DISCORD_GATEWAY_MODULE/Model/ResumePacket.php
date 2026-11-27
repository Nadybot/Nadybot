<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\Modules\DISCORD\ReducedStringableTrait;
use Stringable;

class ResumePacket implements Stringable {
	use ReducedStringableTrait;

	public function __construct(
		#[\SensitiveParameter] public string $token,
		public string $session_id,
		public int $seq,
	) {
	}
}
