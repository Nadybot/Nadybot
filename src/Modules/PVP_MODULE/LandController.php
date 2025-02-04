<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\ParamClass\PTowerSite;
use Nadybot\Core\{Attributes as NCA, CmdContext, ModuleInstance, Text, Types\Playfield};
use Throwable;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: LandController::LC_CMD,
		alias: 'lc',
		description: 'Perform Notum Wars commands',
		accessLevel: 'guest',
	)
]
class LandController extends ModuleInstance {
	public const LC_CMD = 'nw lc';

	#[NCA\Inject]
	private NotumWarsController $nwCtrl;

	/** List all playfields with tower sites */
	#[NCA\HandlesCommand(self::LC_CMD)]
	public function listNWPlayfields(
		CmdContext $context,
		#[NCA\Str('lc')] string $action,
	): void {
		if (!count($this->nwCtrl->state)) {
			$context->reply('The Tower-API is still initializing.');
			return;
		}
		$lines = [];
		foreach ($this->nwCtrl->state as $pfId => $pfState) {
			$lines[$pfState->longName] = Text::makeChatcmd(
				$pfState->longName,
				"/tell <myname> nw lc {$pfState->shortName}"
			) . " <highlight>({$pfState->shortName})<end>";
		}
		ksort($lines);
		$msg = Text::makeBlob(
			'Land Control Index',
			"<header2>Playfields with notum fields<end>\n".
			'<tab>' . implode("\n<tab>", $lines)
		);
		$context->reply($msg);
	}

	/** Show the status of all tower sites in a playfield */
	#[NCA\HandlesCommand(self::LC_CMD)]
	public function listTowerSites(
		CmdContext $context,
		#[NCA\Str('lc')] string $action,
		#[NCA\PlayfieldStr] string $pf
	): void {
		if (!count($this->nwCtrl->state)) {
			$context->reply('The Tower-API is still initializing.');
			return;
		}
		try {
			$playfield = Playfield::byName($pf);
		} catch (Throwable) {
			$msg = "Playfield <highlight>{$pf}<end> could not be found.";
			$context->reply($msg);
			return;
		}
		$sites = $this->nwCtrl->state[$playfield->value] ?? null;
		if (!isset($sites)) {
			$msg = "No tower sites found on <highlight>{$playfield->long()}<end>.";
			$context->reply($msg);
			return;
		}
		$blocks = array_map(
			function (FeedMessage\SiteUpdate $site): string {
				return $this->nwCtrl->renderSite($site);
			},
			$sites->sorted()
		);
		$msg = Text::makeBlob(
			"All bases in {$playfield->long()}",
			implode("\n\n", $blocks)
		);
		$context->reply($msg);
	}

	/** Show the status of a single tower site */
	#[NCA\HandlesCommand(self::LC_CMD)]
	public function showTowerSite(
		CmdContext $context,
		#[NCA\Str('lc')] string $action,
		PTowerSite $site,
	): void {
		if (!count($this->nwCtrl->state)) {
			$context->reply('The Tower-API is still initializing.');
			return;
		}
		$siteInfo = $this->nwCtrl->state[$site->pf->value][$site->site] ?? null;
		if (!isset($siteInfo)) {
			$msg = "No tower sites <highlight>{$site->pf->short()} {$site->site}<end> found.";
			$context->reply($msg);
			return;
		}
		$blob = $this->nwCtrl->renderSite($siteInfo);
		$msg = Text::makeBlob(
			"{$site->pf->short()} {$site->site} ({$siteInfo->name})",
			$blob,
		);
		$context->reply($msg);
	}
}
