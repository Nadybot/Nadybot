<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBRow};

class CmdCfg extends DBRow {
	#[NCA\JSON\Ignore]
	public string $module;
	#[NCA\JSON\Ignore]
	public string $cmdevent;
	#[NCA\JSON\Ignore]
	public string $file;
	public string $cmd;
	public string $description='none';
	#[NCA\JSON\Ignore]
	public int $verify=0;
	#[NCA\JSON\Ignore]
	public string $dependson='none';

	/**
	 * @var array<string,CmdPermission>
	 *
	 * @json-var CmdPermission[]
	 */
	#[NCA\DB\Ignore]
	#[NCA\JSON\Map("array_values")]
	public array $permissions = [];
}
