<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Handlers;

use Nadybot\Core\UserException;
use Nadybot\Modules\PVP_MODULE\Attributes\Argument;
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;

#[Argument(
	names: ["max_towers"],
	description: "Only match those tower fields with a maximum number of\n".
		"towers. This also includes the CT, so your parameter cannot be\n".
		"lower than 1. You cannot ftrack unplanted sites.",
	type: "number",
	examples: ["1", "3"],
)]
class MaxTowers extends Base {
	public function matches(SiteUpdate $site): bool {
		if (!isset($site->ql)) {
			return false;
		}
		return 1 + $site->num_conductors + $site->num_turrets <= (int)$this->value;
	}

	protected function validateValue(): void {
		if (!ctype_digit($this->value)) {
			throw new UserException("'<highlight>{$this->value}<end>' is not a valid number.");
		}
		if ($this->value === "0") {
			throw new UserException("You cannot track sites with 0 towers.");
		}
	}
}
