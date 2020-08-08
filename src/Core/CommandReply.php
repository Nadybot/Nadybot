<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * An interface to allow replying to a command, no matter the origin
 */
interface CommandReply {
	/**
	 * Send a reply to the channel (tell, guild, priv) where the command was received
	 *
	 * @param string|string[] $msg
	 * @return void
	 */
	public function reply($msg): void;
}
