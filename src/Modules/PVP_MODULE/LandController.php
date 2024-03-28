<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use Nadybot\Core\ParamClass\{PPlayfield, PTowerSite};
use Nadybot\Core\{Attributes as NCA, CmdContext, ModuleInstance, Playfield, Text};
use Throwable;

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: LandController::LC_CMD,
		description: 'Perform Notum Wars commands',
		accessLevel: 'guest',
	)
]
class LandController extends ModuleInstance {
	public const LC_CMD = 'nw lc';

	#[NCA\Inject]
	private NotumWarsController $nwCtrl;

	#[NCA\Inject]
	private Text $text;

	/** List all playfields with tower sites */
	#[NCA\HandlesCommand(self::LC_CMD)]
	public function listNWPlayfields(
		CmdContext $context,
		#[NCA\Str('lc')] string $action,
	): void {
		if (empty($this->nwCtrl->state)) {
			$context->reply('The Tower-API is still initializing.');
			return;
		}
		$lines = [];
		foreach ($this->nwCtrl->state as $pfId => $pfState) {
			$lines[$pfState->longName] = $this->text->makeChatcmd(
				$pfState->longName,
				"/tell <myname> nw lc {$pfState->shortName}"
			) . " <highlight>({$pfState->shortName})<end>";
		}
		ksort($lines);
		$msg = $this->text->makeBlob(
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
		PPlayfield $pf
	): void {
		if (empty($this->nwCtrl->state)) {
			$context->reply('The Tower-API is still initializing.');
			return;
		}
		$playfieldName = $pf();
		try {
			$playfield = Playfield::byName($playfieldName);
		} catch (Throwable) {
			$msg = "Playfield <highlight>{$playfieldName}<end> could not be found.";
			$context->reply($msg);
			return;
		}
		$sites = $this->nwCtrl->state[$playfield->value] ?? null;
		if (!isset($sites)) {
			$msg = "No tower sites found on <highlight>{$playfieldName}<end>.";
			$context->reply($msg);
			return;
		}
		$blocks = array_map(
			function (FeedMessage\SiteUpdate $site): string {
				return $this->nwCtrl->renderSite($site);
			},
			$sites->sorted()
		);
		$msg = $this->text->makeBlob(
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
		if (empty($this->nwCtrl->state)) {
			$context->reply('The Tower-API is still initializing.');
			return;
		}
		try {
			$playfield = Playfield::byName($site->pf);
		} catch (Throwable) {
			$msg = "Playfield <highlight>{$site->pf}<end> could not be found.";
			$context->reply($msg);
			return;
		}
		$siteInfo = $this->nwCtrl->state[$playfield->value][$site->site] ?? null;
		if (!isset($siteInfo)) {
			$msg = "No tower sites <highlight>{$playfield->short()} {$site->site}<end> found.";
			$context->reply($msg);
			return;
		}
		$blob = $this->nwCtrl->renderSite($siteInfo);
		$msg = $this->text->makeBlob(
			"{$playfield->short()} {$site->site} ({$siteInfo->name})",
			$blob,
		);
		$context->reply($msg);
	}
}
