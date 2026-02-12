<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

/** This represents the setting mode edit, or noedit */
enum SettingMode: string {
	/** This means the setting is a regular one that can be changed */
	case Edit = 'edit';

	/** This means the setting is immutable via user interaction */
	case NoEdit = 'noedit';
}
