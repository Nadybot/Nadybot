<?php declare(strict_types=1);

namespace Nadybot\Core\DBSchema;

use Nadybot\Core\{
	Attributes\JSON,
	DBRow,
	Registry,
	Util,
};

/**
 * This represents the data the bot stores about a player in the cache and database
 *
 * @package Nadybot\Core\DBSchema
 */
class Player extends DBRow {
	/** The character ID as used by Anarchy Online */
	public int $charid;

	/** The character's first name (the name before $name) */
	#[JSON\Name('first_name')]
	public string $firstname = '';

	/** The character's name as it appears in the game */
	public string $name;

	/** The character's last name (the name after $name) */
	#[JSON\Name('last_name')]
	public string $lastname = '';

	/** What level (1-220) is the character or null if unknown */
	public ?int $level = null;

	/** Any of Nano, Solitus, Atrox or Opifex. Also empty string if unknown */
	public string $breed = '';

	/** Male, Female, Neuter or an empty string if unknown */
	public string $gender = '';

	/** Omni, Clan, Neutral or an empty string if unknown */
	public string $faction = '';

	/** The long profession name (e.g. "Enforcer", not "enf" or "enfo") or an empty string if unknown */
	public ?string $profession = '';

	/**
	 * The title-level title for the profession of this player
	 * For example "The man", "Don" or empty if unknown.
	 */
	public string $prof_title= '';

	/** The name of the ai_level as a rank or empty string if unknown */
	public string $ai_rank = '';

	/** AI level of this player or null if unknown */
	public ?int $ai_level = null;

	/** The id of the org this player is in or null if none or unknown */
	#[JSON\Name('org_id')]
	public ?int $guild_id = null;

	/** The name of the org this player is in or null if none/unknown */
	#[JSON\Name('org')]
	public ?string $guild = '';

	/**
	 * The name of the rank the player has in their org (Veteran, Apprentice) or null if not in an org
	 * or unknown
	 */
	#[JSON\Name('org_rank')]
	public ?string $guild_rank = '';

	/** The numeric rank of the player in their org or null if not in an org/unknown */
	#[JSON\Name('org_rank_id')]
	public ?int $guild_rank_id = null;

	/** In which dimension (RK server) is this character? 4 for test, 5 for RK5, 6 for RK19 */
	public ?int $dimension;

	/** Which head is the player using */
	public ?int $head_id = null;

	/** Numeric PvP-rating of the player (1-7) or null if unknown */
	public ?int $pvp_rating = null;

	/**
	 * Name of the player's PvP title derived from their $pvp_rating or
	 * null if unknown
	 */
	public ?string $pvp_title = null;

	#[JSON\Ignore]
	public string $source = '';

	/** Unix timestamp of the last update of these data */
	public ?int $last_update;

	public function getPronoun(): string {
		if (strtolower($this->gender) === 'female') {
			return 'she';
		}
		if (strtolower($this->gender) === 'male') {
			return 'he';
		}
		return 'they';
	}

	public function getIsAre(): string {
		if (strtolower($this->gender) === 'female') {
			return 'is';
		}
		if (strtolower($this->gender) === 'male') {
			return 'is';
		}
		return 'are';
	}

	/**
	 * Render a text with pronoun/attribute substitution
	 *
	 * Parses text like "%They% %are% currently level %level%/%ai_level%."
	 * The first letter is always lower-cased, unless you give it with the first letter
	 * in uppercase. So "%Faction%" is "Clan" and "%faction%" is "clan"
	 *
	 * @param string $text The text to parse
	 *
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
		$gender = strtolower($this->gender);
		$text = preg_replace_callback(
			'/%([a-z:_A-Z]+)%/',
			function (array $matches) use ($pronouns, $gender): string {
				$pronoun = $matches[1];
				$choices = explode(':', $pronoun);
				if (count($choices) === 2) {
					return $choices[($gender === 'neuter') ? 0 : 1];
				}
				$lc = strtolower($pronoun);
				if (!isset($pronouns[$gender][$lc])) {
					if (property_exists($this, $lc)) {
						$result = lcfirst((string)($this->{$lc} ?? ''));
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

	/** @return array<string,int|string|null> */
	public function getTokens(string $prefix=''): array {
		$tokens = [
			"{$prefix}name" => $this->name,
			"c-{$prefix}name" => "<highlight>{$this->name}<end>",
			"{$prefix}first-name" => $this->firstname,
			"{$prefix}last-name" => $this->lastname,
			"{$prefix}level" => $this->level,
			"c-{$prefix}level" => isset($this->level) ? "<highlight>{$this->level}<end>" : null,
			"{$prefix}ai-level" => $this->ai_level,
			"c-{$prefix}ai-level" => isset($this->ai_level) ? "<green>{$this->ai_level}<end>" : null,
			"{$prefix}prof" => $this->profession,
			"c-{$prefix}prof" => isset($this->profession) ? "<highlight>{$this->profession}<end>" : null,
			"{$prefix}profession" => $this->profession,
			"c-{$prefix}profession" => isset($this->profession) ? "<highlight>{$this->profession}<end>" : null,
			"{$prefix}org" => $this->guild,
			"c-{$prefix}org" => isset($this->guild)
				? '<' . strtolower($this->faction ?? 'highlight') . ">{$this->guild}<end>"
				: null,
			"{$prefix}org-rank" => $this->guild_rank,
			"{$prefix}breed" => $this->breed,
			"c-{$prefix}breed" => isset($this->breed) ? "<highlight>{$this->breed}<end>" : null,
			"{$prefix}faction" => $this->faction,
			"c-{$prefix}faction" => isset($this->faction)
				? '<' . strtolower($this->faction) . ">{$this->faction}<end>"
				: null,
			"{$prefix}gender" => $this->gender,
			"{$prefix}whois" => $this->getInfo(),
			"{$prefix}short-prof" => null,
			"c-{$prefix}short-prof" => null,
		];

		/** @var ?Util */
		$util = Registry::getInstance(Util::class);
		if (isset($util, $this->profession)) {
			$abbr = $tokens["{$prefix}short-prof"] = $util->getProfessionAbbreviation($this->profession);
			$tokens["c-{$prefix}short-prof"] = "<highlight>{$abbr}<end>";
		}
		return $tokens;
	}

	public function getInfo(bool $showFirstAndLastName=true): string {
		$msg = '';

		if ($showFirstAndLastName && strlen($this->firstname??'')) {
			$msg = $this->firstname . ' ';
		}

		$msg .= "<highlight>\"{$this->name}\"<end> ";

		if ($showFirstAndLastName && strlen($this->lastname??'')) {
			$msg .= $this->lastname . ' ';
		}

		$msg .= "(<highlight>{$this->level}<end>/<green>{$this->ai_level}<end>";
		$msg .= ", {$this->gender} {$this->breed} <highlight>{$this->profession}<end>";
		$msg .= ', <' . strtolower($this->faction) . ">{$this->faction}<end>";

		if (isset($this->guild) && strlen($this->guild)) {
			$msg .= ", {$this->guild_rank} of <" . strtolower($this->faction) . ">{$this->guild}<end>)";
		} else {
			$msg .= ', Not in a guild)';
		}

		return $msg;
	}
}
