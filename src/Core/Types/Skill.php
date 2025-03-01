<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use Nadybot\Core\Safe;
use ValueError;

/** This represents a valid skill in Anarchy Online */
enum Skill: int {
	/**
	 * Try to create an instance by its name, otherwise return null
	 *
	 * @param string $name           The name of the skill
	 * @param bool   $exactMatchOnly If set, don't try to guess the skill based on the beginning
	 *                               of the name, but only try exact matches
	 *
	 * @return null|self|list<self> If doing an exact match, the return value is either
	 *                              the skill or null, wildcard matches will always
	 *                              return an array of Skills
	 *
	 * @psalm-return null|self|non-empty-list<self>
	 *
	 * @throws ValueError on non-existing skill
	 */
	public static function tryFromName(string $name, bool $exactMatchOnly=true): null|self|array {
		try {
			return self::fromName($name, $exactMatchOnly);
		} catch (ValueError) {
			return null;
		}
	}

	/**
	 * Check if a negative value for this skill would be considered good,
	 * e.g. for the skill lock modifier, or the nano cost modifier
	 */
	public function negativeIsGood(): bool {
		return in_array($this, [self::AddNanoCost, self::SkillLockModifier], true);
	}

	/**
	 * Search for skills by skill names
	 *
	 * @param string $name The name of the skill to search for
	 *
	 * @throws ValueError on non-existing skill
	 *
	 * @return list<self> A list of skills that match
	 */
	public static function getMatching(string $name): array {
		if (ctype_digit($name)) {
			$matching = self::tryFrom((int)$name);
			if (isset($matching)) {
				return [$matching];
			}
		}
		$matching = self::tryFromName($name, false);
		if (!isset($matching)) {
			return [];
		}
		if (!is_array($matching)) {
			return [$matching];
		}
		return $matching;
	}

