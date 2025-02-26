<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use AO\Utils;
use Nadybot\Core\{AccessManager, Registry};
use Nadybot\Modules\RAID_MODULE\RaidRankController;
use Throwable;
use ValueError;

/** This is one valid access level of the bot */
enum AccessLevel: string {
	/**
	 * Compare if our access level has more rights than $that
	 *
	 * @return int * `1` if our access level is better
	 *             * `0` if they are the same
	 *             * `-1` if `$that`'s access level is better
	 */
	public function compare(self $that): int {
		return $that->toInt() <=> $this->toInt();
	}

	/** Check if our access level is higher than the given one */
	public function higherThan(self $that): bool {
		return $this->toInt() < $that->toInt();
	}

	/** Check if our access level is lower than the given one */
	public function lowerThan(self $that): bool {
		return $this->toInt() > $that->toInt();
	}

	/** Check if our access level is higher than or equal to the given one */
	public function atLeast(self $that): bool {
		return $this->toInt() <= $that->toInt();
	}

	/** Check if our access level is lower than or equal to the given one */
	public function atMost(self $that): bool {
		return $this->toInt() >= $that->toInt();
	}

	/** Get the numeric height of the access level (lower is better) */
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

	/** Create an instance given the numeric access level (lower is better) */
	public static function fromInt(int $height): self {
		return match ($height) {
			0 => self::None,
			1 => self::Superadmin,
			2 => self::Admin,
			3 => self::Mod,
			4 => self::Guild,
			5 => self::RaidAdmin3,
			6 => self::RaidAdmin2,
			7 => self::RaidAdmin1,
			8 => self::RaidLeader3,
			9 => self::RaidLeader2,
			10 => self::RaidLeader1,
			14 => self::Member,
			15 => self::RaidLeader,
			16 => self::Guest,
			17 => self::All,
			default => throw new ValueError("Invalid access level: {$height}"),
		};
	}

	/** Check if we are a raid-rank access level */
	public function isRaidAL(): bool {
		return match ($this) {
			self::None => false,
			self::Superadmin => false,
			self::Admin => false,
			self::Mod => false,
			self::Guild => false,
			self::RaidAdmin3 => true,
			self::RaidAdmin2 => true,
			self::RaidAdmin1 => true,
			self::RaidLeader3 => true,
			self::RaidLeader2 => true,
			self::RaidLeader1 => true,
			self::Member => false,
			self::RaidLeader => false,
			self::Guest => false,
			self::All => false,
		};
	}

	/** Create an instance by the name of the rank, or null if impossible */
	public static function tryFromName(string $name): ?self {
		try {
			return self::fromName($name);
		} catch (Throwable) {
			return null;
		}
	}

	/**
	 * Check if we have an explicit, real rank,
	 * or just a temporary one like
	 * guest, guild, raidleader and none
	 */
	public function isRealRank(): bool {
		return match ($this) {
			self::None => false,
			self::Superadmin => true,
			self::Admin => true,
			self::Mod => true,
			self::Guild => false,
			self::RaidAdmin3 => true,
			self::RaidAdmin2 => true,
			self::RaidAdmin1 => true,
			self::RaidLeader3 => true,
			self::RaidLeader2 => true,
			self::RaidLeader1 => true,
			self::Member => true,
			self::RaidLeader => false,
			self::Guest => false,
			self::All => false,
		};
	}

	/** Create an instance by the name of the rank */
	public static function fromName(string $name): self {
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

	/** Get the name to display for this access level, first letter upper-cased */
	public function displayNameUC(): string {
		return Utils::normalizeCharacter($this->displayName());
	}

	/** Get the name to display for this access level, always lower-cased */
	public function displayName(): string {
		$alManager = Registry::getInstance(AccessManager::class);
		$rrCtrl = Registry::getInstance(RaidRankController::class);
		$name = match ($this) {
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
		return strtolower($name);
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
