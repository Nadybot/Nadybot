<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

class DTOGuild {
	public function __construct(
		public int $NANORACECOUNT,
		public int $MINLVL,
		public int $ENFCOUNT,
		public int $NUMMEMBERS,
		public int $NEUTERCOUNT,
		public int $KEEPERCOUNT,
		public int $OPIFEXCOUNT,
		public int $ORG_DIMENSION,
		public int $MONSTERCOUNT,
		public int $SOLIDERCOUNT,
		public int $BTCOUNT,
		public int $AGENTCOUNT,
		public int $FIXERCOUNT,
		public int $MACOUNT,
		public string $GOVERNINGNAME,
		public int $SOLITUSCOUNT,
		public int $TRADERCOUNT,
		public int $METACOUNT,
		public int $MAXLVL,
		public int $ORG_INSTANCE,
		public int $DOCTORCOUNT,
		public string $OBJECTIVE,
		public int $ATROXCOUNT,
		public int $SHADECOUNT,
		public string $DESCRIPTION,
		public string $HISTORY,
		public float $AVGLVL,
		public int $MALECOUNT,
		public int $NANOCOUNT,
		public string $SIDE_NAME,
		public int $ENGINEEERCOUNT,
		public int $ADVENTURERCOUNT,
		public int $SIDE,
		public int $FEMALECOUNT,
		public ?string $NAME=null,
	) {
	}
}
