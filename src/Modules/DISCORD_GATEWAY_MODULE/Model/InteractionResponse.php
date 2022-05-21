<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class InteractionResponse extends JSONDataModel {
	public const TYPE_PONG = 1;
	public const TYPE_CHANNEL_MESSAGE_WITH_SOURCE = 4;
	public const TYPE_DEFERRED_CHANNEL_MESSAGE_WITH_SOURCE = 5;
	public const TYPE_DEFERRED_UPDATE_MESSAGE = 6;
	public const TYPE_UPDATE_MESSAGE = 7;
	public const TYPE_APPLICATION_COMMAND_AUTOCOMPLETE_RESULT = 8;
	public const TYPE_MODAL = 9;

	/** the type of response */
	public int $type;

	/** an optional response message */
	public ?InteractionCallbackData $data = null;
}
