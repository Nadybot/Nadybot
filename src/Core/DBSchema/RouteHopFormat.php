<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class RouteHopFormat extends DBRow {
	/** Internal primary key */
	public int $id;

	/** The hop mask (discord, *, aopriv, ...) */
	public string $hop;

	/** Whether to render this tag or not */
	public bool $render = true;

	/** The format what the text of the tag should look like */
	public ?string $format;
}
