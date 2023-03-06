<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Handlers;

use Nadybot\Modules\PVP_MODULE\Attributes\Argument;
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;

#[Argument("org", "guild", "organization")]
class Org extends Base {
	public function matches(SiteUpdate $site): bool {
		if (!isset($site->org_name)) {
			return false;
		}
		return fnmatch($this->value, $site->org_name);
	}

	protected function validateValue(): void {
		return;
	}
}
