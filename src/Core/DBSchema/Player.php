<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\DBRow;

/**
 * This represents the data the bot stores about a player in the cache and database
 * @package Nadybot\Core\DBSchema
 */
class Player extends DBRow {
	/**
	 * The character ID as used by Anarchy Online
	 */
	public int $charid;

	/**
	 * The character's first name (the name before $name)
	 * @json:name=first_name
	 */
	public string $firstname = '';

	/**
	 * The character's name as it appears in the game
	 */
	public string $name;

	/**
	 * The character's last name (the name after $name)
	 * @json:name=last_name
	 */
	public string $lastname = '';

	/**
	 * What level (1-220) is the character or null if unknown
	 */
	public ?int $level = null;

	/**
	 * Any of Nano, Solitus, Atrox or Opifex. Also empty string if unknown
	 */
	public string $breed = '';

	/**
	 * Male, Female, Neuter or an empty string if unknown
	 */
	public string $gender = '';

	/**
	 * Omni, Clan, Neutral or an empty string if unknown
	 */
	public string $faction = '';

	/**
	 * The long profession name (e.g. "Enforcer", not "enf" or "enfo") or an empty string if unknown
	 */
	public ?string $profession = '';

	/**
	 * The title-level title for the profession of this player
	 * For example "The man", "Don" or empty if unknown.
	 * @var string
	 */
	public string $prof_title= '';

	/**
	 * The name of the ai_level as a rank or empty string if unknown
	 */
	public string $ai_rank = '';

	/**
	 * AI level of this player or null if unknown
	 */
	public ?int $ai_level = null;

	/**
	 * The id of the org this player is in or null if none or unknown
	 * @json:name=org_id
	 */
	public ?int $guild_id = null;

	/**
	 * The name of the org this player is in or null if none/unknown
	 * @json:name=org
	 */
	public ?string $guild = '';

	/**
	 * The name of the rank the player has in their org (Veteran, Apprentice) or null if not in an org
	 * or unknown
	 * @json:name=org_rank
	 */
	public ?string $guild_rank = '';

	/**
	 * The numeric rank of the player in their org or null if not in an org/unknown
	 * @json:name=org_rank_id
	 */
	public ?int $guild_rank_id = null;

	/**
	 * In which dimension (RK server) is this character? 4 for test, 5 for RK5, 6 for RK19
	 */
	public ?int $dimension;

	/**
	 * Which head is the player using
	 */
	public ?int $head_id = null;

	/**
	 * Numeric PvP-rating of the player (1-7) or null if unknown
	 */
	public ?int $pvp_rating = null;

	/**
	 * Name of the player's PvP title derived from their $pvp_rating or
	 * null if unknown
	 */
	public ?string $pvp_title = null;

	/** @json:ignore */
	public string $source = '';

	/**
	 * Unix timestamp of the last update of these data
	 */
	public ?int $last_update;

	public function getPronoun(): string {
		if (strtolower($this->gender??"") === "female") {
			return "she";
		}
		if (strtolower($this->gender??"") === "male") {
			return "he";
		}
		return "they";
	}

	public function getIsAre(): string {
		if (strtolower($this->gender??"") === "female") {
			return "is";
		}
		if (strtolower($this->gender??"") === "male") {
			return "is";
		}
		return "are";
	}

	/**
	 * Render a text with pronoun/attribute substitution
	 *
	 * Parses text like "%They% %are% currently level %level%/%ai_level%."
	 * The first letter is always lower-cased, unless you give it with the first letter
	 * in uppercase. So "%Faction%" is "Clan" and "%faction%" is "clan"
	 *
	 * @param string $text The text to parse
	 * @return string The rendered text
	 */
	public function text(string $text): string {
		$pronouns = [
			'male' => [
				'they' => 'he',
				'them' => 'him',
				'their' => 'his',
				'theirs' => 'his',
				'themselves' => 'himself',
				'have' => 'has',
				'are' => 'is',
			],
			'female' => [
				'they' => 'she',
				'them' => 'her',
				'their' => 'her',
				'theirs' => 'hers',
				'themselves' => 'herself',
				'have' => 'has',
				'are' => 'is',
			],
		];
		$gender = strtolower($this->gender??"");
		$text = preg_replace_callback(
			"/%([a-z:_A-Z]+)%/",
			function (array $matches) use ($pronouns, $gender): string {
				$pronoun = $matches[1];
				$choices = explode(":", $pronoun);
				if (count($choices) === 2) {
					return $choices[($gender === "neuter") ? 0 : 1];
				}
				$lc = strtolower($pronoun);
				if (!isset($pronouns[$gender][$lc])) {
					if (property_exists($this, $lc)) {
						$result = lcfirst((string)($this->{$lc} ?? ""));
					} else {
						return $pronoun;
					}
				} else {
					$result = $pronouns[$gender][$lc];
				}
				if (ord(substr($pronoun, 0, 1)) < 97) {
					return ucfirst($result);
				}
				return $result;
			},
			$text
		);
		return $text;
	}
}
