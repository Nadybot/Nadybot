<?php declare(strict_types=1);

namespace Nadybot\Modules\PVP_MODULE;

use function Amp\delay;

use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Parameter\Str,
	CmdContext,
	Config\BotConfig,
	ModuleInstance,
	ParamClass\PTowerSite,
	Text,
	Types\AccessLevel,
	Types\Dimension,
	Types\Playfield
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: LandController::LC_CMD,
		alias: 'lc',
		description: 'Perform Notum Wars commands',
		accessLevel: AccessLevel::Guest,
	),
	NCA\DefineCommand(
		command: 'nw wait',
		description: 'Wait for the Tower-API to be isnitialized',
		accessLevel: AccessLevel::Admin,
	)
]
class LandController extends ModuleInstance {
	public const LC_CMD = 'nw lc';

	#[NCA\Inject]
	private NotumWarsController $nwCtrl;

	#[NCA\Inject]
	private BotConfig $config;

	/** Wait for the Tower-API to be available */
	#[NCA\HandlesCommand('nw wait')]
	public function waitForTowerAPI(
		CmdContext $context,
		#[Str('wait')] string $action,
		?int $maxWait,
	): void {
		$dimension = $this->config->main->dimension;
		if (!$this->nwCtrl->supportsTowerAPI($dimension)) {
			$context->reply('There is no Tower-API for ' . Dimension::nameOf($dimension) . ' yet.');
			return;
		}
		$maxWait ??= 60;
		$start = time();
		while (!count($this->nwCtrl->state)) {
			if (time() - $start > $maxWait) {
				$context->reply("The Tower-API is still not ready after {$maxWait}s");
				return;
			}
			delay(0.1);
		}
		$context->reply('The Tower-API is now ready to be used.');
	}

	/** List all playfields with tower sites */
	#[NCA\HandlesCommand(self::LC_CMD)]
	public function listNWPlayfields(
		CmdContext $context,
		#[Str('lc')] string $action,
	): void {
		if (!$this->isTowerAPIReady($context)) {
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
		#[Str('lc')] string $action,
		Playfield $playfield,
	): void {
		if (!$this->isTowerAPIReady($context)) {
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
		#[Str('lc')] string $action,
		PTowerSite $site,
	): void {
		if (!$this->isTowerAPIReady($context)) {
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

	/** Check if the Tower-API can be used, and if not, tell the user why */
	private function isTowerAPIReady(CmdContext $context): bool {
		$dimension = $this->config->main->dimension;
		if (!$this->nwCtrl->supportsTowerAPI($dimension)) {
			$context->reply('There is no Tower-API for ' . Dimension::nameOf($dimension) . ' yet.');
			return false;
		}
		if (!count($this->nwCtrl->state)) {
			$context->reply('The Tower-API is still initializing.');
			return false;
		}
		return true;
	}
}
