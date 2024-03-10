<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE\Handlers;

use Nadybot\Core\ParamClass\PTowerSite;
use Nadybot\Core\{Registry, UserException};
use Nadybot\Modules\HELPBOT_MODULE\{Playfield, PlayfieldController};
use Nadybot\Modules\PVP_MODULE\Attributes\Argument;
use Nadybot\Modules\PVP_MODULE\FeedMessage\SiteUpdate;

#[Argument(
	names: ["site"],
	description: "Only match the given site. A site has a playfield name, and ".
		"a site-number, e.g. \"PW 12\" or PW12.",
	type: "site-name with, or without spaces",
	examples: ["AEG3", '"GOF 6"'],
)]
class Site extends Base {
	private ?Playfield $pf=null;
	private ?int $siteId=null;

	public function matches(SiteUpdate $site): bool {
		if (!isset($this->pf) || !isset($this->siteId)) {
			return false;
		}
		return $this->pf->id === $site->playfield_id && $this->siteId === $site->site_id;
	}

	protected function validateValue(): void {
		if (!PTowerSite::matches($this->value)) {
			throw new UserException("'<highlight>{$this->value}<end>' is not a tower site format");
		}
		$site = new PTowerSite($this->value);

		/** @var ?PlayfieldController */
		$pfCtrl = Registry::getInstance(PlayfieldController::class);
		if (!isset($pfCtrl)) {
			return;
		}
		$this->pf = $pfCtrl->getPlayfieldByName($site->pf);
		if (!isset($this->pf)) {
			throw new UserException("'<highlight>{$this->value}<end>' is not a known playfield.");
		}
		$this->siteId = $site->site;
	}
}
