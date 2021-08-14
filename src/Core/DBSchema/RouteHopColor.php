<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class RouteHopColor extends DBRow {
	/** Internal primary key */
	public int $id;

	/** The hop mask (discord, *, aopriv, ...) */
	public string $hop;

	/** The 6 hex digits of the tag color, like FFFFFF */
	public ?string $tag_color;

	/** The 6 hex digits of the text color, like FFFFFF */
	public ?string $text_color;
}
