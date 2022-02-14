<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;
use Nadybot\Core\Attributes\JSON;

class RouteHopFormat extends DBRow {
	/**
	 * Internal primary key
	 */
	#[JSON\Ignore]
	public int $id;

	/** The hop mask (discord, *, aopriv, ...) */
	public string $hop;

	/** The channel for which to apply these, or null for all */
	public ?string $where;

	/** Only apply these settings if the event was routed via this hop */
	public ?string $via;

	/** Whether to render this tag or not */
	public bool $render = true;

	/** The format what the text of the tag should look like */
	public string $format = '%s';
}
