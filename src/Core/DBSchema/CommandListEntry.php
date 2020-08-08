<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class CommandListEntry extends DBRow {
	public string $cmd;
	public string $cmdevent;
	public string $description;
	public string $module;
	public string $file;
	public string $admin;
	public string $dependson;
	public int $guild_avail;
	public int $guild_status;
	public int $priv_avail;
	public int $priv_status;
	public int $msg_avail;
	public int $msg_status;
}
