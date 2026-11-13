<?php declare(strict_types=1);

namespace Nadybot\Tests\Core\Modules\PLAYER_LOOKUP;

use Nadybot\Core\Modules\PLAYER_LOOKUP\Directory\LostEdenProvider;
use Nadybot\Core\Types\{Faction, Government, Profession};
use PHPUnit\Framework\Attributes\{CoversClass, Test};
use PHPUnit\Framework\TestCase;

/** The JSON samples are the ones from Lost Eden's API documentation */
#[CoversClass(LostEdenProvider::class)]
class LostEdenProviderTest extends TestCase {
	private const CHARACTER = <<<'JSON'
		{
		  "id": 4711,
		  "name": "Nady",
		  "firstName": "",
		  "lastName": "",
		  "level": 220,
		  "titleLevel": 7,
		  "alienLevel": 30,
		  "profession": "Bureaucrat",
		  "breed": "Nanomage",
		  "gender": "Female",
		  "faction": "Clan",
		  "headId": 223911,
		  "pvpRating": 1300,
		  "org": { "id": 12, "name": "Team Rainbow", "rank": 2, "rankTitle": "Veteran" },
		  "updatedAt": "2026-11-12T03:00:00.000Z"
		}
		JSON;

	private const UNSAVED_CHARACTER = <<<'JSON'
		{
		  "id": 4712,
		  "name": "Newbie",
		  "firstName": "",
		  "lastName": "",
		  "level": null,
		  "titleLevel": null,
		  "alienLevel": null,
		  "profession": null,
		  "breed": null,
		  "gender": null,
		  "faction": null,
		  "headId": null,
		  "pvpRating": null,
		  "org": null,
		  "updatedAt": "2026-11-12T03:00:00.000Z"
		}
		JSON;

	private const ORG = <<<'JSON'
		{
		  "id": 12,
		  "name": "Team Rainbow",
		  "faction": "Clan",
		  "governingForm": "Republic",
		  "description": "",
		  "objective": "",
		  "history": "",
		  "memberCount": 66,
		  "levels": { "min": 1, "max": 220, "average": 149.71 },
		  "professions": { "Adventurer": 6, "Agent": 4, "Trader": 11 },
		  "breeds": { "Atrox": 15, "Nanomage": 22, "Opifex": 11, "Solitus": 18 },
		  "genders": { "Female": 32, "Male": 19, "Neuter": 15 },
		  "members": [
		    { "id": 4700, "name": "Sillyant", "firstName": "", "lastName": "", "level": 220, "titleLevel": 7, "alienLevel": 30,
		      "profession": "Trader", "breed": "Nanomage", "gender": "Male", "faction": "Clan", "headId": 223923,
		      "pvpRating": 1300, "rank": 0, "rankTitle": "President" }
		  ],
		  "truncated": false,
		  "updatedAt": "2026-11-12T03:00:00.000Z"
		}
		JSON;

	#[Test]
	public function parsesCharacter(): void {
		$player = LostEdenProvider::parseCharacter(self::CHARACTER);
		$this->assertNotNull($player);
		$this->assertSame(4_711, $player->charid);
		$this->assertSame('Nady', $player->name);
		$this->assertSame(7, $player->dimension);
		$this->assertSame(220, $player->level);
		$this->assertSame(Profession::Bureaucrat, $player->profession);
		$this->assertSame('Dictator', $player->prof_title);
		$this->assertSame(30, $player->ai_level);
		$this->assertSame('Vindicator', $player->ai_rank);
		$this->assertSame('Nano', $player->breed);
		$this->assertSame('Female', $player->gender);
		$this->assertSame(Faction::Clan, $player->faction);
		$this->assertSame(223_911, $player->head_id);
		$this->assertSame(1_300, $player->pvp_rating);
		$this->assertNull($player->pvp_title);
		$this->assertSame(12, $player->guild_id);
		$this->assertSame('Team Rainbow', $player->guild);
		$this->assertSame('Veteran', $player->guild_rank);
		$this->assertSame(2, $player->guild_rank_id);
		$this->assertSame(1_794_452_400, $player->last_update);
		$this->assertSame('rubi-ka.org', $player->source);
	}

	#[Test]
	public function parsesNeverSavedCharacter(): void {
		$player = LostEdenProvider::parseCharacter(self::UNSAVED_CHARACTER);
		$this->assertNotNull($player);
		$this->assertSame('Newbie', $player->name);
		$this->assertNull($player->level);
		$this->assertSame(Profession::Unknown, $player->profession);
		$this->assertSame('', $player->prof_title);
		$this->assertSame('', $player->ai_rank);
		$this->assertSame('', $player->breed);
		$this->assertSame(Faction::Unknown, $player->faction);
		$this->assertSame(0, $player->guild_id);
		$this->assertSame('', $player->guild);
	}

	#[Test]
	public function returnsNullForInvalidData(): void {
		$this->assertNull(LostEdenProvider::parseCharacter('{"error":"not_found","message":"x"}'));
		$this->assertNull(LostEdenProvider::parseCharacter('not json'));
		$this->assertNull(LostEdenProvider::parseOrg('{"error":"not_found","message":"x"}'));
	}

	#[Test]
	public function parsesOrg(): void {
		$guild = LostEdenProvider::parseOrg(self::ORG);
		$this->assertNotNull($guild);
		$this->assertSame(12, $guild->guild_id);
		$this->assertSame('Team Rainbow', $guild->orgname);
		$this->assertSame(Faction::Clan, $guild->orgside);
		$this->assertSame(Government::Republic, $guild->governing_form);
		$this->assertSame(1_794_452_400, $guild->last_update?->getTimestamp());
		$this->assertCount(1, $guild->members);
		$member = $guild->members['Sillyant'];
		$this->assertSame(4_700, $member->charid);
		$this->assertSame(7, $member->dimension);
		$this->assertSame(Profession::Trader, $member->profession);
		$this->assertSame('Mogul', $member->prof_title);
		$this->assertSame('Nano', $member->breed);
		$this->assertSame(0, $member->guild_rank_id);
		$this->assertSame('President', $member->guild_rank);
		$this->assertSame(12, $member->guild_id);
		$this->assertSame('Team Rainbow', $member->guild);
		$this->assertSame('org_roster', $member->source);
	}
}
