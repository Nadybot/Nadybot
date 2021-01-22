<?php declare(strict_types=1);

namespace Nadybot\Modules\COMMENT_MODULE;

class FormattedComments {
	/** How many different characters have comments in here? */
	public int $numChars = 0;

	/** How many different players have comments in here? */
	public int $numMains = 0;

	/** How many comments are there in total? */
	public int $numComments = 0;

	/** The formatted text as blob */
	public string $blob;
}