	/**
	 * Try to create an instance by its name
	 *
	 * @param string $name           The name of the skill
	 * @param bool   $exactMatchOnly If set, don't try to guess the skill based on the beginning
	 *                               of the name, but only try exact matches
	 *
	 * @return self|list<self> If doing an exact match, the return value is either
	 *                         the skill or a ValueError, wildcard matches will always
	 *                         return an array of Skills
	 *
	 * @psalm-return self|non-empty-list<self>
	 *
	 * @throws ValueError on non-existing skill
	 */
	public static function fromName(string $name, bool $exactMatchOnly=true): self|array {
		$name = strtolower($name);

		/** @var array<string,self> */
		$mapping = [
			'nano cost' => self::AddNanoCost,
			'percentage additional nano execution cost' => self::AddNanoCost,
			'add xp' => self::AddXP,
			'percentage additional experience' => self::AddXP,
			'1 handed blunt weapons' => self::OneHB,
			'1 hand blunt weapons' => self::OneHB,
			'1hb' => self::OneHB,
			'1 handed edged weapons' => self::OneHE,
			'1 hand edged weapons' => self::OneHE,
			'1he' => self::OneHE,
			'2 handed blunt weapons' => self::TwoHB,
			'2hb' => self::TwoHB,
			'2 handed edged weapons' => self::TwoHE,
			'2he' => self::TwoHE,
			'add all defense' => self::AAD,
			'added to all defencive rolls' => self::AAD,
			'added to all defensive rolls' => self::AAD,
			'aad' => self::AAD,
			'added to all offensive rolls' => self::AAO,
			'add all offense' => self::AAO,
			'aao' => self::AAO,
			'add chemical damage' => self::AddChemDmg,
			'added to chemical damage' => self::AddChemDmg,
			'add cold damage' => self::AddColdDmg,
			'added to cold damage' => self::AddColdDmg,
			'add energy damage' => self::AddEnergyDmg,
			'added to energy damage' => self::AddEnergyDmg,
			'add fire damage' => self::AddFireDmg,
			'added to fire damage' => self::AddFireDmg,
			'add melee damage' => self::AddMeleeDmg,
			'added to melee damage' => self::AddMeleeDmg,
			'add nano damage' => self::AddNanoDmg,
			'added to nano damage' => self::AddNanoDmg,
			'add poison damage' => self::AddPoisonDmg,
			'added to poison damage' => self::AddPoisonDmg,
			'add projectile damage' => self::AddProjDmg,
			'added to projectile damage' => self::AddProjDmg,
			'add radiation damage' => self::AddRadDmg,
			'added to radiation damage' => self::AddRadDmg,
			'adventuring' => self::Adventuring,
			'outdoor adventuring' => self::Adventuring,
			'aggdef' => self::AggDef,
			'aggressiveness' => self::Aggressiveness,
			'agility' => self::Agility,
			'agl' => self::Agility,
			'agi' => self::Agility,
			'aimed shot' => self::AimedShot,
			'as' => self::AimedShot,
			'assault rifle' => self::AssaultRifle,
			'assault rif' => self::AssaultRifle,
			'attack rating' => self::AttackRating,
			'biological metamorphosis' => self::BM,
			'bm' => self::BM,
			'body development' => self::BodyDev,
			'bodydev' => self::BodyDev,
			'bow' => self::Bow,
			'bow special attack' => self::BowSpcAtt,
			'brawling' => self::Brawling,
			'breaking and entry' => self::BreakAndAntry,
			'breaking and entering' => self::BreakAndAntry,
			'b&e' => self::BreakAndAntry,
			'burst' => self::Burst,
			'chemical ac' => self::ChemicalAC,
			'chemicalk armor-class' => self::ChemicalAC,
			'chemistry' => self::Chemistry,
			'cold ac' => self::ColdAC,
			'cold armor-class' => self::ColdAC,
			'computer literacy' => self::CL,
			'cl' => self::CL,
			'complit' => self::CL,
			'concealment' => self::Concealment,
			'critical decrease' => self::CriticalDecrease,
			'crticical increase' => self::CriticalIncrease,
			'damage to pet' => self::DmgToPet,
			'damage to pet damage multiplier' => self::DmgToPetMultiplier,
			'nano interrupt' => self::DecNanoInt,
			'deflect' => self::Deflect,
			'dimach' => self::Dimach,
			'dimach (soul attack)' => self::Dimach,
			'direct nano damage efficiency' => self::DirectNanoDmgEff,
			'disease ac' => self::DiseaseAC,
			'disease and poison armor-class' => self::DiseaseAC,
			'dodge ranged attacks' => self::DodgeRng,
			'duck explosives' => self::DuckExp,
			'duck explosives and thrown objects' => self::DuckExp,
			'electrical engineering' => self::ElecEngi,
			'energy ac' => self::EnergyAC,
			'energy attack armor-class' => self::EnergyAC,
			'evade close combat' => self::EvadeClsC,
			'evade close combat and martial art attacks' => self::EvadeClsC,
			'faction with guardian of shadow' => self::FactionGuardian,
			'fast attack' => self::FastAttack,
			'fire ac' => self::FireAC,
			'fire armor-class' => self::FireAC,
			'first aid' => self::FirstAid,
			'fling shot' => self::FlingShot,
			'free deck slot' => self::FreeDeckSlot,
			'full auto' => self::FullAuto,
			'grenade throwing' => self::Grenade,
			'grenade or lumping throwing' => self::Grenade,
			'heal reactivity' => self::HealReactivity,
			'heal delta' => self::HealDelta,
			'healing efficiency' => self::HealingEfficiency,
			'heavy weapons' => self::HeavyWeapons,
			'operate heavy weapons' => self::HeavyWeapons,
			'projectile ac' => self::ImpProjAC,
			'impact and projectile weapon armor-class' => self::ImpProjAC,
			'intelligence' => self::Intelligence,
			'int' => self::Intelligence,
			'invaders killed' => self::InvadersKilled,
			'ip' => self::IP,
			'map navigation' => self::MapNavig,
			'martial arts' => self::MA,
			'ma' => self::MA,
			'matter metamorphosis' => self::MM,
			'mm' => self::MM,
			'matter creation' => self::MC,
			'matter creations' => self::MC,
			'mc' => self::MC,
			'max health' => self::MaxHealth,
			'max nano' => self::MaxNano,
			'add max ncu' => self::MaxNCU,
			'maximum reflected chemical damage' => self::MaxReflectedChemicalDmg,
			'maximum reflected cold damage' => self::MaxReflectedColdDmg,
			'maximum reflected energy damage' => self::MaxReflectedEnergyDmg,
			'maximum reflected fire damage' => self::MaxReflectedFireDmg,
			'maximum reflected melee damage' => self::MaxReflectedMeleeDmg,
			'maximum reflected nano damage' => self::MaxReflectedNanoDmg,
			'maximum reflected poison damage' => self::MaxReflectedPoisonDmg,
			'maximum reflected projectile damage' => self::MaxReflectedProjectileDmg,
			'maximum reflected radiation damage' => self::MaxReflectedRadiationDmg,
			'mechanical engineering' => self::MechEngi,
			'me' => self::MechEngi,
			'melee energy weapons' => self::MeleeEner,
			'melee weapons initiative' => self::MeleeInit,
			'melee ac' => self::MeleeAC,
			'melee attacks and martial art armor-class' => self::MeleeAC,
			'mg/smg' => self::SMG,
			'machine guns and sub machine guns' => self::SMG,
			'multiple melee weapons' => self::MultiMelee,
			'multi melee' => self::MultiMelee,
			'multiple ranged weapons' => self::MultiRanged,
			'mr' => self::MultiRanged,
			'nano pool' => self::NanoPool,
			'nano energy pool' => self::NanoPool,
			'nano programming' => self::NanoProgramming,
			'nano-bot programming' => self::NanoProgramming,
			'nano resistance' => self::NanoResist,
			'nano initiative' => self::NanoInit,
			'nano execution init' => self::NanoInit,
			'nano delta' => self::NanoDelta,
			'perception' => self::Perception,
			'perception and spotting'=> self::Perception,
			'pharmaceuticals' => self::PharmaTech,
			'pharmacological technology' => self::PharmaTech,
			'physical initiative' => self::PhysicInit,
			'physical prowess and martial arts initiative' => self::PhysicInit,
			'piercing' => self::Piercing,
			'piercing weapons' => self::Piercing,
			'pistol' => self::Pistol,
			'psychic' => self::Psychic,
			'psy' => self::Psychic,
			'psychological modifications' => self::PM,
			'pm' => self::PM,
			'psychology' => self::Psychology,
			'pvp duel score' => self::PVPDuelScore,
			'quantum physics' => self::QuantumFT,
			'quantum force field technology' => self::QuantumFT,
			'qft' => self::QuantumFT,
			'radiation ac' => self::RadiationAC,
			'radiation armor-class' => self::RadiationAC,
			'ranged energy' => self::RangedEner,
			'ranged energy weapons' => self::RangedEner,
			'ranged initiative' => self::RangedInit,
			'ranged weapons initiative' => self::RangedInit,
			'add nano range' => self::RangeIncNF,
			'rangeincreasernf' => self::RangeIncNF,
			'add weapon range' => self::RangeIncWeapon,
			'rangeincreaserweapon' => self::RangeIncWeapon,
			'reflect chemical ac' => self::ReflectChemicalAC,
			'reflect cold ac' => self::ReflectColdAC,
			'reflect energy ac' => self::ReflectEnergyAC,
			'reflect fire ac' => self::ReflectFireAC,
			'reflect melee ac' => self::ReflectMeleeAC,
			'reflect nano ac' => self::ReflectNanoAC,
			'reflect poison ac' => self::ReflectPoisonAC,
			'reflect projectile ac' => self::ReflectProjectileAC,
			'reflect radiation ac' => self::ReflectRadiationAC,
			'regain xp' => self::RegainXP,
			'rifle' => self::Rifle,
			'rifle and sniper-rifle' => self::Rifle,
			'riposte' => self::Riposte,
			'run speed' => self::RunSpeed,
			'scale' => self::Scale,
			'sense' => self::Sense,
			'sen' => self::Sense,
			'sensory improvement' => self::SI,
			'sensory improvement and modification' => self::SI,
			'si' => self::SI,
			'shadow breed' => self::ShadowBreed,
			'sharp objects' => self::SharpObj,
			'knife or sharp object throwing' => self::SharpObj,
			'chemical damage shield' => self::ShieldChemicalAC,
			'cold damage shield' => self::ShieldColdAC,
			'energy damage shield' => self::ShieldEnergyAC,
			'fire damage shield' => self::ShieldFireAC,
			'melee damage shield' => self::ShieldMeleeAC,
			'nano damage shield' => self::ShieldNanoAC,
			'poison damage shield' => self::ShieldPoisonAC,
			'projectile damage shield' => self::ShieldProjectileAC,
			'radiation damage shield' => self::ShieldRadiationAC,
			'shotgun' => self::Shotgun,
			'side' => self::Side,
			'skill lock' => self::SkillLockModifier,
			'sneak attack' => self::SneakAttack,
			'stamina' => self::Stamina,
			'sta' => self::Stamina,
			'strength' => self::Strength,
			'str' => self::Strength,
			'swimming' => self::Swimming,
			'time and space' => self::TS,
			'time and space alteration' => self::TS,
			'ts' => self::TS,
			'trap disarming' => self::TrapDisarm,
			'trap disarmament' => self::TrapDisarm,
			'treatment' => self::Treatment,
			'tutoring' => self::Tutoring,
			'used ncu' => self::UsedNCU,
			'vehicle air' => self::VehicleAir,
			'vehicle navigation, airborne' => self::VehicleAir,
			'vehicle ground' => self::VehicleGround,
			'vehicle navigation, ground' => self::VehicleGround,
			'vehicle water' => self::VehicleWater,
			'vehicle navigation, water' => self::VehicleWater,
			'weapon smithing' => self::WeaponSmt,
			'xp' => self::XP,
			'% add. nano cost' => self::AddNanoCost,
			'% add. xp' => self::AddXP,
			'1h blunt' => self::OneHB,
			'1h edged' => self::OneHE,
			'2h blunt' => self::TwoHB,
			'2h edged' => self::TwoHE,
			'add all def.' => self::AAD,
			'add all off.' => self::AAO,
			'add. chem. dam.' => self::AddChemDmg,
			'add. cold dam.' => self::AddColdDmg,
			'add. energy dam.' => self::AddEnergyDmg,
			'add. fire dam.' => self::AddFireDmg,
			'add. melee dam.' => self::AddMeleeDmg,
			'add. nano dam.' => self::AddNanoDmg,
			'add. poison dam.' => self::AddPoisonDmg,
			'add. proj. dam.' => self::AddProjDmg,
			'add. rad. dam.' => self::AddRadDmg,
			'bio metamor' => self::BM,
			'body dev.' => self::BodyDev,
			'bow spc att' => self::BowSpcAtt,
			'break&entry' => self::BreakAndAntry,
			'comp. liter' => self::CL,
			'criticalincrease' => self::CriticalIncrease,
			'decreased nano-interrupt modifier %' => self::DecNanoInt,
			'dodge-rng' => self::DodgeRng,
			'duck-exp' => self::DuckExp,
			'elec. engi' => self::ElecEngi,
			'evade-clsc' => self::EvadeClsC,
			'grenade' => self::Grenade,
			'healdelta' => self::HealDelta,
			'imp/proj ac' => self::ImpProjAC,
			'invaderskilled' => self::InvadersKilled,
			'map navig.' => self::MapNavig,
			'matt.metam' => self::MM,
			'matter crea' => self::MC,
			'max ncu' => self::MaxNCU,
			'maxreflectedchemicaldmg' => self::MaxReflectedChemicalDmg,
			'maxreflectedcolddmg' => self::MaxReflectedColdDmg,
			'maxreflectedenergydmg' => self::MaxReflectedEnergyDmg,
			'maxreflectedfiredmg' => self::MaxReflectedFireDmg,
			'maxreflectedmeleedmg' => self::MaxReflectedMeleeDmg,
			'maxreflectednanodmg' => self::MaxReflectedNanoDmg,
			'maxreflectedpoisondmg' => self::MaxReflectedPoisonDmg,
			'maxreflectedprojectiledmg' => self::MaxReflectedProjectileDmg,
			'maxreflectedradiationdmg' => self::MaxReflectedRadiationDmg,
			'mech. engi' => self::MechEngi,
			'melee ener.' => self::MeleeEner,
			'melee. init.' => self::MeleeInit,
			'melee/ma ac' => self::MeleeAC,
			'mg / smg' => self::SMG,
			'mult. melee' => self::MultiMelee,
			'multi ranged' => self::MultiRanged,
			'nano progra' => self::NanoProgramming,
			'nano resist' => self::NanoResist,
			'nanoc. init.' => self::NanoInit,
			'nanodelta' => self::NanoDelta,
			'pharma tech' => self::PharmaTech,
			'physic. init' => self::PhysicInit,
			'psycho modi' => self::PM,
			'pvpduelscore' => self::PVPDuelScore,
			'quantum ft' => self::QuantumFT,
			'ranged ener' => self::RangedEner,
			'ranged. init.' => self::RangedInit,
			'rangeinc. nf' => self::RangeIncNF,
			'rangeinc. weapon' => self::RangeIncWeapon,
			'reflectchemicalac' => self::ReflectChemicalAC,
			'reflectcoldac' => self::ReflectColdAC,
			'reflectenergyac' => self::ReflectEnergyAC,
			'reflectfireac' => self::ReflectFireAC,
			'reflectmeleeac' => self::ReflectMeleeAC,
			'reflectnanoac' => self::ReflectNanoAC,
			'reflectpoisonac' => self::ReflectPoisonAC,
			'reflectprojectileac' => self::ReflectProjectileAC,
			'reflectradiationac' => self::ReflectRadiationAC,
			'regain xp percentage' => self::RegainXP,
			'sensory impr' => self::SI,
			'shadowbreed' => self::ShadowBreed,
			'sharp obj' => self::SharpObj,
			'shieldchemicalac' => self::ShieldChemicalAC,
			'shieldcoldac' => self::ShieldColdAC,
			'shieldenergyac' => self::ShieldEnergyAC,
			'shieldfireac' => self::ShieldFireAC,
			'shieldmeleeac' => self::ShieldMeleeAC,
			'shieldnanoac' => self::ShieldNanoAC,
			'shieldpoisonac' => self::ShieldPoisonAC,
			'shieldprojectileac' => self::ShieldProjectileAC,
			'shieldradiationac' => self::ShieldRadiationAC,
			'skilllockmodifier' => self::SkillLockModifier,
			'sneak atck' => self::SneakAttack,
			'time&space' => self::TS,
			'trap disarm.' => self::TrapDisarm,
			'weapon smt' => self::WeaponSmt,
		];
		$exactMatch = $mapping[$name] ?? null;
		if (isset($exactMatch)) {
			return $exactMatch;
		}
		if ($exactMatchOnly) {
			throw new ValueError("Unknown skill \"{$name}\"");
		}

		/** @var list<self> */
		$result = [];
		$search = Safe::pregSplit('/\s+/', $name);
		foreach ($mapping as $key => $value) {
			if (!self::matchesSearch($search, $key)) {
				continue;
			} elseif (!in_array($value, $result, true)) {
				$result []= $value;
			}
		}
		if (count($result) === 0) {
			throw new ValueError("Unknown skill \"{$name}\"");
		}
		return count($result) === 1 ? $result[0] : $result;
	}

