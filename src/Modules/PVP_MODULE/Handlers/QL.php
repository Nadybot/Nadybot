<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Handlers;

use Nadybot\Core\UserException;
use Nadybot\Modules\PVP_MODULE\Attributes\Argument;
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;

#[Argument(
	names: ["ql"],
	description: "Only keep tower sites where the CT has the given QL, or is\n".
		"in the given QL-range. Specify ranges liked 1-10.",
	type: "number or number range",
	examples: ["17", "25-53"]
)]
class QL extends Base {
	public function matches(SiteUpdate $site): bool {
		if (!isset($site->ql)) {
			return false;
		}
		$range = explode("-", $this->value);
		if (count($range) === 2) {
			return $site->ql > (int)$range[0] && $site->ql < (int)$range[1];
		}
		return $site->ql === (int)$this->value;
	}

	protected function validateValue(): void {
		$parts = explode("-", $this->value);
		if (count($parts) > 2) {
			throw new UserException("Expected either a QL-range (min-max) or a single QL.");
		}
		foreach ($parts as $part) {
			if (!preg_match("/^\d+$/", $part)) {
				throw new UserException("'<highlight>{$part}<end>' is not a valid QL.");
			}
		}
	}
}
