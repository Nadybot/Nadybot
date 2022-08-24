<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{Attributes as NCA, DBRow};

class Route extends DBRow {
	/** The unique ID of this route */
	public int $id;

	/** The source channel for this route */
	public string $source;

	/** The destination channel for this route */
	public string $destination;

	/** Set to true if this route is also the other way around */
	public bool $two_way=false;

	/** If set, the route is disabled until the set timestamp */
	public ?int $disabled_until = null;

	/**
	 * The modifiers for this route
	 *
	 * @var RouteModifier[]
	 */
	#[NCA\DB\Ignore]
	public array $modifiers = [];
}
