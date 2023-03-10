<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Event;

use Nadybot\Core\Event;
use Nadybot\Modules\PVP_MODULE\FeedMessage;

class SiteUpdate extends Event {
	public function __construct(
		public FeedMessage\SiteUpdate $site
	) {
		$this->type = "site-update";
	}
}
