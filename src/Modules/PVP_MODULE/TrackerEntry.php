<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow};
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;
use Nadybot\Modules\PVP_MODULE\Handlers\Base;

class TrackerEntry extends DBRow {
	/** The id of the tracker entry */
	public int $id;

	/** Name of the character who created this entry */
	public string $created_by;

	/** Timestamp when the entry was created */
	public int $created_on;

	/** The expression to filter on */
	public string $expression;

	/**
	 * The events to trigger for this tracker
	 *
	 * @var string[]
	 */
	#[NCA\DB\MapRead([self::class, "decodeEvents"])]
	#[NCA\DB\MapWrite([self::class, "encodeEvents"])]
	public array $events = [];

	/** @var Base[] */
	#[NCA\DB\Ignore]
	public array $handlers=[];

	public function matches(SiteUpdate $site, ?string $eventName=null): bool {
		foreach ($this->handlers as $handler) {
			if ($handler->matches($site) === false) {
				return false;
			}
		}
		return true;
	}

	/** @return string[] */
	public static function decodeEvents(string $events): array {
		return explode(",", $events);
	}

	/** @param string[] $events */
	public static function encodeEvents(array $events): string {
		return implode(",", $events);
	}
}
