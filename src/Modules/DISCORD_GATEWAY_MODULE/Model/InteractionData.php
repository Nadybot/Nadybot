<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\Attributes\Hydrator\CastToStdClass;
use Nadybot\Core\Modules\DISCORD\{ReducedStringableTrait, SelectOptionValue};
use stdClass;
use Stringable;

class InteractionData implements Stringable {
	use ReducedStringableTrait;

	/** Slash commands; a text-based command that shows up when a user types / */
	public const TYPE_CHAT_INPUT = 1;

	/** A UI-based command that shows up when you right click or tap on a user */
	public const TYPE_USER = 2;

	/** A UI-based command that shows up when you right click or tap on a message */
	public const TYPE_MESSAGE = 3;

	/**
	 * @param string                   $id             the ID of the invoked command
	 * @param string                   $name           the name of the invoked command
	 * @param int                      $type           the type of the invoked command
	 * @param ?stdClass                $resolved       converted users + roles + channels
	 *                                                 + attachments
	 * @param ?InteractionDataOption[] $options        the params + values from the user
	 * @param ?string                  $guild_id       the id of the guild the command
	 *                                                 is registered to
	 * @param ?string                  $custom_id      the custom_id of the component
	 * @param ?int                     $component_type the type of the component
	 * @param ?SelectOptionValue[]     $values         the values the user selected
	 * @param ?string                  $target_id      id the of user or message targeted
	 *                                                 by a user or message command
	 * @param ?stdClass[]              $components     the values submitted by the user
	 *
	 * @psalm-param ?list<stdClass> $components
	 */
	public function __construct(
		public string $id,
		public string $name,
		public int $type,
		#[CastToStdClass] public ?stdClass $resolved=null,
		#[CastListToType(InteractionDataOption::class)] public ?array $options=null,
		public ?string $guild_id=null,
		public ?string $custom_id=null,
		public ?int $component_type=null,
		#[CastListToType(SelectOptionValue::class)] public ?array $values=null,
		public ?string $target_id=null,
		#[CastToStdClass] public ?array $components=null,
	) {
	}

	public function getOptionString(): string {
		$parts = [$this->name];
		if (isset($this->options)) {
			foreach ($this->options as $option) {
				$parts []= $option->getOptionString();
			}
		}
		return implode(' ', $parts);
	}
}
