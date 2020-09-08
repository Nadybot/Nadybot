<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Nadybot\Core\CommandReply;
use Nadybot\Core\DB;
use Nadybot\Core\Modules\PLAYER_LOOKUP\GuildManager;
use Nadybot\Core\Modules\PLAYER_LOOKUP\PlayerManager;
use Nadybot\Core\Nadybot;
use Nadybot\Core\Text;
use Nadybot\Core\Util;

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
		} else {
			// Someone's name.  Doing a whois to get an orgID.
			$name = ucfirst(strtolower($args[1]));
			$whois = $this->playerManager->getByName($name, $dimension);

			if ($whois === null) {
				$msg = "Could not find character info for $name.";
				$sendto->reply($msg);
				return;
			} elseif ($whois->guild_id === 0) {
				$msg = "Character <highlight>$name<end> does not seem to be in an org.";
				$sendto->reply($msg);
				return;
			} else {
				$orgId = $whois->guild_id;
			}
		}

		$msg = "Getting org info...";
		$sendto->reply($msg);

		$org = $this->guildManager->getById($orgId, $dimension);
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
		$link .= "<tab>Faction: <highlight>$leader->faction<end>\n";
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
			$link .= "<tab>".
				$this->text->alignNumber($profMembers, 3, "highlight").
				"  (".
				$this->text->alignNumber(
					(int)round(($profMembers*100)/$numMembers, 1),
					(count($countProfs) > 1 ) ? 2 : 3
				).
				"%)  $prof\n";
		}
		$msg = $this->text->makeBlob("Org Info for $org->orgname", $link);

		$sendto->reply($msg);
	}
}
