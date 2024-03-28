<?php declare(strict_types=1);

namespace Nadybot\Core;

use ValueError;

enum Playfield: int {
	/** @var array<string,string|int|null> */
	public const EXAMPLE_TOKENS = [
		'pf-id' => 551,
		'pf-long' => 'Wailing Wastes',
		'pf-short' => 'WW',
	];

	/** @return array<string,string|int|null> */
	public function getTokens(): array {
		return [
			'pf-id' => $this->value,
			'pf-long' => $this->long(),
			'pf-short' => $this->short(),
		];
	}

	public static function byName(string $name): self {
		return match (strtolower($name)) {
			'4 holes' => self::FourHoles,
			'4holes' => self::FourHoles,
			'andromeda' => self::Andromeda,
			'aegean' => self::Aegean,
			'old athen' => self::OldAthen,
			'oldathen' => self::OldAthen,
			'athen shire' => self::AthenShire,
			'athenshire' => self::AthenShire,
			'west athens' => self::WestAthens,
			'westathens' => self::WestAthens,
			'avalon' => self::Avalon,
			'belial forest' => self::BelialForest,
			'belialforest' => self::BelialForest,
			'borealis' => self::Borealis,
			'broken shores' => self::BrokenShores,
			'brokenshores' => self::BrokenShores,
			'central artery valley' => self::CentralArteryValley,
			'centralarteryvalley' => self::CentralArteryValley,
			'clondyke' => self::Clondyke,
			'coast of peace' => self::CoastOfPeace,
			'coastofpeace' => self::CoastOfPeace,
			'coast of tranquility' => self::CoastOfTranquility,
			'coastoftranquility' => self::CoastOfTranquility,
			'deep artery valley' => self::DeepArteryValley,
			'deeparteryvalley' => self::DeepArteryValley,
			'eastern fouls plain' => self::EasternFoulsPlain,
			'easternfoulsplain' => self::EasternFoulsPlain,
			'galway county' => self::GalwayCounty,
			'galwaycounty' => self::GalwayCounty,
			'galway shire' => self::GalwayShire,
			'galwayshire' => self::GalwayShire,
			'greater omni forest' => self::GreaterOmniForest,
			'greateromniforest' => self::GreaterOmniForest,
			'greater tir county' => self::GreaterTirCounty,
			'greatertircounty' => self::GreaterTirCounty,
			'holes in the wall' => self::HolesInTheWall,
			'holesinthewall' => self::HolesInTheWall,
			'lush fields' => self::LushFields,
			'lushfields' => self::LushFields,
			'milky way' => self::MilkyWay,
			'milkyway' => self::MilkyWay,
			'mutant domain' => self::MutantDomain,
			'mutantdomain' => self::MutantDomain,
			'newland' => self::Newland,
			'newland city' => self::NewlandCity,
			'newlandcity' => self::NewlandCity,
			'newland desert' => self::NewlandDesert,
			'newlanddesert' => self::NewlandDesert,
			'omni forest' => self::OmniForest,
			'omniforest' => self::OmniForest,
			'omni-1 entertainment' => self::OmniEnt,
			'omni-1 ent' => self::OmniEnt,
			'omni ent' => self::OmniEnt,
			'omnient' => self::OmniEnt,
			'omni-1 hq' => self::OmniHQ,
			'omni-1hq' => self::OmniHQ,
			'omni hq' => self::OmniHQ,
			'omnihq' => self::OmniHQ,
			'omni-1 trade' => self::OmniTrade,
			'omni-1trade' => self::OmniTrade,
			'omni1trade' => self::OmniTrade,
			'omni trade' => self::OmniTrade,
			'omnitrade' => self::OmniTrade,
			'perpetual wastelands' => self::PerpetualWastelands,
			'perpetualwastelands' => self::PerpetualWastelands,
			'pleasant meadows' => self::PleasantMeadows,
			'pleasantmeadows' => self::PleasantMeadows,
			'rome blue' => self::RomeBlue,
			'romeblue' => self::RomeBlue,
			'rome green' => self::RomeGreen,
			'romegreen' => self::RomeGreen,
			'rome red' => self::RomeRed,
			'romered' => self::RomeRed,
			'southern artery valley' => self::SouthernArteryValley,
			'southernarteryvalley' => self::SouthernArteryValley,
			'southern fouls hills' => self::SouthernFoulsHills,
			'southernfoulshills' => self::SouthernFoulsHills,
			'stret east bank' => self::StretEastBank,
			'streteastbank' => self::StretEastBank,
			'stret west bank' => self::StretWestBank,
			'stretwestbank' => self::StretWestBank,
			'the longest road' => self::TheLongestRoad,
			'thelongestroad' => self::TheLongestRoad,
			'the reck' => self::TheReck,
			'thereck' => self::TheReck,
			'reck' => self::TheReck,
			'tir' => self::Tir,
			'tir county' => self::TirCounty,
			'tircounty' => self::TirCounty,
			'upper stret east bank' => self::UpperStretEastBank,
			'upperstreteastbank' => self::UpperStretEastBank,
			'varmint woods' => self::VarmintWoods,
			'wailing wastes' => self::WailingWastes,
			'wailingwastes' => self::WailingWastes,
			'wartorn valley' => self::WartornValley,
			'wartornvalley' => self::WartornValley,
			'holoworld: omni training' => self::HoloworldOmniTraining,
			'holoworld omni training' => self::HoloworldOmniTraining,
			'holoworldomnitraining' => self::HoloworldOmniTraining,
			'holoworldomni' => self::HoloworldOmniTraining,
			'holoworld omni' => self::HoloworldOmniTraining,
			'holoworld' => self::HoloworldOmniTraining,
			'park: clan training' => self::ParkClanTraining,
			'park clan training' => self::ParkClanTraining,
			'parkclantraining' => self::ParkClanTraining,
			'park clan' => self::ParkClanTraining,
			'parkclan' => self::ParkClanTraining,
			'park' => self::ParkClanTraining,
			'junkyard: neutral training' => self::JunkyardNeutralTraining,
			'junkyard neutral training' => self::JunkyardNeutralTraining,
			'junkyardneutraltraining' => self::JunkyardNeutralTraining,
			'neutral training' => self::JunkyardNeutralTraining,
			'neutraltraining' => self::JunkyardNeutralTraining,
			'icc shuttleport' => self::ICCShuttleport,
			'iccshuttleport' => self::ICCShuttleport,
			'shuttleport' => self::ICCShuttleport,
			'uturn canyon' => self::UturnCanyon,
			'uturncanyon' => self::UturnCanyon,
			'uturn forest' => self::UturnForest,
			'uturnforest' => self::UturnForest,
			'three craters east' => self::ThreeCratersEast,
			'threecraterseast' => self::ThreeCratersEast,
			'three craters west' => self::ThreeCratersWest,
			'threecraterswest' => self::ThreeCratersWest,
			'arete' => self::Arete,
			'unicorn outpost' => self::UnicornOutpost,
			'unicornoutpost' => self::UnicornOutpost,
			'adonis city' => self::AdonisCity,
			'ado city' => self::AdonisCity,
			'adoniscity' => self::AdonisCity,
			'adocity' => self::AdonisCity,
			'adonis abyss' => self::AdonisAbyss,
			'ado abyss' => self::AdonisAbyss,
			'adonisabyss' => self::AdonisAbyss,
			'adoabyss' => self::AdonisAbyss,
			'elysium' => self::Elysium,
			'elysium east' => self::ElysiumEast,
			'elysiumeast' => self::ElysiumEast,
			'elysium e' => self::ElysiumEast,
			'elysiume' => self::ElysiumEast,
			'elysium north' => self::ElysiumNorth,
			'elysiumnorth' => self::ElysiumNorth,
			'elysium n' => self::ElysiumNorth,
			'elysiumn' => self::ElysiumNorth,
			'elysium south' => self::ElysiumSouth,
			'elysiumsouth' => self::ElysiumSouth,
			'elysium s' => self::ElysiumSouth,
			'elysiums' => self::ElysiumSouth,
			'elysium west' => self::ElysiumWest,
			'elysiumwest' => self::ElysiumWest,
			'elysium w' => self::ElysiumWest,
			'elysiumw' => self::ElysiumWest,
			'inferno' => self::Inferno,
			'inferno burning marshes' => self::BurningMarshes,
			'burning marshes' => self::BurningMarshes,
			'marshes' => self::BurningMarshes,
			'jobe harbor' => self::JobeHarbor,
			'jobeharbor' => self::JobeHarbor,
			'jobe market' => self::JobeMarket,
			'jobemarket' => self::JobeMarket,
			'jobe platform' => self::JobePlatform,
			'jobeplatform' => self::JobePlatform,
			'jobe plaza' => self::JobePlaza,
			'jobeplaza' => self::JobePlaza,
			'jobe research' => self::JobeResearch,
			'joberesearch' => self::JobeResearch,
			'nascense frontier' => self::NascenseFrontier,
			'nascensefrontier' => self::NascenseFrontier,
			'nascense swamp' => self::NascenseSwamp,
			'nascenseswamp' => self::NascenseSwamp,
			'nascense wilds' => self::NascenseWilds,
			'nascensewilds' => self::NascenseWilds,
			'penumbra forest' => self::PenumbraForest,
			'penumbraforest' => self::PenumbraForest,
			'penumbra hollow' => self::PenumbraHollow,
			'penumbrahollow' => self::PenumbraHollow,
			'penumbra valley' => self::PenumbraValley,
			'penumbravalley' => self::PenumbraValley,
			'pandemonium antenora' => self::PandemoniumAntenora,
			'pandemoniumantenora' => self::PandemoniumAntenora,
			'antenora' => self::PandemoniumAntenora,
			'pandemonium caina' => self::PandemoniumCaina,
			'pandemoniumcaina' => self::PandemoniumCaina,
			'caina' => self::PandemoniumCaina,
			'pandemonium judecca' => self::PandemoniumJudecca,
			'pandemoniumjudecca' => self::PandemoniumJudecca,
			'judecca' => self::PandemoniumJudecca,
			'pandemonium ptolemea' => self::PandemoniumPtolemea,
			'pandemoniumptolemea' => self::PandemoniumPtolemea,
			'ptolemea' => self::PandemoniumPtolemea,
			'scheol upper' => self::ScheolUpper,
			'scheolupper' => self::ScheolUpper,
			'upper scheol' => self::ScheolUpper,
			'scheol lower' => self::ScheolLower,
			'scheollower' => self::ScheolLower,
			'lower scheol' => self::ScheolUpper,
			'unicorn defence hub' => self::UnicornDefenceHub,
			'unicorndefencehub' => self::UnicornDefenceHub,
			'unicorn hub' => self::UnicornDefenceHub,
			'unicornhub' => self::UnicornDefenceHub,
			'lox hub' => self::LoxHub,
			'loxhub' => self::LoxHub,
			'sbc-xpm site alpha-romeo 21' => self::SBCXpmSiteAlphaRomeo21,
			'alpha-romeo 21' => self::SBCXpmSiteAlphaRomeo21,
			'sbc-xpm 21' => self::SBCXpmSiteAlphaRomeo21,
			'sbc-xpm site alpha-romeo 22' => self::SBCXpmSiteAlphaRomeo22,
			'alpha-romeo 22' => self::SBCXpmSiteAlphaRomeo22,
			'sbc-xpm 22' => self::SBCXpmSiteAlphaRomeo22,
			'sbc-xpm site alpha-romeo 23' => self::SBCXpmSiteAlphaRomeo23,
			'alpha-romeo 23' => self::SBCXpmSiteAlphaRomeo23,
			'sbc-xpm 23' => self::SBCXpmSiteAlphaRomeo23,
			'sbc-xpm site alpha-romeo 24' => self::SBCXpmSiteAlphaRomeo24,
			'alpha-romeo 24' => self::SBCXpmSiteAlphaRomeo24,
			'sbc-xpm 24' => self::SBCXpmSiteAlphaRomeo24,
			'sbc-xpm site alpha-romeo 25' => self::SBCXpmSiteAlphaRomeo25,
			'alpha-romeo 25' => self::SBCXpmSiteAlphaRomeo25,
			'sbc-xpm 25' => self::SBCXpmSiteAlphaRomeo25,
			'sbc-xpm site alpha-romeo 26' => self::SBCXpmSiteAlphaRomeo26,
			'alpha-romeo 26' => self::SBCXpmSiteAlphaRomeo26,
			'sbc-xpm 26' => self::SBCXpmSiteAlphaRomeo26,
			'sbc-xpm site alpha-romeo 27' => self::SBCXpmSiteAlphaRomeo27,
			'alpha-romeo 27' => self::SBCXpmSiteAlphaRomeo27,
			'sbc-xpm 27' => self::SBCXpmSiteAlphaRomeo27,
			'4ho' => self::FourHoles,
			'and' => self::Andromeda,
			'aeg' => self::Aegean,
			'oa' => self::OldAthen,
			'as' => self::AthenShire,
			'wa' => self::WestAthens,
			'av' => self::Avalon,
			'bf' => self::BelialForest,
			'bor' => self::Borealis,
			'bs' => self::BrokenShores,
			'cav' => self::CentralArteryValley,
			'clon' => self::Clondyke,
			'cp' => self::CoastOfPeace,
			'ct' => self::CoastOfTranquility,
			'dav' => self::DeepArteryValley,
			'efp' => self::EasternFoulsPlain,
			'gc' => self::GalwayCounty,
			'gs' => self::GalwayShire,
			'gof' => self::GreaterOmniForest,
			'gtc' => self::GreaterTirCounty,
			'hitw' => self::HolesInTheWall,
			'lf' => self::LushFields,
			'mw' => self::MilkyWay,
			'mort' => self::Mort,
			'md' => self::MutantDomain,
			'nl' => self::Newland,
			'nc' => self::NewlandCity,
			'nld' => self::NewlandDesert,
			'of' => self::OmniForest,
			'ent' => self::OmniEnt,
			'ohq' => self::OmniHQ,
			'ot' => self::OmniTrade,
			'pw' => self::PerpetualWastelands,
			'pm' => self::PleasantMeadows,
			'rb' => self::RomeBlue,
			'rg' => self::RomeGreen,
			'rr' => self::RomeRed,
			'sav' => self::SouthernArteryValley,
			'sfh' => self::SouthernFoulsHills,
			'seb' => self::StretEastBank,
			'swb' => self::StretWestBank,
			'tlr' => self::TheLongestRoad,
			'tr' => self::TheReck,
			'tc' => self::TirCounty,
			'useb' => self::UpperStretEastBank,
			'vw' => self::VarmintWoods,
			'ww' => self::WailingWastes,
			'wv' => self::WartornValley,
			'hwo' => self::HoloworldOmniTraining,
			'pct' => self::ParkClanTraining,
			'jy' => self::JunkyardNeutralTraining,
			'isp' => self::ICCShuttleport,
			'uc' => self::UturnCanyon,
			'uf' => self::UturnForest,
			'3ce' => self::ThreeCratersEast,
			'3cw' => self::ThreeCratersWest,
			'apfhub' => self::UnicornOutpost,
			'ado' => self::AdonisAbyss,
			'ely' => self::Elysium,
			'elyeast' => self::ElysiumEast,
			'elynorth' => self::ElysiumNorth,
			'elysouth' => self::ElysiumSouth,
			'elywest' => self::ElysiumWest,
			'inf' => self::Inferno,
			'infmarshes' => self::BurningMarshes,
			'harbor' => self::JobeHarbor,
			'market' => self::JobeMarket,
			'platform' => self::JobePlatform,
			'plaza' => self::JobePlaza,
			'research' => self::JobeResearch,
			'nascfrontier' => self::NascenseFrontier,
			'nascswamp' => self::NascenseSwamp,
			'nascwilds' => self::NascenseWilds,
			'penforest' => self::PenumbraForest,
			'penhollow' => self::PenumbraHollow,
			'penvalley' => self::PenumbraValley,
			'pandeantenora' => self::PandemoniumAntenora,
			'pandecaina' => self::PandemoniumCaina,
			'pandejudecca' => self::PandemoniumJudecca,
			'pandeptolemea' => self::PandemoniumPtolemea,
			'mines-lvl30' => self::SBCXpmSiteAlphaRomeo21,
			'mines-lvl60' => self::SBCXpmSiteAlphaRomeo22,
			'mines-lvl100' => self::SBCXpmSiteAlphaRomeo23,
			'mines-lvl150' => self::SBCXpmSiteAlphaRomeo24,
			'mines-lvl200' => self::SBCXpmSiteAlphaRomeo25,
			'mines-lvl214' => self::SBCXpmSiteAlphaRomeo26,
			'mines-lvl220' => self::SBCXpmSiteAlphaRomeo27,
			default => throw new ValueError("\"{$name}\" is not a valid backing value for eum \"Playfield\""),
		};
	}

