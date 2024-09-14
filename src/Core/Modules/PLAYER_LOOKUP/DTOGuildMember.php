<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PLAYER_LOOKUP;

class DTOGuildMember {
	public function __construct(
		public string $SEX,
		public string $BREED,
		public int $PVPRATING,
		public string $NAME,
		public int $CHAR_DIMENSION,
		public string $RANK_TITLE,
		public int $RANK,
		public int $ALIENLEVEL,
		public string $PROF_TITLE,
		public int $HEADID,
		public int $LEVELX,
		public string $PROF,
		public string $DEFENDER_RANK_TITLE,
		public string $FIRSTNAME='',
		public string $LASTNAME='',
		public ?int $CHAR_INSTANCE=null,
		public ?string $PVPTITLE=null,
	) {
	}
}
