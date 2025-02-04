<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

enum Skill: string {
	case AddNanoCost = '% Add. Nano Cost';
	case AddXP = '% Add. Xp';
	case OneHB = '1h Blunt';
	case OneHE = '1h Edged';
	case TwoHB = '2h Blunt';
	case TwoHE = '2h Edged';
	case AAD = 'Add All Def.';
	case AAO = 'Add All Off.';
	case AddChemDmg = 'Add. Chem. Dam.';
	case AddColdDmg = 'Add. Cold Dam.';
	case AddEnergyDmg = 'Add. Energy Dam.';
	case AddFireDmg = 'Add. Fire Dam.';
	case AddMeleeDmg = 'Add. Melee Dam.';
	case AddNanoDmg = 'Add. Nano Dam.';
	case AddPoisonDmg = 'Add. Poison Dam.';
	case AddProjDmg = 'Add. Proj. Dam.';
	case AddRadDmg = 'Add. Rad. Dam.';
	case Adventuring = 'Adventuring';
	case AggDef = 'Aggdef';
	case Aggressiveness = 'Aggressiveness';
	case Agility = 'Agility';
	case AimedShot = 'Aimed Shot';
	case AssaultRifle = 'Assault Rifle';
	case AttackRating = 'Attack rating';
	case BM = 'Bio Metamor';
	case BodyDev = 'Body Dev.';
	case Bow = 'Bow';
	case BowSpcAtt = 'Bow Spc Att';
	case Brawling = 'Brawling';
	case BreakAndAntry = 'Break&Entry';
	case Burst = 'Burst';
	case ChemicalAC = 'Chemical AC';
	case Chemistry = 'Chemistry';
	case ColdAC = 'Cold AC';
	case CL = 'Comp. Liter';
	case Concealment = 'Concealment';
	case CriticalDecrease = 'Critical Decrease';
	case CriticalIncrease = 'CriticalIncrease';
	case DmgToPet = 'Damage to Pet';
	case DmgToPetMultiplier = 'Damage To Pet Damage Multiplier';
	case DecNanoInt = 'Decreased Nano-Interrupt Modifier %';
	case Deflect = 'Deflect';
	case Dimach = 'Dimach';
	case DirectNanoDmgEff = 'Direct Nano Damage Efficiency';
	case DiseaseAC = 'Disease AC';
	case DodgeRng = 'Dodge-Rng';
	case DuckExp = 'Duck-Exp';
	case ElecEngi = 'Elec. Engi';
	case EnergyAC = 'Energy AC';
	case EvadeClsC = 'Evade-ClsC';
	case FactionGuardian = 'Faction with Guardian of Shadow';
	case FastAttack = 'Fast Attack';
	case FireAC = 'Fire AC';
	case FirstAif = 'First Aid';
	case FlingShot = 'Fling Shot';
	case FreeDeckSlot = 'Free deck slot';
	case FullAuto = 'Full Auto';
	case Grenade = 'Grenade';
	case HealReactivity = 'Heal Reactivity';
	case HealDelta = 'HealDelta';
	case HealingEfficiency = 'Healing Efficiency';
	case HeavyWeapons = 'Heavy Weapons';
	case ImpProjAC = 'Imp/Proj AC';
	case Intelligence = 'Intelligence';
	case InvadersKiller = 'InvadersKilled';
	case IP = 'IP';
	case MapNavig = 'Map Navig.';
/*
"Martial Arts",""
Matt.Metam,""
"Matter Crea",""
"Max Health",""
"Max Nano",""
"Max NCU",""
MaxReflectedChemicalDmg,""
MaxReflectedColdDmg,""
MaxReflectedEnergyDmg,""
MaxReflectedFireDmg,""
MaxReflectedMeleeDmg,""
MaxReflectedNanoDmg,""
MaxReflectedPoisonDmg,""
MaxReflectedProjectileDmg,""
MaxReflectedRadiationDmg,""
"Mech. Engi",""
"Melee Ener.",""
"Melee. Init.",""
"Melee/ma AC",""
"MG / SMG",""
"Mult. Melee",""
"Multi Ranged",""
"Nano Pool",""
"Nano Progra",""
"Nano Resist",""
"NanoC. Init.",""
NanoDelta,""
Perception,""
"Pharma Tech",""
"Physic. Init",""
Piercing,""
Pistol,""
Psychic,""
"Psycho Modi",""
Psychology,""
PVPDuelScore,""
"Quantum FT",""
"Radiation AC",""
"Ranged Ener",""
"Ranged. Init.",""
"RangeInc. NF",%
"RangeInc. Weapon",%
ReflectChemicalAC,%
ReflectColdAC,%
ReflectEnergyAC,%
ReflectFireAC,%
ReflectMeleeAC,%
ReflectNanoAC,%
ReflectPoisonAC,%
ReflectProjectileAC,%
ReflectRadiationAC,%
"Regain XP Percentage",%
Rifle,""
Riposte,""
"Run Speed",""
Scale,""
Sense,""
"Sensory Impr",""
ShadowBreed,""
"Sharp Obj",""
ShieldChemicalAC,""
ShieldColdAC,""
ShieldEnergyAC,""
ShieldFireAC,""
ShieldMeleeAC,""
ShieldNanoAC,""
ShieldPoisonAC,""
ShieldProjectileAC,""
ShieldRadiationAC,""
Shotgun,""
Side,""
SkillLockModifier,%
"Sneak Atck",""
Stamina,""
Strength,""
Swimming,""
Time&Space,""
"Trap Disarm.",""
Treatment,""
Tutoring,""
"Used NCU",""
"Vehicle Air",""
"Vehicle Ground",""
"Vehicle Water",""
"Weapon Smt",""
"XP",""
*/
}
