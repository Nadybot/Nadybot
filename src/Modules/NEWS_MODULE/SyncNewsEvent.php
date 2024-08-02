<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\Events\SyncEvent;

class SyncNewsEvent extends SyncEvent {
	public const EVENT_MASK = 'sync(news)';

	/**
	 * @param int    $time   Unix timestamp when this was created
	 * @param string $name   Name of the character who created the entry
	 * @param string $news   Text of these news
	 * @param string $uuid   UUID of these news
	 * @param bool   $sticky Set to true if this is pinned above all unpinned news
	 */
	public function __construct(
		public int $time,
		public string $name,
		public string $news,
		public string $uuid,
		public bool $sticky,
		?string $sourceBot=null,
		?int $sourceDimension=null,
		?bool $forceSync=null,
	) {
		$this->type = self::EVENT_MASK;
		parent::__construct($sourceBot, $sourceDimension, $forceSync);
	}

	public static function fromNews(News $news): self {
		return new self(
			time: $news->time,
			name: $news->name,
			news: $news->news,
			uuid: $news->id->toString(),
			sticky: $news->sticky,
		);
	}

	/**
	 * @return array<string,int|string|bool>
	 *
	 * @phpstan-return array{"time":int, "name":string, "news":string, "uuid":string, "deleted":0, "sticky":bool}
	 */
	public function toData(): array {
		return [
			'time' => $this->time,
			'name' => $this->name,
			'news' => $this->news,
			'uuid' => $this->uuid,
			'deleted' => 0,
			'sticky' => $this->sticky,
		];
	}
}
