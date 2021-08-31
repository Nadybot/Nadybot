<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class RouteHopFormat extends DBRow {
	/**
	 * Internal primary key
	 * @json:ignore
	 */
	public int $id;

	/** The hop mask (discord, *, aopriv, ...) */
	public string $hop;

	/** The channel for which to apply these, or null for all */
	public ?string $where;

	/** Whether to render this tag or not */
	public bool $render = true;

	/** The format what the text of the tag should look like */
	public string $format = '%s';
}