	public function long(): string {
		return match ($this) {
			self::FourHoles => '4 Holes',
			self::Andromeda => 'Andromeda',
			self::Aegean => 'Aegean',
			self::OldAthen => 'Old Athen',
			self::AthenShire => 'Athen Shire',
			self::WestAthens => 'West Athens',
			self::Avalon => 'Avalon',
			self::BelialForest => 'Belial Forest',
			self::Borealis => 'Borealis',
			self::BrokenShores => 'Broken Shores',
			self::CentralArteryValley => 'Central Artery Valley',
			self::Clondyke => 'Clondyke',
			self::CoastOfPeace => 'Coast of Peace',
			self::CoastOfTranquility => 'Coast of Tranquility',
			self::DeepArteryValley => 'Deep Artery Valley',
			self::EasternFoulsPlain => 'Eastern Fouls Plain',
			self::GalwayCounty => 'Galway County',
			self::GalwayShire => 'Galway Shire',
			self::GreaterOmniForest => 'Greater Omni Forest',
			self::GreaterTirCounty => 'Greater Tir County',
			self::HolesInTheWall => 'Holes in the Wall',
			self::LushFields => 'Lush Fields',
			self::MilkyWay => 'Milky Way',
			self::Mort => 'Mort',
			self::MutantDomain => 'Mutant Domain',
			self::Newland => 'Newland',
			self::NewlandCity => 'Newland City',
			self::NewlandDesert => 'Newland Desert',
			self::OmniForest => 'Omni Forest',
			self::OmniEnt => 'Omni-1 Entertainment',
			self::OmniHQ => 'Omni-1 HQ',
			self::OmniTrade => 'Omni-1 Trade',
			self::PerpetualWastelands => 'Perpetual Wastelands',
			self::PleasantMeadows => 'Pleasant Meadows',
			self::RomeBlue => 'Rome Blue',
			self::RomeGreen => 'Rome Green',
			self::RomeRed => 'Rome Red',
			self::SouthernArteryValley => 'Southern Artery Valley',
			self::SouthernFoulsHills => 'Southern Fouls Hills',
			self::StretEastBank => 'Stret East Bank',
			self::StretWestBank => 'Stret West Bank',
			self::TheLongestRoad => 'The Longest Road',
			self::TheReck => 'The Reck',
			self::Tir => 'Tir',
			self::TirCounty => 'Tir County',
			self::UpperStretEastBank => 'Upper Stret East Bank',
			self::VarmintWoods => 'Varmint Woods',
			self::WailingWastes => 'Wailing Wastes',
			self::WartornValley => 'Wartorn Valley',
			self::HoloworldOmniTraining => 'Holoworld: Omni Training',
			self::ParkClanTraining => 'Park: Clan Training',
			self::JunkyardNeutralTraining => 'Junkyard: Neutral Training',
			self::ICCShuttleport => 'ICC Shuttleport',
			self::UturnCanyon => 'Uturn Canyon',
			self::UturnForest => 'Uturn Forest',
			self::ThreeCratersEast => 'Three Craters East',
			self::ThreeCratersWest => 'Three Craters West',
			self::Arete => 'Arete',
			self::UnicornOutpost => 'Unicorn Outpost',
			self::AdonisCity => 'Adonis City',
			self::AdonisAbyss => 'Adonis Abyss',
			self::Elysium => 'Elysium',
			self::ElysiumEast => 'Elysium East',
			self::ElysiumNorth => 'Elysium North',
			self::ElysiumSouth => 'Elysium South',
			self::ElysiumWest => 'Elysium West',
			self::Inferno => 'Inferno',
			self::BurningMarshes => 'Inferno Burning Marshes',
			self::JobeHarbor => 'Jobe Harbor',
			self::JobeMarket => 'Jobe Market',
			self::JobePlatform => 'Jobe Platform',
			self::JobePlaza => 'Jobe Plaza',
			self::JobeResearch => 'Jobe Research',
			self::NascenseFrontier => 'Nascense Frontier',
			self::NascenseSwamp => 'Nascense Swamp',
			self::NascenseWilds => 'Nascense Wilds',
			self::PenumbraForest => 'Penumbra Forest',
			self::PenumbraHollow => 'Penumbra Hollow',
			self::PenumbraValley => 'Penumbra Valley',
			self::PandemoniumAntenora => 'Pandemonium Antenora',
			self::PandemoniumCaina => 'Pandemonium Caina',
			self::PandemoniumJudecca => 'Pandemonium Judecca',
			self::PandemoniumPtolemea => 'Pandemonium Ptolemea',
			self::ScheolUpper => 'Scheol Upper',
			self::ScheolLower => 'Scheol Lower',
			self::UnicornDefenceHub => 'Unicorn Defence Hub',
			self::LoxHub => 'LoX Hub',
			self::SBCXpmSiteAlphaRomeo21 => 'SBC-Xpm Site Alpha-Romeo 21',
			self::SBCXpmSiteAlphaRomeo22 => 'SBC-Xpm Site Alpha-Romeo 22',
			self::SBCXpmSiteAlphaRomeo23 => 'SBC-Xpm Site Alpha-Romeo 23',
			self::SBCXpmSiteAlphaRomeo24 => 'SBC-Xpm Site Alpha-Romeo 24',
			self::SBCXpmSiteAlphaRomeo25 => 'SBC-Xpm Site Alpha-Romeo 25',
			self::SBCXpmSiteAlphaRomeo26 => 'SBC-Xpm Site Alpha-Romeo 26',
			self::SBCXpmSiteAlphaRomeo27 => 'SBC-Xpm Site Alpha-Romeo 27',
		};
	}

