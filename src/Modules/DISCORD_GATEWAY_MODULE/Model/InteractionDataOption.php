<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class InteractionDataOption extends JSONDataModel {
	public const TYPE_SUB_COMMAND = 1;
	public const TYPE_SUB_COMMAND_GROUP = 2;
	public const TYPE_STRING = 3;
	/** Any integer between -2^53 and 2^53 */
	public const TYPE_INTEGER = 4;
	public const TYPE_BOOLEAN = 5;
	public const TYPE_USER = 6;
	/** Includes all channel types + categories */
	public const TYPE_CHANNEL = 7;
	public const TYPE_ROLE = 8;
	/** Includes users and roles */
	public const TYPE_MENTIONABLE = 9;
	/** Any double between -2^53 and 2^53 */
	public const TYPE_NUMBER = 10;
	/** attachment object */
	public const TYPE_ATTACHMENT = 11;

	/** Name of the parameter */
	public string $name;

	/** Value of application command option type */
	public int $type;

	/** Value of the option resulting from user input */
	public null|string|int|float $value = null;

	/**
	 * Present if this option is a group or subcommand
	 * @var \Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model\InteractionDataOption[]
	 */
	public ?array $options = null;

	/** true if this option is the currently focused option for autocomplete */
	public ?bool $focused = null;

	public function getOptionString(): string {
		if (!isset($this->options)) {
			return (string)$this->value;
		}
		$parts = [];
		foreach ($this->options as $option) {
			$parts []= $option->getOptionString();
		}
		return join(" ", $parts);
	}
}
