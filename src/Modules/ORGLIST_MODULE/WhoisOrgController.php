<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ConfigFile,
	DB,
	DBSchema\Player,
	ModuleInstance,
	Modules\PLAYER_LOOKUP\Guild,
	Modules\PLAYER_LOOKUP\GuildManager,
	Modules\PLAYER_LOOKUP\PlayerManager,
	Nadybot,
	ParamClass\PCharacter,
	Text,
	Util,
};
use Nadybot\Modules\ONLINE_MODULE\OnlineController;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "whoisorg",
		accessLevel: "guest",
		description: "Display org info",
	)
]
class WhoisOrgController extends ModuleInstance {
	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public ConfigFile $config;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public PlayerManager $playerManager;

	#[NCA\Inject]
	public GuildManager $guildManager;

	#[NCA\Inject]
	public OnlineController $onlineController;

	/** Show information about an organization */
	#[NCA\HandlesCommand("whoisorg")]
	public function whoisorgIdCommand(CmdContext $context, int $orgId, ?int $dimension): void {
		$dimension ??= $this->config->dimension;
		$this->sendOrgIdInfo($orgId, $context, $dimension);
		return;
	}

	/** Show information about a character's org */
	#[NCA\HandlesCommand("whoisorg")]
	public function whoisorgCommand(CmdContext $context, PCharacter $char, ?int $dimension): void {
		$dimension ??= $this->config->dimension;
		$name = $char();
		$this->playerManager->getByNameAsync(
			function(?Player $whois) use ($name, $context, $dimension): void {
				if ($whois === null) {
					$msg = "Could not find character info for {$name}.";
					$context->reply($msg);
					return;
				} elseif (!isset($whois->guild_id) || $whois->guild_id === 0) {
					$msg = "Character <highlight>{$name}<end> does not seem to be in an org.";
					$context->reply($msg);
					return;
				}
				$this->sendOrgIdInfo($whois->guild_id, $context, $dimension);
			},
			$name,
			$dimension
		);
	}

	protected function sendOrgIdInfo(int $orgId, CmdContext $context, int $dimension): void {
		$msg = "Getting org info...";
		$context->reply($msg);

		$this->guildManager->getByIdAsync($orgId, $dimension, false, [$this, "sendOrgInfo"], $context);
	}

	public function sendOrgInfo(?Guild $org, CmdContext $context): void {
		if ($org === null) {
			$msg = "Error in getting the org info. ".
				"Either the org does not exist or AO's server ".
				"was too slow to respond.";
			$context->reply($msg);
			return;
		}
		if (!isset($org->orgname)) {
			$msg = "This is an illegal org id.";
			$context->reply($msg);
			return;
		}

		$countProfs = [];
		$minLevel = 220;
		$maxLevel = 1;

		$numMembers = count($org->members);
		$sumLevels = 0;
		$leader = null;
		$faction = "&lt;unknown&gt;";
		foreach ($org->members as $member) {
			if ($member->guild_rank_id === 0) {
				$leader = $member;
				$faction = $leader->faction;
			}
			$sumLevels += $member->level??0;

			$minLevel = min($member->level, $minLevel);
			$maxLevel = max($member->level, $maxLevel);

			if (isset($member->profession)) {
				$countProfs[$member->profession]++;
			}
		}
		$averageLevel = round($sumLevels/$numMembers);

		$link = "<header2>General Info<end>\n";
		$link .= "<tab>Faction: <" . strtolower($faction) . ">{$faction}<end>\n";
		$link .= "<tab>Lowest lvl: <highlight>{$minLevel}<end>\n";
		$link .= "<tab>Highest lvl: <highlight>{$maxLevel}<end>\n";
		$link .= "<tab>Average lvl: <highlight>{$averageLevel}<end>\n\n";

		if (isset($leader)) {
			$link .= "<header2>{$leader->guild_rank}<end>\n";
			$link .= "<tab>Name: <highlight>{$leader->name}<end>\n";
			$link .= "<tab>Profession: <highlight>{$leader->profession}<end>\n";
			$link .= "<tab>Level: <highlight>{$leader->level}<end>\n";
			$link .= "<tab>Gender: <highlight>{$leader->gender}<end>\n";
			$link .= "<tab>Breed: <highlight>{$leader->breed}<end>\n\n";
		}

		ksort($countProfs);
		$link .= "<header2>Members ($numMembers)<end>\n";
		foreach ($countProfs as $prof => $profMembers) {
			$profIcon = "<img src=tdb://id:GFX_GUI_ICON_PROFESSION_".($this->onlineController->getProfessionId($prof)??0).">";
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

		$context->reply($msg);
	}
}
