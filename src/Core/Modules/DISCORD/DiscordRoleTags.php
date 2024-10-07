<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Stringable;

class DiscordRoleTags implements Stringable {
	use ReducedStringableTrait;

	/**
	 * @param ?string $bot_id                  the id of the bot this role belongs to
	 * @param ?string $integration_id          the id of the integration this role belongs to
	 * @param ?string $subscription_listing_id the id of this role's subscription SKU and listing
	 * @param bool    $premium_subscriber      whether this is the guild's Booster role
	 * @param bool    $available_for_purchase  whether this role is available for purchase
	 * @param bool    $guild_connections       whether this role is a guild's linked role
	 */
	public function __construct(
		public ?string $bot_id,
		public ?string $integration_id,
		public ?string $subscription_listing_id,
		#[CastNullToTrue] public bool $premium_subscriber=false,
		#[CastNullToTrue] public bool $available_for_purchase=false,
		#[CastNullToTrue] public bool $guild_connections=false,
	) {
	}
}
