import { JsonDecoder } from "ts.data.json";

export enum Breed {
  Solitus = "Solitus",
  Nanomage = "Nano",
  Opifex = "Opifex",
  Atrox = "Atrox",
}

export enum Gender {
  Male = "Male",
  Female = "Female",
  Neuter = "Neuter",
}

export enum Faction {
  Omni = "Omni",
  Clan = "Clan",
  Neutral = "Neutral",
}

export enum Profession {
  Adventurer = "Adventurer",
  Agent = "Agent",
  Bureaucrat = "Bureaucrat",
  Doctor = "Doctor",
  Enforcer = "Enforcer",
  Engineer = "Engineer",
  Fixer = "Fixer",
  Keeper = "Keeper",
  MartialArtist = "Martial Artist",
  MetaPhysicist = "Meta-Physicist",
  NanoTechnician = "Nano-Technician",
  Shade = "Shade",
  Soldier = "Soldier",
  Trader = "Trader",
}

export enum Dimension {
  Test = 4,
  RK5 = 5,
  RK19 = 6,
}

export interface PlayerBase {
  // The character ID as used by Anarchy Online
  readonly charid: number;
  // The character's first name (the name before $name)
  readonly first_name: string;
  // The character's name as it appears in the game
  readonly name: string;
  // The character's last name (the name after $name)
  readonly last_name: string;
  // What level (1-220) is the characer or null if unknown
  readonly level: number;
  // Any of Nanomage, Solitus, Atrox or Opifex. Also empty string if unknown
  readonly breed: Breed;
  // Male, Female, Neuter or an empty string if unknown
  readonly gender: Gender;
  // Omni, Clan, Neutral or an empty string if unknown
  readonly faction: Faction;
  // The long profession name (e.g. "Enforcer", not "enf" or "enfo") or an empty string if unknown
  readonly profession: Profession;
  // The title-level title for the profession of this player For example "The man", "Don" or empty if unknown.
  readonly prof_title: string;
  // The name of the ai_level as a rank or empty string if unknown
  readonly ai_rank: string;
  // AI level of this player or null if unknown
  readonly ai_level: number;
  // The id of the org this player is in or null if none or unknown
  readonly org_id: number;
  // The name of the org this player is in or null if none/unknown
  readonly org: string;
  // The name of the rank the player has in their org (Veteran, Apprentice) or null if not in an org or unknown
  readonly org_rank: string;
  // The numeric rank of the player in their org or null if not in an org/unknown
  readonly org_rank_id: number | null;
  // In which dimension (RK server) is this character? 4 for test, 5 for RK5, 6 for RK19
  readonly dimension: Dimension;
  // Which head is the player using
  readonly head_id: number;
  // Numeric PvP-rating of the player (1-7) or null if unknown
  readonly pvp_rating: number;
  // Name of the player's PvP title derived from their $pvp_rating or null if unknown
  readonly pvp_title: string | null;
  // Unix timestamp of the last update of these data
  readonly last_update: number;
}

export interface OnlinePlayer extends PlayerBase {
  // The AFK message of the player or an empty string
  afk_message: string;
  // The name of the main character, or the same as $name if this is the main character of the player
  readonly main_character: string;
  // True if this player is currently online, false otherwise
  online: boolean;
}

// This is the list of all players considered to be online by the bot
export interface OnlinePlayers {
  org: Array<OnlinePlayer>;
  private_channel: Array<OnlinePlayer>;
}

const breedDecoder = JsonDecoder.enumeration<Breed>(Breed, "Breed");
const genderDecoder = JsonDecoder.enumeration<Gender>(Gender, "Gender");
const factionDecoder = JsonDecoder.enumeration<Faction>(Faction, "Faction");
const professionDecoder = JsonDecoder.enumeration<Profession>(
  Profession,
  "Profession"
);
const dimensionDecoder = JsonDecoder.enumeration<Dimension>(
  Dimension,
  "Dimension"
);

const playerBaseDecoderMapping = {
  charid: JsonDecoder.number,
  first_name: JsonDecoder.string,
  name: JsonDecoder.string,
  last_name: JsonDecoder.string,
  level: JsonDecoder.number,
  breed: breedDecoder,
  gender: genderDecoder,
  faction: factionDecoder,
  profession: professionDecoder,
  prof_title: JsonDecoder.string,
  ai_rank: JsonDecoder.string,
  ai_level: JsonDecoder.number,
  org_id: JsonDecoder.number,
  org: JsonDecoder.string,
  org_rank: JsonDecoder.string,
  org_rank_id: JsonDecoder.nullable(JsonDecoder.number),
  dimension: dimensionDecoder,
  head_id: JsonDecoder.number,
  pvp_rating: JsonDecoder.number,
  pvp_title: JsonDecoder.nullable(JsonDecoder.string),
  last_update: JsonDecoder.number,
};

const onlinePlayerExtraKeysMapping = {
  afk_message: JsonDecoder.string,
  main_character: JsonDecoder.string,
  online: JsonDecoder.boolean,
};

const onlinePlayerMapping = {
  ...playerBaseDecoderMapping,
  ...onlinePlayerExtraKeysMapping,
};

// const playerBaseDecoder = JsonDecoder.objectStrict<PlayerBase>(
//   playerBaseDecoderMapping,
//   "PlayerBase"
// );

const onlinePlayerDecoder = JsonDecoder.objectStrict<OnlinePlayer>(
  onlinePlayerMapping,
  "OnlinePlayer"
);

const onlinePlayerArrayDecoder = JsonDecoder.array<OnlinePlayer>(
  onlinePlayerDecoder,
  "OnlinePlayersArray"
);

export const onlinePlayersDecoder = JsonDecoder.objectStrict<OnlinePlayers>(
  {
    org: onlinePlayerArrayDecoder,
    private_channel: onlinePlayerArrayDecoder,
  },
  "OnlinePlayers"
);
