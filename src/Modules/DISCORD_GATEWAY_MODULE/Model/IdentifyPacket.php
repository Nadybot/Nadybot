<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

class IdentifyPacket {
	public string $token;
	public ConnectionProperties $properties;
	public ?bool $compress;
	/**
	 * value between 50 and 250,
	 * total number of members where the gateway will stop
	 * sending offline members in the guild member list
	 */
	public ?int $large_threshold;
	/**
	 * used for Guild Sharding
	 * @var int[]
	 */
	public ?array $shard;
	/** presence structure for initial presence information	- */
	public ?UpdateStatus $presence;
	/** enables dispatching of guild subscription events (presence and typing events) */
	public ?bool $guild_subscriptions;
	/** the Gateway Intents you wish to receive	*/
	public ?int $intents;

	public function __construct() {
		$this->presence = new UpdateStatus();
		$this->properties = new ConnectionProperties();
	}
}
