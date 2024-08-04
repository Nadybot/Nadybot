<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\{Attributes as NCA, DBTable, Types\MessageEmitter};
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;
use Nadybot\Modules\PVP_MODULE\Handlers\Base;
use Ramsey\Uuid\{Uuid, UuidInterface};
use Safe\DateTimeImmutable;

#[NCA\DB\Table(name: 'nw_tracker')]
class TrackerEntry extends DBTable implements MessageEmitter {
	/** Timestamp when the entry was created */
	public int $created_on;

	/** The id of the tracker entry */
	#[NCA\DB\PK] public UuidInterface $id;

	/**
	 * @param string         $created_by Name of the character who created this entry
	 * @param string         $expression The expression to filter on
	 * @param list<string>   $events     The events to trigger for this tracker
	 * @param list<Base>     $handlers
	 * @param ?UuidInterface $id         The id of the tracker entry
	 * @param ?int           $created_on Timestamp when the entry was created
	 */
	public function __construct(
		public string $created_by,
		public string $expression,
		#[NCA\DB\MapRead([self::class, 'decodeEvents'])] #[NCA\DB\MapWrite([self::class, 'encodeEvents'])] public array $events=[],
		#[NCA\DB\Ignore] public array $handlers=[],
		?UuidInterface $id=null,
		?int $created_on=null,
	) {
		$this->created_on = $created_on ?? time();
		$dt = null;
		if (isset($created_on) && !isset($id)) {
			$dt = (new DateTimeImmutable())->setTimestamp($created_on);
		}
		$this->id = $id ?? Uuid::uuid7($dt);
	}

	public function matches(SiteUpdate $site, ?string $eventName=null): bool {
		foreach ($this->handlers as $handler) {
			if ($handler->matches($site) === false) {
				return false;
			}
		}
		return true;
	}

	/** @return list<string> */
	public static function decodeEvents(string $events): array {
		return explode(',', $events);
	}

	/** @param iterable<array-key,string> $events */
	public static function encodeEvents(iterable $events): string {
		return collect($events)->join(',');
	}

	public function getChannelName(): string {
		return "site-tracker({$this->id})";
	}
}
