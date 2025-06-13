<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\BAN;

use Nadybot\Core\{
	Attributes as NCA,
	DB,
	ExportCharacter,
	ModuleInstance,
	Nadybot,
	Types\ExporterInterface
};
use Nadybot\Core\Attributes\Inject;
use Nadybot\Core\DBSchema\BanEntry;
use Psr\Log\LoggerInterface;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\Exporter('banlist'),
]
class BanExporter extends ModuleInstance implements ExporterInterface {
	#[Inject]
	private Nadybot $chatBot;

	/** @return list<ExportedBan> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(BanEntry::getTable())
			->asObj(BanEntry::class)
			->map(function (BanEntry $banEntry): ExportedBan {
				$name = $this->chatBot->getName($banEntry->charid);
				$ban = new ExportedBan(
					character: new ExportCharacter(name: $name, id: $banEntry->charid),
					bannedBy: new ExportCharacter(name: $banEntry->admin),
					banReason: $banEntry->reason,
					banStart: $banEntry->time,
				);
				if (isset($banEntry->banend) && $banEntry->banend > 0) {
					$ban->banEnd = $banEntry->banend;
				}
				return $ban;
			})->toList();
	}
}