	/** Get the unit for this skill (% or empty string) */
	public function unit(): string {
		/** @psalm-suppress UnhandledMatchCondition */
		return match ($this) {
			self::AddNanoCost => '%',
			self::AddXP => '%',
			self::OneHB => '',
			self::OneHE => '',
			self::TwoHB => '',
			self::TwoHE => '',
			self::AAD => '',
			self::AAO => '',
			self::AddChemDmg => '',
			self::AddColdDmg => '',
			self::AddEnergyDmg => '',
			self::AddFireDmg => '',
			self::AddMeleeDmg => '',
			self::AddNanoDmg => '',
			self::AddPoisonDmg => '',
			self::AddProjDmg => '',
			self::AddRadDmg => '',
			self::Adventuring => '',
			self::AggDef => '',
			self::Aggressiveness => '',
			self::Agility => '',
			self::AimedShot => '',
			self::AssaultRifle => '',
			self::AttackRating => '',
			self::BM => '',
			self::BodyDev => '',
			self::Bow => '',
			self::BowSpcAtt => '',
			self::Brawling => '',
			self::BreakAndAntry => '',
			self::Burst => '',
			self::ChemicalAC => '',
			self::Chemistry => '',
			self::ColdAC => '',
			self::CL => '',
			self::Concealment => '',
			self::CriticalDecrease => '%',
			self::CriticalIncrease => '%',
			self::DmgToPet => '',
			self::DmgToPetMultiplier => '',
			self::DecNanoInt => '%',
			self::Deflect => '',
			self::Dimach => '',
			self::DirectNanoDmgEff => '%',
			self::DiseaseAC => '',
			self::DodgeRng => '',
			self::DuckExp => '',
			self::ElecEngi => '',
			self::EnergyAC => '',
			self::EvadeClsC => '',
			self::FactionGuardian => '',
			self::FastAttack => '',
			self::FireAC => '',
			self::FirstAid => '',
			self::FlingShot => '',
			self::FreeDeckSlot => '',
			self::FullAuto => '',
			self::Grenade => '',
			self::HealReactivity => '%',
			self::HealDelta => '',
			self::HealingEfficiency => '%',
			self::HeavyWeapons => '',
			self::ImpProjAC => '',
			self::Intelligence => '',
			self::InvadersKilled => '',
			self::IP => '',
			self::MapNavig => '',
			self::MA => '',
			self::MM => '',
			self::MC => '',
			self::MaxHealth => '',
			self::MaxNano => '',
			self::MaxNCU => '',
			self::MaxReflectedChemicalDmg => '',
			self::MaxReflectedColdDmg => '',
			self::MaxReflectedEnergyDmg => '',
			self::MaxReflectedFireDmg => '',
			self::MaxReflectedMeleeDmg => '',
			self::MaxReflectedNanoDmg => '',
			self::MaxReflectedPoisonDmg => '',
			self::MaxReflectedProjectileDmg => '',
			self::MaxReflectedRadiationDmg => '',
			self::MechEngi => '',
			self::MeleeEner => '',
			self::MeleeInit => '',
			self::MeleeAC => '',
			self::SMG => '',
			self::MultiMelee => '',
			self::MultiRanged => '',
			self::NanoPool => '',
			self::NanoProgramming => '',
			self::NanoResist => '',
			self::NanoInit => '',
			self::NanoDelta => '',
			self::Perception => '',
			self::PharmaTech => '',
			self::PhysicInit => '',
			self::Piercing => '',
			self::Pistol => '',
			self::Psychic => '',
			self::PM => '',
			self::Psychology => '',
			self::PVPDuelScore => '',
			self::QuantumFT => '',
			self::RadiationAC => '',
			self::RangedEner => '',
			self::RangedInit => '',
			self::RangeIncNF => '%',
			self::RangeIncWeapon => '%',
			self::ReflectChemicalAC => '%',
			self::ReflectColdAC => '%',
			self::ReflectEnergyAC => '%',
			self::ReflectFireAC => '%',
			self::ReflectMeleeAC => '%',
			self::ReflectNanoAC => '%',
			self::ReflectPoisonAC => '%',
			self::ReflectProjectileAC => '%',
			self::ReflectRadiationAC => '%',
			self::RegainXP => '%',
			self::Rifle => '',
			self::Riposte => '',
			self::RunSpeed => '',
			self::Scale => '',
			self::Sense => '',
			self::SI => '',
			self::ShadowBreed => '',
			self::SharpObj => '',
			self::ShieldChemicalAC => '',
			self::ShieldColdAC => '',
			self::ShieldEnergyAC => '',
			self::ShieldFireAC => '',
			self::ShieldMeleeAC => '',
			self::ShieldNanoAC => '',
			self::ShieldPoisonAC => '',
			self::ShieldProjectileAC => '',
			self::ShieldRadiationAC => '',
			self::Shotgun => '',
			self::Side => '',
			self::SkillLockModifier => '%',
			self::SneakAttack => '',
			self::Stamina => '',
			self::Strength => '',
			self::Swimming => '',
			self::TS => '',
			self::TrapDisarm => '',
			self::Treatment => '',
			self::Tutoring => '',
			self::UsedNCU => '',
			self::VehicleAir => '',
			self::VehicleGround => '',
			self::VehicleWater => '',
			self::WeaponSmt => '',
			self::XP => '',
		};
	}

