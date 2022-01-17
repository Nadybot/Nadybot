<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;
use Nadybot\Core\Attributes as NCA;

class CmdCfg extends DBRow {
	public string $module;
	public string $cmdevent;
	public string $file;
	public string $cmd;
	public string $description='none';
	public int $verify=0;
	public string $dependson='none';
	public ?string $help=null;

	/**
	 * @var array<string,CmdPermission>
	 * @json:map=array_values|%s
	 */
	#[NCA\DB\Ignore]
	public array $permissions = [];
}
