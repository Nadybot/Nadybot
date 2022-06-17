<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\Attributes\JSON;
use Nadybot\Core\DBRow;

class RouteHopColor extends DBRow {
	/**
	 * Internal primary key
	 */
	#[JSON\Ignore]
	public int $id;

	/** The hop mask (discord, *, aopriv, ...) */
	public string $hop;

	/** The channel for which to apply these colors or null for all */
	public ?string $where = null;

	/** Only apply this color if the event was routed via this hop */
	public ?string $via = null;

	/** The 6 hex digits of the tag color, like FFFFFF */
	public ?string $tag_color = null;

	/** The 6 hex digits of the text color, like FFFFFF */
	public ?string $text_color = null;
}
