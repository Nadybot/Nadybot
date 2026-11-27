<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\Modules\DISCORD\ReducedStringableTrait;
use Stringable;

class InteractionResponse implements Stringable {
	use ReducedStringableTrait;

	public const int TYPE_PONG = 1;
	public const int TYPE_CHANNEL_MESSAGE_WITH_SOURCE = 4;
	public const int TYPE_DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE = 5;
	public const int TYPE_DEFERRED_UPDATE_MESSAGE = 6;
	public const int TYPE_UPDATE_MESSAGE = 7;
	public const int TYPE_APPLICATION_COMMAND_AUTOCOMPLETE_RESULT = 8;
	public const int TYPE_MODAL = 9;

	/**
	 * @param int                      $type the type of response
	 * @param ?InteractionCallbackData $data an optional response message
	 */
	public function __construct(
		public int $type,
		public ?InteractionCallbackData $data=null,
	) {
	}
}
