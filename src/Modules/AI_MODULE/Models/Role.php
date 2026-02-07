<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE\Models;

/**
 * Represents a chat message role
 */
enum Role: string {
	case SYSTEM = 'system';
	case USER = 'user';
	case ASSISTANT = 'assistant';
}
