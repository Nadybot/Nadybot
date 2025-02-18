<?php declare(strict_types=1);

namespace Nadybot\Core;

enum AuditAction: string {
	case AddRank = 'add-rank';
	case DelRank = 'del-rank';
	case PermBan = 'permanent-ban';
	case TempBan = 'temporary-ban';
	case Lock = 'lock';
	case Unlock = 'unlock';
	case Join = 'join';
	case Kick = 'kick';
	case Leave = 'leave';
	case Invite = 'invite';
	case AddAlt = 'add-alt';
	case DelAlt = 'del-alt';
	case SetMain = 'set-main';
}
