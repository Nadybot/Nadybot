<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\DISCORD;

use Stringable;

class DiscordRole implements Stringable {
	use ReducedStringableTrait;

	/**
	 * @param string           $id            role id
	 * @param string           $name          role name
	 * @param int              $color         representation of hexadecimal color code
	 * @param bool             $hoist         if this role is pinned in the user listing
	 * @param ?string          $icon          role icon hash
	 * @param ?string          $unicode_emoji role unicode emoji
	 * @param int              $position      position of this role
	 * @param string           $permissions   permission bit set
	 * @param bool             $managed       whether this role is managed by an integration
	 * @param bool             $mentionable   whether this role is mentionable
	 * @param ?DiscordRoleTags $tags          the tags this role has
	 * @param int              $flags         role flags combined as a bit field
	 */
	public function __construct(
		public string $id,
		public string $name,
		public int $color,
		public bool $hoist,
		public ?string $icon,
		public ?string $unicode_emoji,
		public int $position,
		public string $permissions,
		public bool $managed,
		public bool $mentionable,
		public ?DiscordRoleTags $tags,
		public int $flags,
	) {
	}
}
