<?php declare(strict_types=1);

namespace Nadybot\Modules\GUILD_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Modules\ALTS\AltsController,
	ParamClass\PDuration,
	Text,
	Types\AccessLevel,
	Util,
};

/**
 * @author Tyrence (RK2)
 * @author Mindrila (RK1)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'inactivemem',
		accessLevel: AccessLevel::Guild,
		description: 'Check for inactive members',
	)
]
class InactiveMemberController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private AltsController $altsController;

	/** Show org members who have not logged on for a specified amount of time */
	#[NCA\HandlesCommand('inactivemem')]
	public function inactivememCommand(CmdContext $context, PDuration $duration): void {
		$time = $duration->toSecs();
		if ($time < 1) {
			$msg = 'You must enter a valid time parameter.';
			$context->reply($msg);
			return;
		}

		$timeString = Util::unixtimeToReadable($time, false);
		$time = time() - $time;

		/** @var Collection<string,Collection<int,RecentOrgMember>> */
		$members = $this->db->table(OrgMember::getTable())
			->where('mode', '!=', 'del')
			->orderByDesc('logged_off')
			->asObj(OrgMember::class)
			->map(function (OrgMember $member): RecentOrgMember {
				return new RecentOrgMember(
					main: $this->altsController->getMainOf($member->name),
					name: $member->name,
					mode: $member->mode,
					logged_off: $member->logged_off,
				);
			})
			->groupBy('main')
			->sortKeys();
		if (count($members) === 0) {
			$context->reply('There are no members in the org roster.');
			return;
		}

		$numInactive = 0;

		$blob = 'Org members who have not logged off since '.
			"<highlight>{$timeString}<end> ago.\n\n".
			"<header2>Inactive org members<end>\n";

		foreach ($members as $mainName => $altsLink) {
			$alt = $altsLink->firstOrFail();

			if (($alt->logged_off??0) >= $time) {
				continue;
			}
			$alt->logged_off ??= 0;
			$numInactive++;
			$altsLink = Text::makeChatcmd(
				'alts',
				"/tell <myname> alts {$alt->main}"
			);

			$player = "<pagebreak><tab>[{$altsLink}] {$alt->main}";
			if ($alt->logged_off) {
				$lastseen = Util::date($alt->logged_off);
				$player .= ": Last seen on [{$alt->name}] on {$lastseen}\n";
			} else {
				$player .= ": Never seen\n";
			}
			$blob .= $player;
		}
		$msg = Text::makeBlob("{$numInactive} Inactive Org Members", $blob);
		$context->reply($msg);
	}
}
