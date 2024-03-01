<?php declare(strict_types=1);

namespace Nadybot\Modules\NEWS_MODULE;

use Nadybot\Core\SyncEvent;

class SyncNewsEvent extends SyncEvent {
	public string $type = "sync(news)";

	/** Unix timestamp when this was created */
	public int $time;

	/** Name of the character who created the entry */
	public string $name;

	/** Text of these news */
	public string $news;

	/** UUID of these news */
	public string $uuid;

	/** Set to true if this is pinned above all unpinned news */
	public bool $sticky;

	public static function fromNews(News $news): self {
		$event = new self();
		$syncAttribs = ["time", "name", "news", "uuid", "sticky"];
		foreach ($syncAttribs as $attrib) {
			$event->{$attrib} = $news->{$attrib} ?? null;
		}
		return $event;
	}

	/**
	 * @return array<string,int|string|bool>
	 *
	 * @phpstan-return array{"time":int, "name":string, "news":string, "uuid":string, "deleted":0, "sticky":bool}
	 */
	public function toData(): array {
		return [
			"time" => $this->time,
			"name" => $this->name,
			"news" => $this->news,
			"uuid" => $this->uuid,
			"deleted" => 0,
			"sticky" => $this->sticky,
		];
	}
}