	/** Get the full name of the skill */
	public function fullName(): string {
		/** @psalm-suppress UnhandledMatchCondition */
		return match ($this) {
			self::AddNanoCost => 'Nano Cost',
			self::AddXP => 'Add XP',
			self::OneHB => '1 Handed Blunt Weapons',
			self::OneHE => '1 Handed Edged Weapons',
			self::TwoHB => '2 Handed Blunt Weapons',
			self::TwoHE => '2 Handed Edged Weapons',
			self::AAD => 'Add All Defense',
			self::AAO => 'Add All Offense',
			self::AddChemDmg => 'Add Chemical Damage',
			self::AddColdDmg => 'Add Cold Damage',
			self::AddEnergyDmg => 'Add Energy Damage',
			self::AddFireDmg => 'Add Fire Damage',
			self::AddMeleeDmg => 'Add Melee Damage',
			self::AddNanoDmg => 'Add Nano Damage',
			self::AddPoisonDmg => 'Add Poison Damage',
			self::AddProjDmg => 'Add Projectile Damage',
			self::AddRadDmg => 'Add Radiation Damage',
			self::Adventuring => 'Adventuring',
			self::AggDef => 'Aggdef',
			self::Aggressiveness => 'Aggressiveness',
			self::Agility => 'Agility',
			self::AimedShot => 'Aimed Shot',
			self::AssaultRifle => 'Assault Rifle',
			self::AttackRating => 'Attack Rating',
			self::BM => 'Biological Metamorphosis',
			self::BodyDev => 'Body Development',
			self::Bow => 'Bow',
			self::BowSpcAtt => 'Bow Special Attack',
			self::Brawling => 'Brawling',
			self::BreakAndAntry => 'Breaking and Entry',
			self::Burst => 'Burst',
			self::ChemicalAC => 'Chemical AC',
			self::Chemistry => 'Chemistry',
			self::ColdAC => 'Cold AC',
			self::CL => 'Computer Literacy',
			self::Concealment => 'Concealment',
			self::CriticalDecrease => 'Critical Decrease',
			self::CriticalIncrease => 'Crticical Increase',
			self::DmgToPet => 'Damage to Pet',
			self::DmgToPetMultiplier => 'Damage To Pet Damage Multiplier',
			self::DecNanoInt => 'Nano Interrupt',
			self::Deflect => 'Deflect',
			self::Dimach => 'Dimach',
			self::DirectNanoDmgEff => 'Direct Nano Damage Efficiency',
			self::DiseaseAC => 'Disease AC',
			self::DodgeRng => 'Dodge Ranged Attacks',
			self::DuckExp => 'Duck Explosives',
			self::ElecEngi => 'Electrical Engineering',
			self::EnergyAC => 'Energy AC',
			self::EvadeClsC => 'Evade Close Combat',
			self::FactionGuardian => 'Faction with Guardian of Shadow',
			self::FastAttack => 'Fast Attack',
			self::FireAC => 'Fire AC',
			self::FirstAid => 'First Aid',
			self::FlingShot => 'Fling Shot',
			self::FreeDeckSlot => 'Free deck slot',
			self::FullAuto => 'Full Auto',
			self::Grenade => 'Grenade Throwing',
			self::HealReactivity => 'Heal Reactivity',
			self::HealDelta => 'Heal Delta',
			self::HealingEfficiency => 'Healing Efficiency',
			self::HeavyWeapons => 'Heavy Weapons',
			self::ImpProjAC => 'Projectile AC',
			self::Intelligence => 'Intelligence',
			self::InvadersKilled => 'Invaders Killed',
			self::IP => 'IP',
			self::MapNavig => 'Map Navigation',
			self::MA => 'Martial Arts',
			self::MM => 'Matter Metamorphosis',
			self::MC => 'Matter Creation',
			self::MaxHealth => 'Max Health',
			self::MaxNano => 'Max Nano',
			self::MaxNCU => 'Add Max NCU',
			self::MaxReflectedChemicalDmg => 'Maximum Reflected Chemical Damage',
			self::MaxReflectedColdDmg => 'Maximum Reflected Cold Damage',
			self::MaxReflectedEnergyDmg => 'Maximum Reflected Energy Damage',
			self::MaxReflectedFireDmg => 'Maximum Reflected Fire Damage',
			self::MaxReflectedMeleeDmg => 'Maximum Reflected Melee Damage',
			self::MaxReflectedNanoDmg => 'Maximum Reflected Nano Damage',
			self::MaxReflectedPoisonDmg => 'Maximum Reflected Poison Damage',
			self::MaxReflectedProjectileDmg => 'Maximum Reflected Projectile Damage',
			self::MaxReflectedRadiationDmg => 'Maximum Reflected Radiation Damage',
			self::MechEngi => 'Mechanical Engineering',
			self::MeleeEner => 'Melee Energy Weapons',
			self::MeleeInit => 'Melee Weapons Initiative',
			self::MeleeAC => 'Melee AC',
			self::SMG => 'MG/SMG',
			self::MultiMelee => 'Multiple Melee Weapons',
			self::MultiRanged => 'Multiple Ranged Weapons',
			self::NanoPool => 'Nano Pool',
			self::NanoProgramming => 'Nano Programming',
			self::NanoResist => 'Nano Resistance',
			self::NanoInit => 'Nano Initiative',
			self::NanoDelta => 'Nano Delta',
			self::Perception => 'Perception',
			self::PharmaTech => 'Pharmaceuticals',
			self::PhysicInit => 'Physical Initiative',
			self::Piercing => 'Piercing',
			self::Pistol => 'Pistol',
			self::Psychic => 'Psychic',
			self::PM => 'Psychological Modifications',
			self::Psychology => 'Psychology',
			self::PVPDuelScore => 'PVP Duel Score',
			self::QuantumFT => 'Quantum Physics',
			self::RadiationAC => 'Radiation AC',
			self::RangedEner => 'Ranged Energy',
			self::RangedInit => 'Ranged Initiative',
			self::RangeIncNF => 'Add Nano Range',
			self::RangeIncWeapon => 'Add Weapon Range',
			self::ReflectChemicalAC => 'Reflect Chemical AC',
			self::ReflectColdAC => 'Reflect Cold AC',
			self::ReflectEnergyAC => 'Reflect Energy AC',
			self::ReflectFireAC => 'Reflect Fire AC',
			self::ReflectMeleeAC => 'Reflect Melee AC',
			self::ReflectNanoAC => 'Reflect Nano AC',
			self::ReflectPoisonAC => 'Reflect Poison AC',
			self::ReflectProjectileAC => 'Reflect Projectile AC',
			self::ReflectRadiationAC => 'Reflect Radiation AC',
			self::RegainXP => 'Regain XP',
			self::Rifle => 'Rifle',
			self::Riposte => 'Riposte',
			self::RunSpeed => 'Run Speed',
			self::Scale => 'Scale',
			self::Sense => 'Sense',
			self::SI => 'Sensory Improvement',
			self::ShadowBreed => 'Shadow Breed',
			self::SharpObj => 'Sharp Objects',
			self::ShieldChemicalAC => 'Chemical Damage Shield',
			self::ShieldColdAC => 'Cold Damage Shield',
			self::ShieldEnergyAC => 'Energy Damage Shield',
			self::ShieldFireAC => 'Fire Damage Shield',
			self::ShieldMeleeAC => 'Melee Damage Shield',
			self::ShieldNanoAC => 'Nano Damage Shield',
			self::ShieldPoisonAC => 'Poison Damage Shield',
			self::ShieldProjectileAC => 'Projectile Damage Shield',
			self::ShieldRadiationAC => 'Radiation Damage Shield',
			self::Shotgun => 'Shotgun',
			self::Side => 'Side',
			self::SkillLockModifier => 'Skill Lock',
			self::SneakAttack => 'Sneak Attack',
			self::Stamina => 'Stamina',
			self::Strength => 'Strength',
			self::Swimming => 'Swimming',
			self::TS => 'Time and Space',
			self::TrapDisarm => 'Trap Disarming',
			self::Treatment => 'Treatment',
			self::Tutoring => 'Tutoring',
			self::UsedNCU => 'Used NCU',
			self::VehicleAir => 'Vehicle Air',
			self::VehicleGround => 'Vehicle Ground',
			self::VehicleWater => 'Vehicle Water',
			self::WeaponSmt => 'Weapon Smithing',
			self::XP => 'XP',
		};
	}

