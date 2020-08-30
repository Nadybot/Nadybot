<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

class ResumePacket {
	public string $token;
	public string $session_id;
	public int $seq;
}
