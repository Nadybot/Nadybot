<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

class Route extends DBRow {
	/** The unique ID of this route */
	public int $id;

	/** The source channel for this route */
	public string $source;

	/** The destination channel for this route */
	public string $destination;

	/** Set to true if this route is also the other way around */
	public bool $two_way=false;

	/**
	 * The modifiers for this route
	 * @db:ignore
	 * @var RouteModifier[]
	 */
	public array $modifiers = [];
}
