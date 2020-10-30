<?php declare(strict_types=1);

namespace Nadybot\Modules\SKILLS_MODULE;

use Nadybot\Core\{
	CommandAlias,
	CommandReply,
	DB,
	Http,
	Text,
	Util,
};
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;
use Nadybot\Modules\ITEMS_MODULE\ItemsController;
use Nadybot\Modules\ITEMS_MODULE\ItemSearchResult;

/**
 * @author Tyrence (RK2)
 * @author Nadyita
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'aggdef',
 *		accessLevel = 'all',
 *		description = 'Agg/Def: Calculates weapon inits for your Agg/Def bar',
 *		help        = 'aggdef.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'aimshot',
 *		accessLevel = 'all',
 *		description = 'Aim Shot: Calculates Aimed Shot',
 *		help        = 'aimshot.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'nanoinit',
 *		accessLevel = 'all',
 *		description = 'Nanoinit: Calculates Nano Init',
 *		help        = 'nanoinit.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'fullauto',
 *		accessLevel = 'all',
 *		description = 'Fullauto: Calculates Full Auto recharge',
 *		help        = 'fullauto.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'burst',
 *		accessLevel = 'all',
 *		description = 'Burst: Calculates Burst',
 *		help        = 'burst.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'fling',
 *		accessLevel = 'all',
 *		description = 'Fling: Calculates Fling',
 *		help        = 'fling.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'mafist',
 *		accessLevel = 'all',
 *		description = 'MA Fist: Calculates your fist speed',
 *		help        = 'mafist.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'dimach',
 *		accessLevel = 'all',
 *		description = 'Dimach: Calculates dimach facts',
 *		help        = 'dimach.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'brawl',
 *		accessLevel = 'all',
 *		description = 'Brawl: Calculates brawl facts',
 *		help        = 'brawl.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'fastattack',
 *		accessLevel = 'all',
 *		description = 'Fastattack: Calculates Fast Attack recharge',
 *		help        = 'fastattack.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'weapon',
 *		accessLevel = 'all',
 *		description = 'Shows weapon info (skill cap specials recycle and aggdef positions)',
 *		help        = 'weapon.txt'
 *	)
 */
