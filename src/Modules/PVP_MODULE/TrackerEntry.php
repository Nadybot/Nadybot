<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\{Attributes as NCA, DBRow, MessageEmitter};
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;
use Nadybot\Modules\PVP_MODULE\Handlers\Base;

class TrackerEntry extends DBRow implements MessageEmitter {
	/** Timestamp when the entry was created */
	public int $created_on;

	/**
	 * @param int      $id         The id of the tracker entry
	 * @param string   $created_by Name of the character who created this entry
	 * @param string   $expression The expression to filter on
	 * @param string[] $events     The events to trigger for this tracker
	 * @param Base[]   $handlers
	 * @param ?int     $created_on Timestamp when the entry was created
	 */
	public function __construct(
		#[NCA\DB\AutoInc] public int $id,
		public string $created_by,
		public string $expression,
		#[NCA\DB\MapRead([self::class, 'decodeEvents'])] #[NCA\DB\MapWrite([self::class, 'encodeEvents'])] public array $events=[],
		#[NCA\DB\Ignore] public array $handlers=[],
		?int $created_on=null,
	) {
		$this->created_on = $created_on ?? time();
	}

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
		return explode(',', $events);
	}

	/** @param string[] $events */
	public static function encodeEvents(array $events): string {
		return implode(',', $events);
	}

	public function getChannelName(): string {
		return "site-tracker({$this->id})";
	}
}