	public function short(): string {
		return match ($this) {
			self::FourHoles => '4HO',
			self::Andromeda => 'AND',
			self::Aegean => 'AEG',
			self::OldAthen => 'OA',
			self::AthenShire => 'AS',
			self::WestAthens => 'WA',
			self::Avalon => 'AV',
			self::BelialForest => 'BF',
			self::Borealis => 'BOR',
			self::BrokenShores => 'BS',
			self::CentralArteryValley => 'CAV',
			self::Clondyke => 'CLON',
			self::CoastOfPeace => 'CP',
			self::CoastOfTranquility => 'CT',
			self::DeepArteryValley => 'DAV',
			self::EasternFoulsPlain => 'EFP',
			self::GalwayCounty => 'GC',
			self::GalwayShire => 'GS',
			self::GreaterOmniForest => 'GOF',
			self::GreaterTirCounty => 'GTC',
			self::HolesInTheWall => 'HITW',
			self::LushFields => 'LF',
			self::MilkyWay => 'MW',
			self::Mort => 'MORT',
			self::MutantDomain => 'MD',
			self::Newland => 'NL',
			self::NewlandCity => 'NC',
			self::NewlandDesert => 'NLD',
			self::OmniForest => 'OF',
			self::OmniEnt => 'ENT',
			self::OmniHQ => 'OHQ',
			self::OmniTrade => 'OT',
			self::PerpetualWastelands => 'PW',
			self::PleasantMeadows => 'PM',
			self::RomeBlue => 'RB',
			self::RomeGreen => 'RG',
			self::RomeRed => 'RR',
			self::SouthernArteryValley => 'SAV',
			self::SouthernFoulsHills => 'SFH',
			self::StretEastBank => 'SEB',
			self::StretWestBank => 'SWB',
			self::TheLongestRoad => 'TLR',
			self::TheReck => 'TR',
			self::Tir => 'TIR',
			self::TirCounty => 'TC',
			self::UpperStretEastBank => 'USEB',
			self::VarmintWoods => 'VW',
			self::WailingWastes => 'WW',
			self::WartornValley => 'WV',
			self::HoloworldOmniTraining => 'HWO',
			self::ParkClanTraining => 'PCT',
			self::JunkyardNeutralTraining => 'JY',
			self::ICCShuttleport => 'ISP',
			self::UturnCanyon => 'UC',
			self::UturnForest => 'UF',
			self::ThreeCratersEast => '3CE',
			self::ThreeCratersWest => '3CW',
			self::Arete => 'ARETE',
			self::UnicornOutpost => 'APFHUB',
			self::AdonisCity => 'ADOCITY',
			self::AdonisAbyss => 'ADO',
			self::Elysium => 'ELY',
			self::ElysiumEast => 'ELYEAST',
			self::ElysiumNorth => 'ELYNORTH',
			self::ElysiumSouth => 'ELYSOUTH',
			self::ElysiumWest => 'ELYWEST',
			self::Inferno => 'INF',
			self::BurningMarshes => 'INFMARSHES',
			self::JobeHarbor => 'HARBOR',
			self::JobeMarket => 'MARKET',
			self::JobePlatform => 'PLATFORM',
			self::JobePlaza => 'PLAZA',
			self::JobeResearch => 'RESEARCH',
			self::NascenseFrontier => 'NASCFRONTIER',
			self::NascenseSwamp => 'NASCSWAMP',
			self::NascenseWilds => 'NASCWILDS',
			self::PenumbraForest => 'PENFOREST',
			self::PenumbraHollow => 'PENHOLLOW',
			self::PenumbraValley => 'PENVALLEY',
			self::PandemoniumAntenora => 'PANDEANTENORA',
			self::PandemoniumCaina => 'PANDECAINA',
			self::PandemoniumJudecca => 'PANDEJUDECCA',
			self::PandemoniumPtolemea => 'PANDEPTOLEMEA',
			self::ScheolUpper => 'SCHEOLUPPER',
			self::ScheolLower => 'SCHEOLLOWER',
			self::UnicornDefenceHub => 'UNICORNHUB',
			self::LoxHub => 'LOXHUB',
			self::SBCXpmSiteAlphaRomeo21 => 'MINES-LVL30',
			self::SBCXpmSiteAlphaRomeo22 => 'MINES-LVL60',
			self::SBCXpmSiteAlphaRomeo23 => 'MINES-LVL100',
			self::SBCXpmSiteAlphaRomeo24 => 'MINES-LVL150',
			self::SBCXpmSiteAlphaRomeo25 => 'MINES-LVL200',
			self::SBCXpmSiteAlphaRomeo26 => 'MINES-LVL214',
			self::SBCXpmSiteAlphaRomeo27 => 'MINES-LVL220',
		};
	}