class SkillsController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;

	/** @Inject */
	public Http $http;

	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/** @Inject */
	public ItemsController $itemsController;
	
	/** @Inject */
	public CommandAlias $commandAlias;
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, "weapon_attributes");
	
		$this->commandAlias->register($this->moduleName, "weapon", "specials");
		$this->commandAlias->register($this->moduleName, "weapon", "inits");
		$this->commandAlias->register($this->moduleName, "aimshot", "as");
		$this->commandAlias->register($this->moduleName, "aimshot", "aimedshot");
	}
	
	/**
	 * @HandlesCommand("aggdef")
	 * @Matches("/^aggdef (\d*\.?\d+) (\d*\.?\d+) (\d+)$/i")
	 */
	public function aggdefCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$AttTim = (float)$args[1];
		$RechT = (float)$args[2];
		$InitS = (int)$args[3];

		$blob = $this->getAggDefOutput($AttTim, $RechT, $InitS);

		$msg = $this->text->makeBlob("Agg/Def Results", $blob);
		$sendto->reply($msg);
	}

	protected function getAggdefBar(float $percent, int $length=50): string {
		$bar = str_repeat("l", $length);
		$markerPos = (int)round($percent / 100 * $length, 0);
		$leftBar   = substr($bar, 0, $markerPos);
		$rightBar  = substr($bar, $markerPos + 1);
		$fancyBar = "<green>${leftBar}<end><red>│<end><green>${rightBar}<end>";
		if ($percent < 100.0) {
			$fancyBar .= "<black>l<end>";
		}
		return $fancyBar;
	}
	
	public function getAggDefOutput(float $AttTim, float $RechT, int $InitS): string {
		if ($InitS < 1200) {
			$AttCalc	= round(((($AttTim - ($InitS / 600)) - 1)/0.02) + 87.5, 2);
			$RechCalc	= round(((($RechT - ($InitS / 300)) - 1)/0.02) + 87.5, 2);
		} else {
			$InitSk = $InitS - 1200;
			$AttCalc = round(((($AttTim - (1200/600) - ($InitSk / 600 / 3)) - 1)/0.02) + 87.5, 2);
			$RechCalc = round(((($RechT - (1200/300) - ($InitSk / 300 / 3)) - 1)/0.02) + 87.5, 2);
		}

		if ($AttCalc < $RechCalc) {
			$InitResult = $RechCalc;
		} else {
			$InitResult = $AttCalc;
		}
		if ($InitResult < 0) {
			$InitResult = 0;
		} elseif ($InitResult > 100 ) {
			$InitResult = 100;
		}

		$initsFullAgg = $this->getInitsNeededFullAgg($AttTim, $RechT);
		$initsNeutral = $this->getInitsNeededNeutral($AttTim, $RechT);
		$initsFullDef = $this->getInitsNeededFullDef($AttTim, $RechT);

		$blob = "Attack:    <highlight>". $AttTim ." <end>second(s).\n";
		$blob .= "Recharge: <highlight>". $RechT ." <end>second(s).\n";
		$blob .= "Init Skill:   <highlight>". $InitS ."<end>.\n\n";
		$blob .= "You must set your AGG bar at <highlight>". (int)round($InitResult, 0) ."% (". (int)round($InitResult*8/100, 0) .") <end>to wield your weapon at <highlight>1/1<end>.\n";
		$blob .= "(<a href=skillid://51>Agg/def-Slider</a> should read <highlight>".(int)round($InitResult*2-100, 0)."<end>).\n\n";
		$blob .= "Init needed for max speed at:\n";
		$blob .= "  Full Agg (100%): <highlight>". $initsFullAgg ." <end>inits\n";
		$blob .= "  Neutral (87.5%): <highlight>". $initsNeutral ." <end>inits\n";
		$blob .= "  Full Def (0%):     <highlight>". $initsFullDef ." <end>inits\n\n";
		$blob .= "<highlight>${initsFullDef}<end> DEF ";
		$blob .= $this->getAggdefBar($InitResult);
		$blob .= " AGG <highlight>${initsFullAgg}<end>\n";
		$blob .= "                         You: <highlight>${InitS}<end>\n\n";
		$blob .= "Note that at the neutral position (87.5%), your attack and recharge time will match that of the weapon you are using.";
		$blob .= "\n\nBased upon a RINGBOT module made by NoGoal(RK2)\n";
		$blob .= "Modified for Budabot by Healnjoo and Nadyita";
		
		return $blob;
	}

	public function getInitsForPercent(float $percent, float $attackTime, float $rechargeTime): int {
		$initAttack   = ($attackTime   - ($percent - 87.5) / 50 - 1) * 600;
		$initRecharge = ($rechargeTime - ($percent - 87.5) / 50 - 1) * 300;

		if ($initAttack > 1200) {
			$initAttack = ($attackTime - ($percent - 37.5) / 50 - 2) * 1800 + 1200;
		}
		if ($initRecharge > 1200) {
			$initRecharge = ($rechargeTime - ($percent - 37.5) / 50 - 4) * 900 + 1200;
		}
		return (int)round(max(max($initAttack, $initRecharge), 0), 0);
	}
	
	public function getInitsNeededFullAgg(float $attackTime, float $rechargeTime) {
		return $this->getInitsForPercent(100, $attackTime, $rechargeTime);
	}
	
	public function getInitsNeededNeutral(float $attackTime, float $rechargeTime) {
		return $this->getInitsForPercent(87.5, $attackTime, $rechargeTime);
	}
	
	public function getInitsNeededFullDef(float $attackTime, float $rechargeTime) {
		return $this->getInitsForPercent(0, $attackTime, $rechargeTime);
	}
	
	/**
	 * @HandlesCommand("aimshot")
	 * @Matches("/^aimshot (\d*\.?\d+) (\d*\.?\d+) (\d+)$/i")
	 */
	public function aimshotCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$attackTime = (float)$args[1];
		$rechargeTime = (float)$args[2];
		$aimedShot = (int)$args[3];

		[$cap, $ASCap] = $this->capAimedShot($attackTime, $rechargeTime);

		$ASRecharge	= (int)ceil(($rechargeTime * 40) - ($aimedShot * 3 / 100) + $attackTime - 1);
		if ($ASRecharge < $cap) {
			$ASRecharge = $cap;
		}
		$ASMultiplier	= (int)round($aimedShot / 95, 0);

		$blob = "Attack:       <highlight>{$attackTime}<end> second(s)\n";
		$blob .= "Recharge:    <highlight>{$rechargeTime}<end> second(s)\n";
		$blob .= "Aimed Shot: <highlight>{$aimedShot}<end>\n\n";
		$blob .= "Aimed Shot Multiplier: <highlight>1-{$ASMultiplier}x<end>\n";
		$blob .= "Aimed Shot Recharge: <highlight>{$ASRecharge}<end> seconds\n";
		$blob .= "With your weapon, your Aimed Shot recharge will cap at <highlight>{$cap}<end>s.\n";
		$blob .= "You need <highlight>{$ASCap}<end> Aimed Shot skill to cap your recharge.";

		$msg = $this->text->makeBlob("Aimed Shot Results", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("brawl")
	 * @Matches("/^brawl (\d+)$/i")
	 */
	public function brawlCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$brawlSkill = (int)$args[1];

		$skillList  = [ 1, 1000, 1001, 2000, 2001, 3000];
		$minList	= [ 1,  100,  101,  170,  171,  235];
		$maxList	= [ 2,  500,  501,  850,  851, 1145];
		$critList	= [ 3,  500,  501,  600,  601,  725];

		if ($brawlSkill < 1001) {
			$i = 0;
		} elseif ($brawlSkill < 2001) {
			$i = 2;
		} else {
			$i = 4;
		}

		$minDamage  = $this->util->interpolate($skillList[$i], $skillList[($i+1)], $minList[$i], $minList[($i+1)], $brawlSkill);
		$maxDamage  = $this->util->interpolate($skillList[$i], $skillList[($i+1)], $maxList[$i], $maxList[($i+1)], $brawlSkill);
		$critBonus = $this->util->interpolate($skillList[$i], $skillList[($i+1)], $critList[$i], $critList[($i+1)], $brawlSkill);
		$stunChance = "<highlight>20<end>%";
		if ($brawlSkill < 1000) {
			$stunChance = "<highlight>10<end>%, (will become 20% above 1000 brawl skill)";
		}
		$stunDuration = "<highlight>4<end>s";
		if ($brawlSkill < 2001) {
			$stunDuration = "<highlight>3<end>s, (will become 4s above 2001 brawl skill)";
		}

		$blob = "Brawl Skill: <highlight>".$brawlSkill."<end>\n";
		$blob .= "Brawl recharge: <highlight>15<end> seconds (constant)\n";
		$blob .= "Damage: <highlight>".$minDamage."<end>-<highlight>".$maxDamage."<end> (<highlight>".$critBonus."<end>)\n";
		$blob .= "Stun chance: ".$stunChance."\n";
		$blob .= "Stun duration: ".$stunDuration."\n";
		$blob .= "\n\nby Imoutochan, RK1";

		$msg = $this->text->makeBlob("Brawl Results", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("burst")
	 * @Matches("/^burst (\d*\.?\d+) (\d*\.?\d+) (\d+) (\d+)$/i")
	 */
	public function burstCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$attackTime = (float)$args[1];
		$rechargeTime = (float)$args[2];
		$burstDelay = (int)$args[3];
		$burstSkill = (int)$args[4];

		[$burstWeaponCap, $burstSkillCap] = $this->capBurst($attackTime, $rechargeTime, $burstDelay);

		$burstRecharge = (int)floor(($rechargeTime * 20) + ($burstDelay / 100) - ($burstSkill / 25) + $attackTime);
		$burstRecharge = max($burstRecharge, $burstWeaponCap);

		$blob = "Attack:       <highlight>{$attackTime}<end> second(s)\n";
		$blob .= "Recharge:    <highlight>{$rechargeTime}<end> second(s)\n";
		$blob .= "Burst Delay: <highlight>{$burstDelay}<end>\n";
		$blob .= "Burst Skill:   <highlight>{$burstSkill}<end>\n\n";
		$blob .= "Your burst recharge: <highlight>{$burstRecharge}<end>s\n\n";
		$blob .= "You need <highlight>{$burstSkillCap}<end> ".
			"burst skill to cap your recharge at the minimum of ".
			"<highlight>{$burstWeaponCap}<end>s.";

		$msg = $this->text->makeBlob("Burst Results", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("dimach")
	 * @Matches("/^dimach (\d+)$/i")
	 */
	public function dimachCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$dimachSkill = (int)$args[1];

		$skillList	        = [   1, 1000, 1001, 2000, 2001, 3000];
		$generalDamageList	= [   1, 2000, 2001, 2500, 2501, 2850];
		$maRechargeList  	= [1800, 1800, 1188,  600,  600,  300];
		$maDamageList	    = [   1, 2000, 2001, 2340, 2341, 2550];
		$shadeRechargeList  = [ 300,  300,  300,  300,  240,  200];
		$shadeDamageList	= [   1,  920,  921, 1872, 1873, 2750];
		$shadeHPDrainList	= [  70,   70,   70,   75,   75,   80];
		$keeperHealList     = [   1, 3000, 3001,10500,10501,15000];

		if ($dimachSkill < 1001) {
			$i = 0;
		} elseif ($dimachSkill < 2001) {
			$i = 2;
		} else {
			$i = 4;
		}

		$blob = "Dimach Skill: <highlight>{$dimachSkill}<end>\n\n";

		$maDamage = $this->util->interpolate($skillList[$i], $skillList[($i+1)], $maDamageList[$i], $maDamageList[($i+1)], $dimachSkill);
		$maDimachRecharge = $this->util->interpolate($skillList[$i], $skillList[($i+1)], $maRechargeList[$i], $maRechargeList[($i+1)], $dimachSkill);
		$blob .= "<header2>Martial Artist<end>\n";
		$blob .= "<tab>Damage: <highlight>{$maDamage}<end> (<highlight>1<end>)\n";
		$blob .= "<tab>Recharge <highlight>".$this->util->unixtimeToReadable($maDimachRecharge)."<end>\n\n";

		$keeperHeal	= $this->util->interpolate($skillList[$i], $skillList[($i+1)], $keeperHealList[$i], $keeperHealList[($i+1)], $dimachSkill);
		$blob .= "<header2>Keeper<end>\n";
		$blob .= "<tab>Self heal: <highlight>".$keeperHeal."<end> HP\n";
		$blob .= "<tab>Recharge: <highlight>5 mins<end> (constant)\n\n";

		$shadeDamage	= $this->util->interpolate($skillList[$i], $skillList[($i+1)], $shadeDamageList[$i], $shadeDamageList[($i+1)], $dimachSkill);
		$shadeHPDrainPercent  = $this->util->interpolate($skillList[$i], $skillList[($i+1)], $shadeHPDrainList[$i], $shadeHPDrainList[($i+1)], $dimachSkill);
		$shadeDimacheRecharge = $this->util->interpolate($skillList[$i], $skillList[($i+1)], $shadeRechargeList[$i], $shadeRechargeList[($i+1)], $dimachSkill);
		$blob .= "<header2>Shade<end>\n";
		$blob .= "<tab>Damage: <highlight>".$shadeDamage."<end> (<highlight>1<end>)\n";
		$blob .= "<tab>HP drain: <highlight>".$shadeHPDrainPercent."<end>%\n";
		$blob .= "<tab>Recharge: <highlight>".$this->util->unixtimeToReadable($shadeDimacheRecharge)."<end>\n\n";

		$damageOthers = $this->util->interpolate($skillList[$i], $skillList[($i+1)], $generalDamageList[$i], $generalDamageList[($i+1)], $dimachSkill);
		$blob .= "<header2>All other professions<end>\n";
		$blob .= "<tab>Damage: <highlight>{$damageOthers}<end> (<highlight>1<end>)\n";
		$blob .= "<tab>Recharge: <highlight>30 mins<end> (constant)\n\n";

		$blob .= "by Imoutochan, RK1";

		$msg = $this->text->makeBlob("Dimach Results", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("fastattack")
	 * @Matches("/^fastattack (\d*\.?\d+) (\d+)$/i")
	 */
	public function fastAttackCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$attackTime = (float)$args[1];
		$fastAttack = (int)$args[2];

		[$weaponCap, $skillNeededForCap] = $this->capFastAttack($attackTime);

		$recharge = (int)round(($attackTime * 16) - ($fastAttack / 100));

		if ($recharge < $weaponCap) {
			$recharge = $weaponCap;
		} else {
			$recharge = ceil($recharge);
		}

		$blob  = "Attack:           <highlight>{$attackTime}<end>s\n";
		$blob .= "Fast Attack:    <highlight>{$fastAttack}<end>\n";
		$blob .= "Your Recharge: <highlight>{$recharge}<end>s\n\n";
		$blob .= "You need <highlight>{$skillNeededForCap}<end> Fast Attack Skill to cap your fast attack at <highlight>{$weaponCap}<end>s.\n";
		$blob .= "Every 100 points in Fast Attack skill less than this will increase the recharge by 1s.";

		$msg = $this->text->makeBlob("Fast Attack Results", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("fling")
	 * @Matches("/^fling (\d*\.?\d+) (\d+)$/i")
	 */
	public function flighShotCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$attackTime = (float)$args[1];
		$flingShot = (int)$args[2];

		[$weaponCap, $skillCap] = $this->capFlingShot($attackTime);

		$recharge =  round(($attackTime * 16) - ($flingShot / 100));

		$recharge = max($weaponCap, $recharge);

		$blob = "Attack:           <highlight>{$attackTime}<end>s\n";
		$blob .= "Fling Shot:       <highlight>{$flingShot}<end>\n";
		$blob .= "Your Recharge: <highlight>{$recharge}<end>s\n\n";
		$blob .= "You need <highlight>{$skillCap}<end> Fling Shot skill to cap your fling at <highlight>{$weaponCap}<end>s.";

		$msg = $this->text->makeBlob("Fling Results", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("fullauto")
	 * @Matches("/^fullauto (\d*\.?\d+) (\d*\.?\d+) (\d+) (\d+)$/i")
	 */
	public function fullAutoCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$attackTime = (float)$args[1];
		$rechargeTime = (float)$args[2];
		$faRecharge = (int)$args[3];
		$faSkill = (int)$args[4];

		[$faWeaponCap, $faSkillCap] = $this->capFullAuto($attackTime, $rechargeTime, $faRecharge);

		$myFullAutoRecharge = (int)round(($rechargeTime * 40) + ($faRecharge / 100) - ($faSkill / 25) + round($attackTime - 1));
		$myFullAutoRecharge = max($myFullAutoRecharge, $faWeaponCap);

		$maxBullets = 5 + (int)floor($faSkill / 100);

		$blob = "Weapon Attack: <highlight>{$attackTime}<end>s\n";
		$blob .= "Weapon Recharge: <highlight>{$rechargeTime}<end>s\n";
		$blob .= "Full Auto Recharge value: <highlight>{$faRecharge}<end>\n";
		$blob .= "FA Skill: <highlight>{$faSkill}<end>\n\n";
		$blob .= "Your Full Auto recharge: <highlight>{$myFullAutoRecharge}<end>s\n";
		$blob .= "Your Full Auto can fire a maximum of <highlight>{$maxBullets}<end> bullets.\n";
		$blob .= "Full Auto recharge always caps at <highlight>{$faWeaponCap}<end>s.\n";
		$blob .= "You will need at least <highlight>{$faSkillCap}<end> Full Auto skill to cap your recharge.\n\n";
		$blob .= "From <black>0<end><highlight>0<end><black>K<end><highlight> to 10.0K<end> damage, the bullet damage is unchanged.\n";
		$blob .= "From <highlight>10K to 11.5K<end> damage, each bullet damage is halved.\n";
		$blob .= "From <highlight>11K to 15.0K<end> damage, each bullet damage is halved again.\n";
		$blob .= "<highlight>15K<end> is the damage cap.";

		$msg = $this->text->makeBlob("Full Auto Results", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("mafist")
	 * @Matches("/^mafist (\d+)$/i")
	 */
	public function maFistCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$maSkill = (int)$args[1];

		// MA templates
		$skillList =     [     1,    200,   1000,   1001,   2000,   2001,   3000];

		$maMinList =     [     4,     45,    125,    130,    220,    225,    450];
		$maMaxList =     [     8,     75,    400,    405,    830,    831,   1300];
		$maCritList =    [     3,     50,    500,    501,    560,    561,    800];
		$maFistSpeed =   [  1.15,    1.2,   1.25,   1.30,   1.35,   1.45,   1.50];
		$maAOID =        [211352, 211353, 211354, 211357, 211358, 211363, 211364];

		$shadeMinList =  [     3,     25,     55,     56,    130    ,131,    280];
		$shadeMaxList =  [     5,     60,    258,    259,    682    ,683,    890];
		$shadeCritList = [     3,     50,    250,    251,    275    ,276,    300];
		$shadeAOID =     [211349, 211350, 211351, 211359, 211360, 211365, 211366];

		$otherMinList =  [     3,     25,     65,     66,    140,    204,    300];
		$otherMaxList =  [     5,     60,    280,    281,    715,    831,    990];
		$otherCritList = [     3,     50,    500,    501,    605,    605,    630];
		$otherAOID =     [ 43712, 144745,  43713, 211355, 211356, 211361, 211362];

		if ($maSkill < 200) {
			$i = 0;
		} elseif ($maSkill < 1001) {
			$i = 1;
		} elseif ($maSkill < 2001) {
			$i = 3;
		} else {
			$i = 5;
		}

		$aoidQL = ((ceil($maSkill / 2) - 1) % 500 + 1);

		$fistQL = min(1500, (int)round($maSkill / 2, 0));
		if ($fistQL <= 200) {
			$speed = 1.25;
		} elseif ($fistQL <= 500) {
			$speed = 1.25 + (0.2 * (($fistQL - 200) / 300));
		} elseif ($fistQL <= 1000) {
			$speed = 1.45 + (0.2 * (($fistQL - 500) / 500));
		} else {
			$speed = 1.65 + (0.2 * (($fistQL - 1000) / 500));
		}
		$speed = round($speed, 2);

		$blob = "MA Skill: <highlight>{$maSkill}<end>\n\n";
		$maSkill = min(3000, $maSkill);
		
		$min = $this->util->interpolate($skillList[$i], $skillList[($i + 1)], $maMinList[$i], $maMinList[($i + 1)], $maSkill);
		$max = $this->util->interpolate($skillList[$i], $skillList[($i + 1)], $maMaxList[$i], $maMaxList[($i + 1)], $maSkill);
		$crit = $this->util->interpolate($skillList[$i], $skillList[($i + 1)], $maCritList[$i], $maCritList[($i + 1)], $maSkill);
		//$ma_speed = $this->util->interpolate($skill_list[$i], $skill_list[($i + 1)], $MA_fist_speed[$i], $MA_fist_speed[($i + 1)], $MaSkill);
		$maBaseSpeed = (($maSkill - $skillList[$i]) * ($maFistSpeed[($i + 1)] - $maFistSpeed[$i])) / ($skillList[($i + 1)] - $skillList[$i]) + $maFistSpeed[$i];
		$maFistSpeed = round($maBaseSpeed, 2);
		$dmg = "<highlight>{$min}<end>-<highlight>{$max}<end> (<highlight>{$crit}<end>)";
		$blob .= "<header2>Martial Artist<end> (".  $this->text->makeItem($maAOID[$i], $maAOID[$i+1], $aoidQL, "item") . ")\n";
		$blob .= "<tab>Fist speed:   <highlight>{$maFistSpeed}<end>s/<highlight>{$maFistSpeed}<end>s\n";
		$blob .= "<tab>Fist damage: {$dmg}\n\n";

		$min = $this->util->interpolate($skillList[$i], $skillList[($i + 1)], $shadeMinList[$i], $shadeMinList[($i + 1)], $maSkill);
		$max = $this->util->interpolate($skillList[$i], $skillList[($i + 1)], $shadeMaxList[$i], $shadeMaxList[($i + 1)], $maSkill);
		$crit = $this->util->interpolate($skillList[$i], $skillList[($i + 1)], $shadeCritList[$i], $shadeCritList[($i + 1)], $maSkill);
		$dmg = "<highlight>".$min."<end>-<highlight>".$max."<end> (<highlight>".$crit."<end>)";
		$blob .= "<header2>Shade<end> (".  $this->text->makeItem($shadeAOID[$i], $shadeAOID[$i+1], $aoidQL, "item") . ")\n";
		$blob .= "<tab>Fist speed:   <highlight>{$speed}<end>s/<highlight>{$speed}<end>s\n";
		$blob .= "<tab>Fist damage: {$dmg}\n\n";

		$min = $this->util->interpolate($skillList[$i], $skillList[($i + 1)], $otherMinList[$i], $otherMinList[($i + 1)], $maSkill);
		$max = $this->util->interpolate($skillList[$i], $skillList[($i + 1)], $otherMaxList[$i], $otherMaxList[($i + 1)], $maSkill);
		$crit = $this->util->interpolate($skillList[$i], $skillList[($i + 1)], $otherCritList[$i], $otherCritList[($i + 1)], $maSkill);
		$dmg = "<highlight>".$min."<end>-<highlight>".$max."<end> (<highlight>".$crit."<end>)";
		$blob .= "<header2>All other professions<end> (".  $this->text->makeItem($otherAOID[$i], $otherAOID[$i+1], $aoidQL, "item") . ")\n";
		$blob .= "<tab>Fist speed:   <highlight>{$speed}<end>s/<highlight>{$speed}<end>s\n";
		$blob .= "<tab>Fist damage: {$dmg}\n\n";

		$msg = $this->text->makeBlob("Martial Arts Results", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("nanoinit")
	 * @Matches("/^nanoinit (\d*\.?\d+) (\d+)$/i")
	 */
	public function nanoInitCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$attackTime = (float)$args[1];
		$initSkill = (int)$args[2];

		$attackTimeReduction = $this->calcAttackTimeReduction($initSkill);
		$effectiveAttackTime = $attackTime - $attackTimeReduction;

		$barSetting = $this->calcBarSetting($effectiveAttackTime);
		if ($barSetting < 0) {
			$barSetting = 0;
		}
		if ($barSetting > 100) {
			$barSetting = 100;
		}

		$fullAggInits = $this->calcInits($attackTime - 1);
		$neutralInits = $this->calcInits($attackTime);
		$fulldefInits = $this->calcInits($attackTime + 1);

		$blob = "Attack:    <highlight>${attackTime}<end> second(s)\n";
		$blob .= "Init Skill:  <highlight>${initSkill}<end>\n";
		$blob .= "Def/Agg:  <highlight>" . round($barSetting, 0) . "%<end>\n";
		$blob .= "You must set your AGG bar at <highlight>" . round($barSetting, 0) ."% (". round($barSetting * 8 / 100, 2) .") <end>to instacast your nano.\n\n";
		$blob .= "(<a href=skillid://51>Agg/def-Slider</a> should read <highlight>" . round($barSetting*2-100, 0) . "<end>).\n\n";
		$blob .= "Init needed to instacast at:\n";
		$blob .= "  Full Agg (100%): <highlight>${fullAggInits}<end> inits\n";
		$blob .= "  Neutral (87.5%): <highlight>${neutralInits}<end> inits\n";
		$blob .= "  Full Def (0%):     <highlight>${fulldefInits}<end> inits\n\n";
		
		$bar = "llllllllllllllllllllllllllllllllllllllllllllllllll";
		$markerPos = (int)round($barSetting/100*strlen($bar), 0);
		$leftBar    = substr($bar, 0, $markerPos);
		$rightBar   = substr($bar, $markerPos+1);
		$blob .= "<highlight>${fulldefInits}<end> DEF <green>${leftBar}<end><red>│<end><green>${rightBar}<end> AGG <highlight>${fullAggInits}<end>\n";
		$blob .= "                         You: <highlight>${initSkill}<end>\n\n";

		$msg = $this->text->makeBlob("Nano Init Results", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("weapon")
	 * @Matches('|^weapon <a href="itemref://(\d+)/(\d+)/(\d+)">|i')
	 * @Matches('|^weapon (\d+) (\d+)|i')
	 */
	public function weaponCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		if (count($args) == 4) {
			$highid = (int)$args[2];
			$ql = (int)$args[3];
		} else {
			$highid = (int)$args[1];
			$ql = (int)$args[2];
		}

		// this is a hack since Worn Soft Pepper Pistol has its high and low ids reversed in-game
		// there may be others
		$sql = "SELECT *, 1 AS order_col FROM aodb WHERE highid = ? AND lowql <= ? AND highql >= ? 
				UNION
				SELECT *, 2 AS order_col FROM aodb WHERE lowid = ? AND lowql <= ? AND highql >= ?
				ORDER BY order_col ASC";
		/** @var ?AODBEntry */
		$row = $this->db->fetch(AODBEntry::class, $sql, $highid, $ql, $ql, $highid, $ql, $ql);

		if ($row === null) {
			$msg = "Item does not exist in the items database.";
			$sendto->reply($msg);
			return;
		}

		/** @var ?WeaponAttribute */
		$lowAttributes = $this->db->fetch(WeaponAttribute::class, "SELECT * FROM weapon_attributes WHERE id = ?", $row->lowid);
		/** @var ?WeaponAttribute */
		$highAttributes = $this->db->fetch(WeaponAttribute::class, "SELECT * FROM weapon_attributes WHERE id = ?", $row->highid);

		if ($lowAttributes === null || $highAttributes === null) {
			$msg = "Could not find any weapon info for this item.";
			$sendto->reply($msg);
			return;
		}

		$name = $row->name;
		$attackTime = $this->util->interpolate($row->lowql, $row->highql, $lowAttributes->attack_time, $highAttributes->attack_time, $ql);
		$rechargeTime = $this->util->interpolate($row->lowql, $row->highql, $lowAttributes->recharge_time, $highAttributes->recharge_time, $ql);
		$rechargeTime /= 100;
		$attackTime /= 100;
		$itemLink = $this->text->makeItem($row->lowid, $row->highid, $ql, $row->name);

		$blob = '';

		$blob .= "<header2>Stats<end>\n";
		$blob .= "<tab>Item:       {$itemLink}\n";
		$blob .= "<tab>Attack:    <highlight>" . sprintf("%.2f", $attackTime) . "<end>s\n";
		$blob .= "<tab>Recharge: <highlight>" . sprintf("%.2f", $rechargeTime) . "<end>s\n\n";

		// inits
		$blob .= "<header2>Agg/Def<end>\n";
		$blob .= $this->getInitDisplay($attackTime, $rechargeTime);
		$blob .= "\n";

		if ($highAttributes->full_auto !== null) {
			$full_auto_recharge = $this->util->interpolate($row->lowql, $row->highql, $lowAttributes->full_auto, $highAttributes->full_auto, $ql);
			[$weaponCap, $skillCap] = $this->capFullAuto($attackTime, $rechargeTime, $full_auto_recharge);
			$blob .= "<header2>Full Auto<end>\n";
			$blob .= "<tab>You need <highlight>".$skillCap."<end> Full Auto skill to cap your recharge at <highlight>".$weaponCap."<end>s.\n\n";
			$found = true;
		}
		if ($highAttributes->burst !== null) {
			$burst_recharge = $this->util->interpolate($row->lowql, $row->highql, $lowAttributes->burst, $highAttributes->burst, $ql);
			[$weaponCap, $skillCap] = $this->capBurst($attackTime, $rechargeTime, $burst_recharge);
			$blob .= "<header2>Burst<end>\n";
			$blob .= "<tab>You need <highlight>".$skillCap."<end> Burst skill to cap your recharge at <highlight>".$weaponCap."<end>s.\n\n";
			$found = true;
		}
		if ($highAttributes->fling_shot) {
			[$weaponCap, $skillCap] = $this->capFlingShot($attackTime);
			$blob .= "<header2>Fligh Shot<end>\n";
			$blob .= "<tab>You need <highlight>".$skillCap."<end> Fling Shot skill to cap your recharge at <highlight>".$weaponCap."<end>s.\n\n";
			$found = true;
		}
		if ($highAttributes->fast_attack) {
			[$weaponCap, $skillCap] = $this->capFastAttack($attackTime);
			$blob .= "<header2>Fast Attack<end>\n";
			$blob .= "<tab>You need <highlight>".$skillCap."<end> Fast Attack skill to cap your recharge at <highlight>".$weaponCap."<end>s.\n\n";
			$found = true;
		}
		if ($highAttributes->aimed_shot) {
			[$weaponCap, $skillCap] = $this->capAimedShot($attackTime, $rechargeTime);
			$blob .= "<header2>Aimed Shot<end>\n";
			$blob .= "<tab>You need <highlight>".$skillCap."<end> Aimed Shot skill to cap your recharge at <highlight>".$weaponCap."<end>s.\n\n";
			$found = true;
		}
		if ($highAttributes->brawl) {
			$blob .= "<header2>Brawl<end>\n";
			$blob .= "<tab>This weapon supports 1 brawl attack every <highlight>15s<end> (constant).\n\n";
			$found = true;
		}
		if ($highAttributes->sneak_attack) {
			$blob .= "<header2>Sneak Attack<end>\n";
			$blob .= "<tab>This weapon supports sneak attacks.\n";
			$blob .= "<tab>The recharge depends solely on your Sneak Attack Skill:\n";
			$blob .= "<tab>40 - (Sneak Attack skill) / 150\n\n";
			$found = true;
		}

		if (!$found) {
			$blob .= "There are no specials on this weapon that could be calculated.\n\n";
		}

		$blob .= "\nRewritten by Nadyita (RK5)";
		$msg = $this->text->makeBlob("Weapon Info for $name", $blob);

		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("weapon")
	 * @Matches('/^weapon (\d+) (.+)/i')
	 * @Matches('/^weapon (.+)/i')
	 */
	public function weaponSearchCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$ql = null;
		$search = $args[1];
		if (count($args) > 2) {
			$ql = (int)$args[1];
			$search = $args[2];
		}
		$data = $this->itemsController->findItemsFromLocal($search, $ql);
		$kept = [];
		$data = array_values(
			array_filter(
				$data,
				function(ItemSearchResult $item) use ($ql, &$kept): bool {
					if (isset($ql) && $ql < $item->lowql || $ql > $item->highql) {
						return false;
					}
					if (isset($kept[$item->lowid])) {
						return false;
					}
					if ($this->db->fetch(
						WeaponAttribute::class,
						"SELECT * FROM `weapon_attributes` WHERE `id`=? OR `id`=?",
						$item->lowid,
						$item->highid
					) === null) {
						return false;
					}
					$kept[$item->lowid] = true;
					return true;
				}
			)
		);
		if (count($data) === 0) {
			if ($ql !== null) {
				$msg = "No QL <highlight>{$ql}<end> items found matching <highlight>{$search}<end>.";
			} else {
				$msg = "No items found matching <highlight>{$search}<end>.";
			}
			$sendto->reply($msg);
			return;
		}
		if (count($data) === 1) {
			$this->weaponCommand($message, $channel, $sender, $sendto, [$message, $data[0]->lowid, $ql ?? $data[0]->ql]);
			return;
		}
		/** @var ItemSearchResult[] $data */
		$blob = "<header2>Weapons matching {$search}<end>\n";
		foreach ($data as $item) {
			$useQL = $ql ?? $item->ql;
			$itemLink = $this->text->makeItem($item->lowid, $item->highid, $useQL, $item->name);
			$statsLink = $this->text->makeChatcmd("stats", "/tell <myname> weapon {$item->lowid} {$useQL}");
			$blob .= "<tab>[{$statsLink}] {$itemLink} (QL {$useQL})\n";
		}
		$msg = $this->text->makeBlob("Weapons (" . count($data) .")", $blob);
		$sendto->reply($msg);
	}

	public function calcAttackTimeReduction(int $initSkill): float {
		if ($initSkill > 1200) {
			$highRecharge = $initSkill - 1200;
			$attackTimeReduction = ($highRecharge / 600) + 6;
		} else {
			$attackTimeReduction = ($initSkill / 200);
		}

		return $attackTimeReduction;
	}

	public function calcBarSetting(float $effectiveAttackTime): float {
		if ($effectiveAttackTime < 0) {
			return 87.5 + (87.5 * $effectiveAttackTime);
		} elseif ($effectiveAttackTime > 0) {
			return 87.5 + (12 * $effectiveAttackTime);
		}
		return 87.5;
	}

	public function calcInits(float $attackTime): float {
		if ($attackTime < 0) {
			return 0;
		} elseif ($attackTime < 6) {
			return round($attackTime * 200, 2);
		} else {
			return round(1200 + ($attackTime - 6) * 600, 2);
		}
	}

	/**
	 * @return int[]
	 */
	public function capFullAuto(float $attackTime, float $rechargeTime, int $fullAutoRecharge): array {
		$weaponCap = floor(10 + $attackTime);
		$skillCap = ((40 * $rechargeTime) + ($fullAutoRecharge / 100) - 11) * 25;

		return [$weaponCap, $skillCap];
	}

	/**
	 * @return int[]
	 */
	public function capBurst(float $attackTime, float $rechargeTime, int $burstRecharge): array {
		$hard_cap = (int)round($attackTime + 8, 0);
		$skill_cap = (int)floor((($rechargeTime * 20) + ($burstRecharge / 100) - 8) * 25);

		return [$hard_cap, $skill_cap];
	}

	/**
	 * @return int[]
	 */
	public function capFlingShot(float $attackTime): array {
		$weaponCap = 5 + $attackTime;
		$skillCap = (($attackTime * 16) - $weaponCap) * 100;

		return [$weaponCap, $skillCap];
	}

	/**
	 * @return int[]
	 */
	public function capFastAttack(float $attackTime): array {
		$weaponCap = (int)floor(5 + $attackTime);
		$skillCap = (($attackTime * 16) - $weaponCap) * 100;

		return [$weaponCap, $skillCap];
	}

	/**
	 * @return int[]
	 */
	public function capAimedShot(float $attackTime, float $rechargeTime): array {
		$hardCap = (int)floor($attackTime + 10);
		$skillCap = (int)ceil((4000 * $rechargeTime - 1100) / 3);
		//$skill_cap = round((($recharge_time * 4000) - ($attack_time * 100) - 1000) / 3);
		//$skill_cap = ceil(((4000 * $recharge_time) - 1000) / 3);

		return [$hardCap, $skillCap];
	}

	public function getInitDisplay(float $attack, float $recharge): string {
		$blob = '';
		for ($percent = 100; $percent >= 0; $percent -= 10) {
			$init = $this->getInitsForPercent($percent, $attack, $recharge);

			$blob .= "<tab>DEF ";
			$blob .= $this->getAggdefBar($percent);
			$blob .= " AGG ";
			$blob .= $this->text->alignNumber($init, 4, "highlight");
			$blob .= " (" . $this->text->alignNumber($percent, 3) . "%)\n";
		}
		return $blob;
	}
}
