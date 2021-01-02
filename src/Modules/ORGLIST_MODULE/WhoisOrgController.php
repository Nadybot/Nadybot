<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\DBSchema\Player;
use Nadybot\Core\Modules\PLAYER_LOOKUP\Guild;
use Nadybot\Core\Modules\PLAYER_LOOKUP\GuildManager;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Text;
use Nadybot\Core\Util;
use Nadybot\Modules\ONLINE_MODULE\OnlineController;

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'whoisorg',
 *		accessLevel = 'all',
 *		description = 'Display org info',
 *		help        = 'whoisorg.txt'
 *	)
 */
class WhoisOrgController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Nadybot $chatBot;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/** @Inject */
	public PlayerManager $playerManager;

	/** @Inject */
	public GuildManager $guildManager;

	/** @Inject */
	public OnlineController $onlineController;

	/**
	 * @HandlesCommand("whoisorg")
	 * @Matches("/^whoisorg ([a-z0-9-]+) (\d)$/i")
	 * @Matches("/^whoisorg ([a-z0-9-]+)$/i")
	 */
	public function whoisorgCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$dimension = (int)$this->chatBot->vars['dimension'];
		if (count($args) === 3) {
			$dimension = (int)$args[2];
		}

		if (preg_match("/^\d+$/", $args[1])) {
			$orgId = (int)$args[1];
			$this->sendOrgIdInfo($orgId, $sendto, $dimension);
			return;
		}
		// Someone's name.  Doing a whois to get an orgID.
		$name = ucfirst(strtolower($args[1]));
		$this->playerManager->getByNameAsync(
			function(?Player $whois) use ($name, $sendto, $dimension): void {
				if ($whois === null) {
					$msg = "Could not find character info for $name.";
					$sendto->reply($msg);
					return;
				} elseif ($whois->guild_id === 0) {
					$msg = "Character <highlight>$name<end> does not seem to be in an org.";
					$sendto->reply($msg);
					return;
				}
				$this->sendOrgIdInfo($whois->guild_id, $sendto, $dimension);
			},
			$name,
			$dimension
		);
	}

	protected function sendOrgIdInfo(int $orgId, CommandReply $sendto, int $dimension): void {
		$msg = "Getting org info...";
		$sendto->reply($msg);

		$this->guildManager->getByIdAsync($orgId, $dimension, false, [$this, "sendOrgInfo"], $sendto);
	}

	public function sendOrgInfo(?Guild $org, CommandReply $sendto): void {
		if ($org === null) {
			$msg = "Error in getting the org info. ".
				"Either the org does not exist or AO's server ".
				"was too slow to respond.";
			$sendto->reply($msg);
			return;
		}
		if (!isset($org->orgname)) {
			$msg = "This is an illegal org id.";
			$sendto->reply($msg);
			return;
		}

		$countProfs = [];
		$minLevel = 220;
		$maxLevel = 1;

		$numMembers = count($org->members);
		$sumLevels = 0;
		$leader = null;
		foreach ($org->members as $member) {
			if ($member->guild_rank_id === 0) {
				$leader = $member;
			}
			$sumLevels += $member->level;

			$minLevel = min($member->level, $minLevel);
			$maxLevel = max($member->level, $maxLevel);

			$countProfs[$member->profession]++;
		}
		$averageLevel = round($sumLevels/$numMembers);

		$link = "<header2>General Info<end>\n";
		$link .= "<tab>Faction: <" . strtolower($leader->faction) . ">$leader->faction<end>\n";
		$link .= "<tab>Lowest lvl: <highlight>$minLevel<end>\n";
		$link .= "<tab>Highest lvl: <highlight>$maxLevel<end>\n";
		$link .= "<tab>Average lvl: <highlight>$averageLevel<end>\n\n";

		$link .= "<header2>$leader->guild_rank<end>\n";
		$link .= "<tab>Name: <highlight>$leader->name<end>\n";
		$link .= "<tab>Profession: <highlight>$leader->profession<end>\n";
		$link .= "<tab>Level: <highlight>$leader->level<end>\n";
		$link .= "<tab>Gender: <highlight>$leader->gender<end>\n";
		$link .= "<tab>Breed: <highlight>$leader->breed<end>\n\n";

		ksort($countProfs);
		$link .= "<header2>Members ($numMembers)<end>\n";
		foreach ($countProfs as $prof => $profMembers) {
			$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".$this->onlineController->getProfessionId($prof).">";
			$link .= "<tab>".
				$this->text->alignNumber($profMembers, 3, "highlight").
				"  (".
				$this->text->alignNumber(
					(int)round(($profMembers*100)/$numMembers, 1),
					(count($countProfs) > 1 ) ? 2 : 3
				).
				"%)  $profIcon $prof\n";
		}
		$msg = $this->text->makeBlob("Org Info for $org->orgname", $link);

		$sendto->reply($msg);
	}
}
