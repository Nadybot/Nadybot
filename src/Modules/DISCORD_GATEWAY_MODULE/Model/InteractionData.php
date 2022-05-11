<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class InteractionData extends JSONDataModel {
	/** Slash commands; a text-based command that shows up when a user types / */
	public const TYPE_CHAT_INPUT = 1;
	/** A UI-based command that shows up when you right click or tap on a user */
	public const TYPE_USER = 2;
	/** A UI-based command that shows up when you right click or tap on a message */
	public const TYPE_MESSAGE = 3;

	/** the ID of the invoked command */
	public string $id;

	/** the name of the invoked command */
	public string $name;

	/** the type of the invoked command */
	public int $type;

	/** converted users + roles + channels + attachments */
	public ?object $resolved = null;

	/**
	 * the params + values from the user
	 * @var \Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\InteractionDataOption[]
	 */
	public ?array $options = null;

	/** the id of the guild the command is registered to */
	public ?string $guild_id = null;

	/** the custom_id of the component */
	public ?string $custom_id = null;

	/** the type of the component */
	public ?int $component_type = null;

	/**
	 * the values the user selected
	 * @var SelectOptionValue[]
	 */
	public ?array $values = null;

	/** id the of user or message targeted by a user or message command */
	public ?string $target_id = null;

	/**
	 * the values submitted by the user
	 * @var object[]
	 */
	public ?array $components = null;

	public function getOptionString(): ?string {
		if (!isset($this->options)) {
			return null;
		}
		$parts = [];
		foreach ($this->options as $option) {
			$parts []= $option->getOptionString();
		}
		return join(" ", $parts);
	}
}
