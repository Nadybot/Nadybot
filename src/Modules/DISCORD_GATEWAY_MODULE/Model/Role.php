<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class Role extends JSONDataModel {
	public string $id;
	public string $name;
	/** integer representation of hexadecimal color code */
	public int $color;
	/** if this role is pinned in the user listing */
	public bool $hoist;
	public int $position;
	/** permission bit set */
	public int $permissions;
	/** whether this role is managed by an integration */
	public bool $managed;
	/** whether this role is mentionable */
	public bool $mentionable;
}
