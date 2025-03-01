<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

class CommandSearchResult extends CmdCfg {
	/** How similar to the searched term is the name of this command? */
	public float $similarity_percent = 0;
}
