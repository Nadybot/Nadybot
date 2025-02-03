<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Handlers;

use Nadybot\Core\Exceptions\UserException;
use Nadybot\Core\Types\Playfield;
use Nadybot\Modules\PVP_MODULE\Attributes\Argument;
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;

#[Argument(
	names: ['pf', 'playfield'],
	description: "Only match sites in a given playfield. Use the short form,\n".
		'e.g. "PW" for Perpetual Wastelands.',
	type: 'string',
)]
class PF extends Base {
	private ?Playfield $pf=null;

	public function matches(SiteUpdate $site): bool {
		if (!isset($this->pf)) {
			return false;
		}
		return $this->pf === $site->playfield;
	}

	protected function validateValue(): void {
		$this->pf = Playfield::tryByName($this->value);
		if (!isset($this->pf)) {
			throw new UserException("'<highlight>{$this->value}<end>' is not a known playfield.");
		}
	}
}