	case FourHoles = 760;
	case Andromeda = 655;
	case Aegean = 585;
	case OldAthen = 540;
	case AthenShire = 550;
	case WestAthens = 545;
	case Avalon = 505;
	case BelialForest = 605;
	case Borealis = 800;
	case BrokenShores = 665;
	case CentralArteryValley = 590;
	case Clondyke = 670;
	case CoastOfPeace = 556;
	case CoastOfTranquility = 656;
	case DeepArteryValley = 595;
	case EasternFoulsPlain = 620;
	case GalwayCounty = 685;
	case GalwayShire = 687;
	case GreaterOmniForest = 717;
	case GreaterTirCounty = 647;
	case HolesInTheWall = 791;
	case LushFields = 695;
	case MilkyWay = 625;
	case Mort = 560;
	case MutantDomain = 696;
	case Newland = 567;
	case NewlandCity = 566;
	case NewlandDesert = 565;
	case OmniForest = 716;
	case OmniEnt = 705;
	case OmniHQ = 700;
	case OmniTrade = 710;
	case PerpetualWastelands = 570;
	case PleasantMeadows = 630;
	case RomeBlue = 735;
	case RomeGreen = 740;
	case RomeRed = 730;
	case SouthernArteryValley = 610;
	case SouthernFoulsHills = 615;
	case StretEastBank = 635;
	case StretWestBank = 790;
	case TheLongestRoad = 795;
	case TheReck = 750;
	case Tir = 640;
	case TirCounty = 646;
	case UpperStretEastBank = 650;
	case VarmintWoods = 600;
	case WailingWastes = 551;
	case WartornValley = 586;
	case HoloworldOmniTraining = 950;
	case ParkClanTraining = 952;
	case JunkyardNeutralTraining = 954;
	case ICCShuttleport = 4_582;
	case UturnCanyon = 6_550;
	case UturnForest = 6_551;
	case ThreeCratersEast = 6_102;
	case ThreeCratersWest = 6_101;
	case Arete = 6_553;
	case UnicornOutpost = 4_364;
	case AdonisCity = 4_872;
	case AdonisAbyss = 4_873;
	case Elysium = 4_542;
	case ElysiumEast = 4_543;
	case ElysiumNorth = 4_544;
	case ElysiumSouth = 4_540;
	case ElysiumWest = 4_541;
	case Inferno = 4_605;
	case BurningMarshes = 4_005;
	case JobeHarbor = 4_531;
	case JobeMarket = 4_532;
	case JobePlatform = 4_530;
	case JobePlaza = 4_533;
	case JobeResearch = 4_001;
	case NascenseFrontier = 4_310;
	case NascenseSwamp = 4_312;
	case NascenseWilds = 4_311;
	case PenumbraForest = 4_320;
	case PenumbraHollow = 4_322;
	case PenumbraValley = 4_321;
	case PandemoniumAntenora = 4_329;
	case PandemoniumCaina = 4_328;
	case PandemoniumJudecca = 4_331;
	case PandemoniumPtolemea = 4_330;
	case ScheolUpper = 4_880;
	case ScheolLower = 4_881;
	case UnicornDefenceHub = 6_007;
	case LoxHub = 6_013;
	case SBCXpmSiteAlphaRomeo21 = 6_300;
	case SBCXpmSiteAlphaRomeo22 = 6_301;
	case SBCXpmSiteAlphaRomeo23 = 6_302;
	case SBCXpmSiteAlphaRomeo24 = 6_303;
	case SBCXpmSiteAlphaRomeo25 = 6_304;
	case SBCXpmSiteAlphaRomeo26 = 6_305;
	case SBCXpmSiteAlphaRomeo27 = 6_306;
}