	/** Get the name by which the skill is shown in the game */
	public function inGame(): string {
		/** @psalm-suppress UnhandledMatchCondition */
		return match ($this) {
			self::AddNanoCost => '% Add. Nano Cost',
			self::AddXP => '% Add. Xp',
			self::OneHB => '1h Blunt',
			self::OneHE => '1h Edged',
			self::TwoHB => '2h Blunt',
			self::TwoHE => '2h Edged',
			self::AAD => 'Add All Def.',
			self::AAO => 'Add All Off.',
			self::AddChemDmg => 'Add. Chem. Dam.',
			self::AddColdDmg => 'Add. Cold Dam.',
			self::AddEnergyDmg => 'Add. Energy Dam.',
			self::AddFireDmg => 'Add. Fire Dam.',
			self::AddMeleeDmg => 'Add. Melee Dam.',
			self::AddNanoDmg => 'Add. Nano Dam.',
			self::AddPoisonDmg => 'Add. Poison Dam.',
			self::AddProjDmg => 'Add. Proj. Dam.',
			self::AddRadDmg => 'Add. Rad. Dam.',
			self::Adventuring => 'Adventuring',
			self::AggDef => 'Aggdef',
			self::Aggressiveness => 'Aggressiveness',
			self::Agility => 'Agility',
			self::AimedShot => 'Aimed Shot',
			self::AssaultRifle => 'Assault Rifle',
			self::AttackRating => 'Attack rating',
			self::BM => 'Bio Metamor',
			self::BodyDev => 'Body Dev.',
			self::Bow => 'Bow',
			self::BowSpcAtt => 'Bow Spc Att',
			self::Brawling => 'Brawling',
			self::BreakAndAntry => 'Break&Entry',
			self::Burst => 'Burst',
			self::ChemicalAC => 'Chemical AC',
			self::Chemistry => 'Chemistry',
			self::ColdAC => 'Cold AC',
			self::CL => 'Comp. Liter',
			self::Concealment => 'Concealment',
			self::CriticalDecrease => 'Critical Decrease',
			self::CriticalIncrease => 'CriticalIncrease',
			self::DmgToPet => 'Damage to Pet',
			self::DmgToPetMultiplier => 'Damage To Pet Damage Multiplier',
			self::DecNanoInt => 'Decreased Nano-Interrupt Modifier %',
			self::Deflect => 'Deflect',
			self::Dimach => 'Dimach',
			self::DirectNanoDmgEff => 'Direct Nano Damage Efficiency',
			self::DiseaseAC => 'Disease AC',
			self::DodgeRng => 'Dodge-Rng',
			self::DuckExp => 'Duck-Exp',
			self::ElecEngi => 'Elec. Engi',
			self::EnergyAC => 'Energy AC',
			self::EvadeClsC => 'Evade-ClsC',
			self::FactionGuardian => 'Faction with Guardian of Shadow',
			self::FastAttack => 'Fast Attack',
			self::FireAC => 'Fire AC',
			self::FirstAid => 'First Aid',
			self::FlingShot => 'Fling Shot',
			self::FreeDeckSlot => 'Free deck slot',
			self::FullAuto => 'Full Auto',
			self::Grenade => 'Grenade',
			self::HealReactivity => 'Heal Reactivity',
			self::HealDelta => 'HealDelta',
			self::HealingEfficiency => 'Healing Efficiency',
			self::HeavyWeapons => 'Heavy Weapons',
			self::ImpProjAC => 'Imp/Proj AC',
			self::Intelligence => 'Intelligence',
			self::InvadersKilled => 'InvadersKilled',
			self::IP => 'IP',
			self::MapNavig => 'Map Navig.',
			self::MA => 'Martial Arts',
			self::MM => 'Matt.Metam',
			self::MC => 'Matter Crea',
			self::MaxHealth => 'Max Health',
			self::MaxNano => 'Max Nano',
			self::MaxNCU => 'Max NCU',
			self::MaxReflectedChemicalDmg => 'MaxReflectedChemicalDmg',
			self::MaxReflectedColdDmg => 'MaxReflectedColdDmg',
			self::MaxReflectedEnergyDmg => 'MaxReflectedEnergyDmg',
			self::MaxReflectedFireDmg => 'MaxReflectedFireDmg',
			self::MaxReflectedMeleeDmg => 'MaxReflectedMeleeDmg',
			self::MaxReflectedNanoDmg => 'MaxReflectedNanoDmg',
			self::MaxReflectedPoisonDmg => 'MaxReflectedPoisonDmg',
			self::MaxReflectedProjectileDmg => 'MaxReflectedProjectileDmg',
			self::MaxReflectedRadiationDmg => 'MaxReflectedRadiationDmg',
			self::MechEngi => 'Mech. Engi',
			self::MeleeEner => 'Melee Ener.',
			self::MeleeInit => 'Melee. Init.',
			self::MeleeAC => 'Melee/ma AC',
			self::SMG => 'MG / SMG',
			self::MultiMelee => 'Mult. Melee',
			self::MultiRanged => 'Multi Ranged',
			self::NanoPool => 'Nano Pool',
			self::NanoProgramming => 'Nano Progra',
			self::NanoResist => 'Nano Resist',
			self::NanoInit => 'NanoC. Init.',
			self::NanoDelta => 'NanoDelta',
			self::Perception => 'Perception',
			self::PharmaTech => 'Pharma Tech',
			self::PhysicInit => 'Physic. Init',
			self::Piercing => 'Piercing',
			self::Pistol => 'Pistol',
			self::Psychic => 'Psychic',
			self::PM => 'Psycho Modi',
			self::Psychology => 'Psychology',
			self::PVPDuelScore => 'PVPDuelScore',
			self::QuantumFT => 'Quantum FT',
			self::RadiationAC => 'Radiation AC',
			self::RangedEner => 'Ranged Ener',
			self::RangedInit => 'Ranged. Init.',
			self::RangeIncNF => 'RangeInc. NF',
			self::RangeIncWeapon => 'RangeInc. Weapon',
			self::ReflectChemicalAC => 'ReflectChemicalAC',
			self::ReflectColdAC => 'ReflectColdAC',
			self::ReflectEnergyAC => 'ReflectEnergyAC',
			self::ReflectFireAC => 'ReflectFireAC',
			self::ReflectMeleeAC => 'ReflectMeleeAC',
			self::ReflectNanoAC => 'ReflectNanoAC',
			self::ReflectPoisonAC => 'ReflectPoisonAC',
			self::ReflectProjectileAC => 'ReflectProjectileAC',
			self::ReflectRadiationAC => 'ReflectRadiationAC',
			self::RegainXP => 'Regain XP Percentage',
			self::Rifle => 'Rifle',
			self::Riposte => 'Riposte',
			self::RunSpeed => 'Run Speed',
			self::Scale => 'Scale',
			self::Sense => 'Sense',
			self::SI => 'Sensory Impr',
			self::ShadowBreed => 'ShadowBreed',
			self::SharpObj => 'Sharp Obj',
			self::ShieldChemicalAC => 'ShieldChemicalAC',
			self::ShieldColdAC => 'ShieldColdAC',
			self::ShieldEnergyAC => 'ShieldEnergyAC',
			self::ShieldFireAC => 'ShieldFireAC',
			self::ShieldMeleeAC => 'ShieldMeleeAC',
			self::ShieldNanoAC => 'ShieldNanoAC',
			self::ShieldPoisonAC => 'ShieldPoisonAC',
			self::ShieldProjectileAC => 'ShieldProjectileAC',
			self::ShieldRadiationAC => 'ShieldRadiationAC',
			self::Shotgun => 'Shotgun',
			self::Side => 'Side',
			self::SkillLockModifier => 'SkillLockModifier',
			self::SneakAttack => 'Sneak Atck',
			self::Stamina => 'Stamina',
			self::Strength => 'Strength',
			self::Swimming => 'Swimming',
			self::TS => 'Time&Space',
			self::TrapDisarm => 'Trap Disarm.',
			self::Treatment => 'Treatment',
			self::Tutoring => 'Tutoring',
			self::UsedNCU => 'Used NCU',
			self::VehicleAir => 'Vehicle Air',
			self::VehicleGround => 'Vehicle Ground',
			self::VehicleWater => 'Vehicle Water',
			self::WeaponSmt => 'Weapon Smt',
			self::XP => 'XP',
		};
	}

