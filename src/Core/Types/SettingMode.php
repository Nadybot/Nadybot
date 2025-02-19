<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This represents the setting mode edit, or noedit */
enum SettingMode: string {
	case Edit = 'edit';
	case NoEdit = 'noedit';
}
