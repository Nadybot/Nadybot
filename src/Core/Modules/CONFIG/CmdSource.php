<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\DBSchema\CmdPermSetMapping;

class CmdSource {
	/**
	 * The name/mask of this command source
	 * If the name contains an asterisk wildcard, it
	 * represents a mask of possible sources, e.g. aotell(*)
	 */
	public string $name;

	/**
	 * A list of permission set mappings this command source maps to
	 * @var CmdPermSetMapping[]
	 */
	public array $mappings = [];
}
