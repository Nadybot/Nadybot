<?php

namespace Budabot\Core;

/**
 * An interface to allow replying to a command, no matter the origin
 */
interface CommandReply {
	/**
	 * Send a reply to the channel (tell, guild, priv) where the command was received
	 *
	 * @param string $msg
	 * @return void
	 */
	public function reply($msg);
}
