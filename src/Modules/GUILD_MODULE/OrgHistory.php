<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Nadybot\Core\DBRow;

class OrgHistory extends DBRow {
	public int $id;
	public ?string $actor;
	public ?string $actee;
	public ?string $action;
	public ?string $organization;
	public ?int $time;
}
