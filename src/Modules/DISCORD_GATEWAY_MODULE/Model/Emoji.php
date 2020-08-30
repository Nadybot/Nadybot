<?php declare(strict_types=1);

namespace Nadybot\Modules\DISCORD_GATEWAY_MODULE\Model;

use Nadybot\Core\JSONDataModel;

class Emoji extends JSONDataModel {
	public ?string $id = null;
	public ?string $name = null;
	public ?array $roles;
	public ?object $user;
	public ?bool $require_colors;
	public ?bool $managed;
	public ?bool $animated;
	public ?bool $available;
}
