<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;
use Nadybot\Core\Attributes as NCA;

class CmdCfg extends DBRow {
	/** @json:ignore **/
	public string $module;
	/** @json:ignore **/
	public string $cmdevent;
	/** @json:ignore **/
	public string $file;
	public string $cmd;
	public string $description='none';
	/** @json:ignore **/
	public int $verify=0;
	/** @json:ignore **/
	public string $dependson='none';
	public ?string $help=null;

	/**
	 * @var array<string,CmdPermission>
	 * @json-var CmdPermission[]
	 * @json:map=array_values|%s
	 */
	#[NCA\DB\Ignore]
	public array $permissions = [];
}