	/**
	 * Check if $term is contained in any of the strings in $search
	 *
	 * @param list<string> $search
	 */
	private static function matchesSearch(array $search, string $term): bool {
		foreach ($search as $token) {
			if (!str_contains($term, $token)) {
				return false;
			}
		}
		return true;
	}

	case AddNanoCost = 318;
	case AddXP = 319;
	case OneHB = 102;
	case OneHE = 103;
	case TwoHB = 107;
	case TwoHE = 105;
	case AAD = 277;
	case AAO = 276;
	case AddChemDmg = 281;
	case AddColdDmg = 311;
	case AddEnergyDmg = 280;
	case AddFireDmg = 316;
	case AddMeleeDmg = 279;
	case AddNanoDmg = 315;
	case AddPoisonDmg = 317;
	case AddProjDmg = 278;
	case AddRadDmg = 282;
	case Adventuring = 137;
	case AggDef = 51;
	case Aggressiveness = 201;
	case Agility = 17;
	case AimedShot = 151;
	case AssaultRifle = 116;
	case AttackRating = 22;
	case BM = 128;
	case BodyDev = 152;
	case Bow = 111;
	case BowSpcAtt = 121;
	case Brawling = 142;
	case BreakAndAntry = 165;
	case Burst = 148;
	case ChemicalAC = 93;
	case Chemistry = 163;
	case ColdAC = 95;
	case CL = 161;
	case Concealment = 164;
	case CriticalDecrease = 391;
	case CriticalIncrease = 379;
	case DmgToPet = 35;
	case DmgToPetMultiplier = 39;
	case DecNanoInt = 383;
	case Deflect = 145;
	case Dimach = 144;
	case DirectNanoDmgEff = 536;
	case DiseaseAC = 96;
	case DodgeRng = 154;
	case DuckExp = 153;
	case ElecEngi = 126;
	case EnergyAC = 92;
	case EvadeClsC = 155;
	case FactionGuardian = 566;
	case FastAttack = 147;
	case FireAC = 97;
	case FirstAid = 123;
	case FlingShot = 150;
	case FreeDeckSlot = 45;
	case FullAuto = 167;
	case Grenade = 109;
	case HealReactivity = 689;
	case HealDelta = 343;
	case HealingEfficiency = 535;
	case HeavyWeapons = 110;
	case ImpProjAC = 90;
	case Intelligence = 19;
	case InvadersKilled = 615;
	case IP = 53;
	case MapNavig = 140;
	case MA = 100;
	case MM = 127;
	case MC = 130;
	case MaxHealth = 1;
	case MaxNano = 221;
	case MaxNCU = 181;
	case MaxReflectedChemicalDmg = 478;
	case MaxReflectedColdDmg = 480;
	case MaxReflectedEnergyDmg = 477;
	case MaxReflectedFireDmg = 482;
	case MaxReflectedMeleeDmg = 476;
	case MaxReflectedNanoDmg = 481;
	case MaxReflectedPoisonDmg = 483;
	case MaxReflectedProjectileDmg = 475;
	case MaxReflectedRadiationDmg = 479;
	case MechEngi = 125;
	case MeleeEner = 104;
	case MeleeInit = 118;
	case MeleeAC = 91;
	case SMG = 114;
	case MultiMelee = 101;
	case MultiRanged = 134;
	case NanoPool = 132;
	case NanoProgramming = 160;
	case NanoResist = 168;
	case NanoInit = 149;
	case NanoDelta = 364;
	case Perception = 136;
	case PharmaTech = 159;
	case PhysicInit = 120;
	case Piercing = 106;
	case Pistol = 112;
	case Psychic = 21;
	case PM = 129;
	case Psychology = 162;
	case PVPDuelScore = 684;
	case QuantumFT = 157;
	case RadiationAC = 94;
	case RangedEner = 133;
	case RangedInit = 119;
	case RangeIncNF = 381;
	case RangeIncWeapon = 380;
	case ReflectChemicalAC = 208;
	case ReflectColdAC = 217;
	case ReflectEnergyAC = 207;
	case ReflectFireAC = 219;
	case ReflectMeleeAC = 206;
	case ReflectNanoAC = 218;
	case ReflectPoisonAC = 225;
	case ReflectProjectileAC = 205;
	case ReflectRadiationAC = 216;
	case RegainXP = 593;
	case Rifle = 113;
	case Riposte = 143;
	case RunSpeed = 156;
	case Scale = 360;
	case Sense = 20;
	case SI = 122;
	case ShadowBreed = 532;
	case SharpObj = 108;
	case ShieldChemicalAC = 229;
	case ShieldColdAC = 231;
	case ShieldEnergyAC = 228;
	case ShieldFireAC = 233;
	case ShieldMeleeAC = 227;
	case ShieldNanoAC = 232;
	case ShieldPoisonAC = 234;
	case ShieldProjectileAC = 226;
	case ShieldRadiationAC = 230;
	case Shotgun = 115;
	case Side = 33;
	case SkillLockModifier = 382;
	case SneakAttack = 146;
	case Stamina = 18;
	case Strength = 16;
	case Swimming = 138;
	case TS = 131;
	case TrapDisarm = 135;
	case Treatment = 124;
	case Tutoring = 141;
	case UsedNCU = 180;
	case VehicleAir = 139;
	case VehicleGround = 166;
	case VehicleWater = 117;
	case WeaponSmt = 158;
	case XP = 52;
}
