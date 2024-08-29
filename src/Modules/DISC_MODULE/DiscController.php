<?php declare(strict_types=1);

namespace Nadybot\Modules\DISC_MODULE;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	ParamClass\PItem,
	Text,
};
use Nadybot\Modules\NANO_MODULE\Nano;

/**
 * @author Nadyita (RK5) <nadyita@hodorraid.org>
 */
#[
	NCA\Instance,
	NCA\HasMigrations,
	NCA\DefineCommand(
		command: 'disc',
		accessLevel: 'guest',
		description: 'Show which nano a disc will turn into',
	)
]
class DiscController extends ModuleInstance {
	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Setup]
	public function setup(): void {
		// load database tables from .sql-files
		$this->db->loadCSVFile($this->moduleName, __DIR__ . '/discs.csv');
	}

	/**
	 * Get the instruction disc from its name and return an array with results
	 *
	 * @return list<Disc> An array of database entries that matched
	 */
	public function getDiscsByName(string $discName): array {
		$query = $this->db->table(Disc::getTable());
		$this->db->addWhereFromParams($query, explode(' ', $discName), 'disc_name');
		return $query->asObjArr(Disc::class);
	}

	/** Get the instruction disc from its id and return the result or null */
	public function getDiscById(int $discId): ?Disc {
		return $this->db->table(Disc::getTable())
			->where('disc_id', $discId)
			->asObj(Disc::class)
			->first();
	}

	/** Show what nano a disc will turn into */
	#[NCA\HandlesCommand('disc')]
	#[NCA\Help\Example('<symbol>disc <a href=itemref://163410/163410/139>Instruction Disc (Tranquility of the Vale)</a>')]
	public function discByItemCommand(CmdContext $context, PItem $item): void {
		$disc = $this->getDiscById($item->lowID);
		if (!isset($disc)) {
			$msg = "Either <highlight>{$item}<end> is not an instruction disc, or it ".
				'cannot be turned into a nano anymore.';
			$context->reply($msg);
			return;
		}
		$this->discCommand($context, $disc);
	}

	/** Show what nano a disc will turn into */
	#[NCA\HandlesCommand('disc')]
	#[NCA\Help\Example('<symbol>disc tranquility vale')]
	public function discByNameCommand(CmdContext $context, string $search): void {
		// If only a name was given, lookup the disc's ID
		$discs = $this->getDiscsByName($search);
		// Not found? Cannot be made into a nano anymore or simply mistyped
		if (!count($discs)) {
			$msg = "Either <highlight>{$search}<end> was mistyped or it cannot be turned into a nano anymore.";
			$context->reply($msg);
			return;
		}
		// If there are multiple matches, present a list of discs to choose from
		if (count($discs) > 1) {
			$context->reply($this->getDiscChoiceDialogue($discs));
			return;
		}
		// Only one found, so pick this one
		$disc = $discs[0];
		$this->discCommand($context, $disc);
	}

	public function discCommand(CmdContext $context, Disc $disc): void {
		$discLink = $disc->getLink();
		$nanoLink = $disc->getCrystalLink();
		$nanoDetails = $this->getNanoDetails($disc);
		if (!isset($nanoDetails)) {
			$context->reply("Cannot find the nano details for {$disc->disc_name}.");
			return;
		}
		$msg = sprintf(
			'%s will turn into %s (%s, %s, <highlight>%s<end>).',
			$discLink,
			$nanoLink,
			implode(', ', explode(':', $nanoDetails->professions)),
			$nanoDetails->nanoline_name,
			$nanoDetails->location
		);
		if (strlen($disc->comment ?? '')) {
			$msg .= ' <red>' . ($disc->comment??'') . '!<end>';
		}
		$context->reply($msg);
	}

	/** Get additional information about the nano of a disc */
	public function getNanoDetails(Disc $disc): ?NanoDetails {
		return $this->db->table(Nano::getTable())
			->where('crystal_id', $disc->crystal_id)
			->select(['location', 'professions', 'strain AS nanoline_name'])
			->asObj(NanoDetails::class)
			->first();
	}

	/**
	 * Generate a choice dialogue if multiple discs match the search criteria
	 *
	 * @param iterable<Disc> $discs The discs that matched the search
	 */
	public function getDiscChoiceDialogue(iterable $discs): string {
		$blob = [];
		foreach ($discs as $disc) {
			$text = Text::makeChatcmd($disc->disc_name, '/tell <myname> disc '.$disc->disc_name);
			$blob []= $text;
		}
		$msg = $this->text->makeBlob(
			count($blob). ' matches matching your search',
			implode("\n<pagebreak>", $blob),
			'Multiple matches, please choose one'
		);
		return "Found {$msg}.";
	}
}
