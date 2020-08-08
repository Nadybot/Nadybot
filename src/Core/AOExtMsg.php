<?php declare(strict_types=1);

namespace Nadybot\Core;

/**
 * @author Oskari Saarenmaa <auno@auno.org>.
 * @license GPL
 *
 * Container class to hold Extended messages
 */

class AOExtMsg {
	public array $args;
	public int $category;
	public int $instance;
	public string $message_string;
	public string $message;
}
