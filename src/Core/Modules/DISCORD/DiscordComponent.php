<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Stringable;

/**
 * Message components are a framework for adding interactive elements to the messages your
 * app or bot sends. They're accessible, customizable, and easy to use.
 *
 * What is a Component?
 * Components are a field on the message object, so you can use them whether you're
 * sending messages or responding to a slash command or other interaction.
 * The top-level components field is an array of Action Row components.
 *
 * @see https://discord.com/developers/docs/interactions/message-components#action-rows for the full documentation
 */
class DiscordComponent implements Stringable {
	use ReducedStringableTrait;

	/** @param int $type The type of this action component */
	public function __construct(
		public int $type,
	) {
	}
}
