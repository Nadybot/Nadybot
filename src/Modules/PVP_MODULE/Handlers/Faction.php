<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Handlers;

use Nadybot\Core\UserException;
use Nadybot\Modules\PVP_MODULE\Attributes\Argument;
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;

#[Argument(
	names: ["faction", "side", "alignment"],
	description: "Only show one side (clan, omni, neutral) of the conflict,\n".
		"or all but one (!clan, !omni, !neutral)",
	type: "string",
	examples: ["omni", "!neutral"]
)]
class Faction extends Base {
	public function matches(SiteUpdate $site): bool {
		if (!isset($site->org_faction)) {
			return false;
		}
		if (substr($this->value, 0, 1) === "!") {
			return $this->value !== strtolower($site->org_faction);
		}
		return $this->value === strtolower($site->org_faction);
	}

	protected function validateValue(): void {
		$this->value = strtolower($this->value);
		if (in_array($this->value, ["omni", "neutral", "clan"], true)) {
			return;
		}
		if (in_array($this->value, ["!omni", "!neutral", "!clan"], true)) {
			return;
		}
		throw new UserException("Invalid faction '{$this->value}'");
	}
}
