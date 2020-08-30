<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class UpdateStatus extends JSONDataModel {
	public const STATUS_ONLINE = "online";
	public const STATUS_DND = "dnd";
	public const STATUS_IDLE = "idle";
	public const STATUS_INVISIBLE = "invisible";
	public const STATUS_OFFLINE = "offline";
	
	/**
	 * unix time (in milliseconds) of when the client went idle,
	 * or null if the client is not idle
	 */
	public ?int $since;
	public ?Activity $game;
	public string $status = self::STATUS_ONLINE;
	public bool $afk = false;

	public function __construct() {
		$this->game = new Activity();
	}
}
