<?php declare(strict_types=1);

namespace Nadybot\Tests\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\PorkProvider;
use Nadybot\Core\Types\{Faction, Government, Profession};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

#[CoversClass(PorkProvider::class)]
class PorkProviderTest extends TestCase {
	private const ORGLIST = <<<'HTML'
		<!-- ORGS BEGIN -->
		<tr>
			<td align="left">
				<a href="//people.anarchy-online.com/org/stats/d/5/name/725003">
					Troet</a></td>
			<td align="right">122</td>
			<td align="right">50</td>
			<td align="left">Clan</td>
			<td align="left">Republic</td>
			<td align="left" class="dim">RK5</td>
		</tr>
		<tr>
			<td align="left">
				<a href="https://people.anarchy-online.com/org/stats/d/5/name/42">
					Some Org </a></td>
			<td align="right">3</td>
			<td align="right">1</td>
			<td align="left">Omni</td>
			<td align="left">Department</td>
			<td align="left" class="dim">RK5</td>
		</tr>
		HTML;

	private const ORG_ROSTER = <<<'JSON'
		[{"NANORACECOUNT":1,"MINLVL":15,"ENFCOUNT":0,"NUMMEMBERS":2,"NEUTERCOUNT":0,"KEEPERCOUNT":0,
		  "OPIFEXCOUNT":0,"ORG_DIMENSION":5,"MONSTERCOUNT":0,"SOLIDERCOUNT":0,"BTCOUNT":1,"AGENTCOUNT":0,
		  "FIXERCOUNT":0,"MACOUNT":0,"GOVERNINGNAME":"Republic","SOLITUSCOUNT":1,"TRADERCOUNT":1,"METACOUNT":0,
		  "MAXLVL":220,"ORG_INSTANCE":725003,"DOCTORCOUNT":0,"OBJECTIVE":"","ATROXCOUNT":0,"SHADECOUNT":0,
		  "DESCRIPTION":"","HISTORY":"","AVGLVL":117.5,"MALECOUNT":1,"NANOCOUNT":0,"SIDE_NAME":"Clan",
		  "ENGINEEERCOUNT":0,"ADVENTURERCOUNT":0,"SIDE":1,"FEMALECOUNT":1,"NAME":"Troet"},
		 [{"SEX":"Female","BREED":"Nano","PVPRATING":1300,"NAME":"Aaa","CHAR_DIMENSION":5,"RANK_TITLE":"President",
		   "RANK":0,"ALIENLEVEL":30,"PROF_TITLE":"Dictator","HEADID":40,"LEVELX":220,"PROF":"Bureaucrat",
		   "DEFENDER_RANK_TITLE":"Vindicator","FIRSTNAME":"","LASTNAME":"","CHAR_INSTANCE":1234,"PVPTITLE":"Freshman"},
		  {"SEX":"Male","BREED":"Solitus","PVPRATING":1300,"NAME":"Bbb","CHAR_DIMENSION":5,"RANK_TITLE":"Member",
		   "RANK":3,"ALIENLEVEL":0,"PROF_TITLE":"Worker","HEADID":41,"LEVELX":15,"PROF":"Trader",
		   "DEFENDER_RANK_TITLE":"","FIRSTNAME":" Bob ","LASTNAME":""}],
		 "2026/11/11 05:56:21"]
		JSON;

	#[Test]
	public function parsesOrgList(): void {
		$orgs = (new PorkProvider(name: 'test', baseUrl: 'http://test'))->parseOrgList(self::ORGLIST);
		$this->assertCount(2, $orgs);
		$this->assertSame(725_003, $orgs[0]->id);
		$this->assertSame('Troet', $orgs[0]->name);
		$this->assertSame(122, $orgs[0]->numMembers);
		$this->assertSame(Faction::Clan, $orgs[0]->faction);
		$this->assertSame(Government::Republic, $orgs[0]->governingForm);
		$this->assertSame('Some Org', $orgs[1]->name);
		$this->assertSame(Government::Department, $orgs[1]->governingForm);
	}

	#[Test]
	public function parsesOrgRoster(): void {
		$guild = (new PorkProvider(name: 'test', baseUrl: 'http://test'))->parseOrgRoster(self::ORG_ROSTER, 725_003, 5);
		$this->assertNotNull($guild);
		$this->assertSame('Troet', $guild->orgname);
		$this->assertSame(Faction::Clan, $guild->orgside);
		$this->assertSame(Government::Republic, $guild->governing_form);
		$this->assertCount(2, $guild->members);
		$this->assertSame(1_234, $guild->members['Aaa']->charid);
		$this->assertSame(Profession::Bureaucrat, $guild->members['Aaa']->profession);
		$this->assertSame('Vindicator', $guild->members['Aaa']->ai_rank);
		// Unknown character ids are 0, so the GuildManager can look them up
		$this->assertSame(0, $guild->members['Bbb']->charid);
		$this->assertSame('Bob', $guild->members['Bbb']->firstname);
		$this->assertSame(5, $guild->members['Bbb']->dimension);
	}
}
