<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

class OrgMember {
	public string $name;
	public ?string $mode;
	public ?int $logged_off = 0;
}
