<?php declare(strict_types=1);

namespace Nadybot\Modules\ORGLIST_MODULE;

use Nadybot\Core\CommandReply;

class Orglist {
	public int $start;
	public CommandReply $sendto;
	public string $org;
	/** @var string[] */
	public array $orgtype;
	/** @var array<string,OrglistResult> */
	public array $result;
	/** @var array<string,bool> */
	public array $added;
	/** @var array<string,bool> */
	public array $check;
}
