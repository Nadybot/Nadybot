<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use Nadybot\Core\{AccessManager, Registry};
use Nadybot\Modules\RAID_MODULE\RaidRankController;

/** This is one valid access level of the bot */
enum AccessLevel: string {
	public function compare(self $that): int {
		return $this->toInt() <=> $that->toInt();
	}

	public function toInt(): int {
		return match ($this) {
			self::None => 0,
			self::Superadmin => 1,
			self::Admin => 2,
			self::Mod => 3,
			self::Guild => 4,
			self::RaidAdmin3 => 5,
			self::RaidAdmin2 => 6,
			self::RaidAdmin1 => 7,
			self::RaidLeader3 => 8,
			self::RaidLeader2 => 9,
			self::RaidLeader1 => 10,
			self::Member => 14,
			self::RaidLeader => 15,
			self::Guest => 16,
			self::All => 17,
		};
	}

	/** Create an instance by the name of the raid rank */
	public function fromName(string $name): self {
		$alManager = Registry::getInstance(AccessManager::class);
		$rrCtrl = Registry::getInstance(RaidRankController::class);
		$accessLevel = strtolower($name);
		return match ($accessLevel) {
			$alManager->rankNameRL,'raidleader' => self::RaidLeader,
			$alManager->rankNameMod,'moderator' => self::Mod,
			$alManager->rankNameAdmin,'administrator' => self::Admin,
			$alManager->rankNameSuperadmin,'superadmin' => self::Superadmin,
			$alManager->rankNameMember,'member' => self::Member,
			$alManager->rankNameGuest,'guest' => self::Guest,
			$alManager->rankNameGuild,'guild' => self::Guild,
			$rrCtrl->nameRaidAdmin3 => self::RaidAdmin3,
			$rrCtrl->nameRaidAdmin2 => self::RaidAdmin2,
			$rrCtrl->nameRaidAdmin1 => self::RaidAdmin1,
			$rrCtrl->nameRaidLeader3 => self::RaidLeader3,
			$rrCtrl->nameRaidLeader2 => self::RaidLeader2,
			$rrCtrl->nameRaidLeader1 => self::RaidLeader1,
			default => self::from($accessLevel),
		};
	}

	/** Get the name to display for this access level, always lower-cased */
	public function displayName(): string {
		$alManager = Registry::getInstance(AccessManager::class);
		$rrCtrl = Registry::getInstance(RaidRankController::class);
		return match ($this) {
			self::None => 'none',
			self::Superadmin => $alManager->rankNameSuperadmin,
			self::Admin => $alManager->rankNameAdmin,
			self::Mod => $alManager->rankNameMod,
			self::Guild => $alManager->rankNameGuild,
			self::RaidAdmin3 => $rrCtrl->nameRaidAdmin3,
			self::RaidAdmin2 => $rrCtrl->nameRaidAdmin2,
			self::RaidAdmin1 => $rrCtrl->nameRaidAdmin1,
			self::RaidLeader3 => $rrCtrl->nameRaidLeader3,
			self::RaidLeader2 => $rrCtrl->nameRaidLeader2,
			self::RaidLeader1 => $rrCtrl->nameRaidLeader1,
			self::Member => $alManager->rankNameMember,
			self::RaidLeader => $alManager->rankNameRL,
			self::Guest => $alManager->rankNameGuest,
			self::All => 'all',
		};
	}

	case None = 'none';
	case Superadmin = 'superadmin';
	case Admin = 'admin';
	case Mod = 'mod';
	case Guild = 'guild';
	case RaidAdmin3 = 'raid_admin_3';
	case RaidAdmin2 = 'raid_admin_2';
	case RaidAdmin1 = 'raid_admin_1';
	case RaidLeader3 = 'raid_leader_3';
	case RaidLeader2 = 'raid_leader_2';
	case RaidLeader1 = 'raid_leader_1';
	case Member = 'member';
	case RaidLeader = 'rl';
	case Guest = 'guest';
	case All = 'all';
}
