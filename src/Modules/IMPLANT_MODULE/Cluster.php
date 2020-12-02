<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\DBRow;

class Cluster extends DBRow {
	public int $ClusterID;
	public int $EffectTypeID;
	public string $LongName;
	public int $NPReq;
	public int $SkillID;
}
