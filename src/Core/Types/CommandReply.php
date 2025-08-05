<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/**
 * An interface to allow replying to a command, no matter the origin
 */
interface CommandReply {
	/**
	 * Send a reply to the channel (tell, guild, priv) where the command was received
	 *
	 * @param string|list<string> $msg
	 */
	public function reply(string|array $msg): void;
}
