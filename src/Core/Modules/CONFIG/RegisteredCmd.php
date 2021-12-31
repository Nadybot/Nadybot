<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DBRow;

class RegisteredCmd extends DBRow {
	public string $module;
	public string $cmdevent;
	public string $file;
	public string $cmd;
	public string $description='none';
	public int $verify=0;
	public int $status=0;
	public string $dependson='none';
	public ?string $help=null;

	public int $guild_avail;
	public int $priv_avail;
	public int $msg_avail;

	public int $guild_status;
	public int $priv_status;
	public int $msg_status;

	public ?string $guild_al;
	public ?string $priv_al;
	public ?string $msg_al;
}
