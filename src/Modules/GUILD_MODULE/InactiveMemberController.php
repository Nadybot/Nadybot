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
	Util,
};

/**
 * @author Tyrence (RK2)
 * @author Mindrila (RK1)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "inactivemem",
		accessLevel: "guild",
		description: "Check for inactive members",
	)
]
class InactiveMemberController extends ModuleInstance {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public AltsController $altsController;

	/** Show org members who have not logged on for a specified amount of time */
	#[NCA\HandlesCommand("inactivemem")]
	public function inactivememCommand(CmdContext $context, PDuration $duration): void {
		$time = $duration->toSecs();
		if ($time < 1) {
			$msg = "You must enter a valid time parameter.";
			$context->reply($msg);
			return;
		}

		$timeString = $this->util->unixtimeToReadable($time, false);
		$time = time() - $time;

		$members = $this->db->table(GuildController::DB_TABLE)
			->where("mode", "!=", "del")
			->orderByDesc("logged_off")
			->asObj(RecentOrgMember::class)
			->each(function (RecentOrgMember $member): void {
				$member->main = $this->altsController->getMainOf($member->name);
			})
			->groupBy("main")
			->sortKeys();
		if (count($members) === 0) {
			$context->reply("There are no members in the org roster.");
			return;
		}

		$numInactive = 0;

		$blob = "Org members who have not logged off since ".
			"<highlight>{$timeString}<end> ago.\n\n".
			"<header2>Inactive org members<end>\n";

		foreach ($members as $mainName => $altsLink) {
			/** @var Collection<RecentOrgMember> $altsLink */
			$alt = $altsLink->first();

			/** @var RecentOrgMember $alt */
			if ($alt->logged_off >= $time) {
				continue;
			}
			$alt->logged_off ??= 0;
			$numInactive++;
			$altsLink = $this->text->makeChatcmd(
				"alts",
				"/tell <myname> alts {$alt->main}"
			);

			$player = "<pagebreak><tab>[{$altsLink}] {$alt->main}";
			if ($alt->logged_off) {
				$lastseen = $this->util->date($alt->logged_off);
				$player .= ": Last seen on [{$alt->name}] on {$lastseen}\n";
			} else {
				$player .= ": Never seen\n";
			}
			$blob .= $player;
		}
		$msg = $this->text->makeBlob("{$numInactive} Inactive Org Members", $blob);
		$context->reply($msg);
	}
}
