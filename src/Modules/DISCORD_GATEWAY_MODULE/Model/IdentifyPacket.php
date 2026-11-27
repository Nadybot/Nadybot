<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use EventSauce\ObjectHydrator\PropertyCasters\CastListToType;
use Nadybot\Core\Modules\DISCORD\ReducedStringableTrait;
use Stringable;

class IdentifyPacket implements Stringable {
	use ReducedStringableTrait;

	/** presence structure for initial presence information */
	public ?UpdateStatus $presence=null;

	public ConnectionProperties $properties;

	/**
	 * @param ?int          $large_threshold     value between 50 and 250, total number of members where the gateway will stop sending offline members in the guild member list
	 * @param ?int[]        $shard               used for Guild Sharding
	 * @param ?bool         $guild_subscriptions enables dispatching of guild subscription events (presence and typing events)
	 * @param ?int          $intents             the Gateway Intents you wish to receive
	 * @param ?UpdateStatus $presence            presence structure for initial presence information
	 *
	 * @psalm-param ?list<int> $shard
	 */
	public function __construct(
		#[\SensitiveParameter] public string $token,
		public ?bool $compress=null,
		public ?int $large_threshold=null,
		#[CastListToType('int')] public ?array $shard=null,
		public ?bool $guild_subscriptions=null,
		public ?int $intents=null,
		?ConnectionProperties $properties=null,
		?UpdateStatus $presence=null,
	) {
		$this->presence = $presence ?? new UpdateStatus();
		$this->properties = $properties ?? new ConnectionProperties();
	}
}
