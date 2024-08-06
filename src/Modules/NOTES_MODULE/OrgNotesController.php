<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\ParamClass\PUuid;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	DB,
	EventManager,
	Exceptions\InsufficientAccessException,
	ModuleInstance,
	Modules\ALTS\AltsController,
	ParamClass\PRemove,
	Text,
	Util,
};
use Ramsey\Uuid\Uuid;

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/OrgNotes'),
	NCA\DefineCommand(
		command: 'orgnotes',
		accessLevel: 'guild',
		description: 'Displays, adds, or removes a note from your list',
		alias: 'orgnote'
	),
	NCA\ProvidesEvent(
		event: SyncOrgNoteEvent::class,
		desc: 'Triggered whenever someone creates an org note'
	),
	NCA\ProvidesEvent(
		event: SyncOrgNoteDeleteEvent::class,
		desc: 'Triggered when deleting an org note'
	)
]
class OrgNotesController extends ModuleInstance {
	/** Rank required to delete other people's org notes */
	#[NCA\Setting\Rank]
	public string $orgnoteDeleteOtherRank = 'mod';

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private AccessManager $accessManager;

	#[NCA\Inject]
	private EventManager $eventManager;

	#[NCA\Inject]
	private AltsController $altsController;

	/**
	 * Get all org notes
	 *
	 * @return Collection<int,OrgNote>
	 */
	public function getOrgNotes(): Collection {
		return $this->db->table(OrgNote::getTable())
			->asObj(OrgNote::class);
	}

	/** Get a single org note */
	public function getOrgNote(\Stringable|string $id): ?OrgNote {
		return $this->db->table(OrgNote::getTable())
			->where('id', (string)$id)
			->asObj(OrgNote::class)
			->first();
	}

	public function createOrgNote(string $creator, string $text, bool $forceSync=false): OrgNote {
		$note = new OrgNote(
			added_by: $creator,
			note: $text,
		);
		$this->db->insert($note);

		$event = SyncOrgNoteEvent::fromOrgNote($note);
		$event->forceSync = $forceSync;
		$this->eventManager->fireEvent($event);
		return $note;
	}

	/** Delete an org note form the DB and return success status */
	public function removeOrgNote(OrgNote $note, bool $forceSync=false): bool {
		$success = $this->db->table(OrgNote::getTable())->delete($note->id) > 0;
		if (!$success) {
			return false;
		}
		$event = new SyncOrgNoteDeleteEvent(
			uuid: $note->id->toString(),
			forceSync: $forceSync,
		);
		$this->eventManager->fireEvent($event);
		return true;
	}

	/**
	 * Delete the given org note with the rights of $actor
	 *
	 * @throws InsufficientAccessException if no right to delete note
	 */
	public function removeOrgNoteId(\Stringable|string $noteId, string $actor, bool $forceSync=false): bool {
		$note = $this->getOrgNote($noteId);
		if (!isset($note)) {
			return false;
		}
		if (!$this->canDeleteOrgNote($note, $actor)) {
			throw new InsufficientAccessException(
				"Only {$this->orgnoteDeleteOtherRank} or higher can delete other ".
				"members' notes."
			);
		}
		return $this->removeOrgNote($note, $forceSync);
	}

	/** List all organization-wide notes */
	#[NCA\HandlesCommand('orgnotes')]
	public function cmdShowOrgNotes(CmdContext $context): void {
		$notes = $this->getOrgNotes();
		if ($notes->isEmpty()) {
			$context->reply('Org notes (0)');
			return;
		}
		$chunks = [];
		foreach ($notes as $note) {
			$removeLink = '';
			if ($this->canDeleteOrgNote($note, $context->char->name)) {
				$removeLink = ' [' . Text::makeChatcmd(
					'remove',
					"/tell <myname> orgnote rem {$note->id}"
				) . ']';
			}
			$chunks []= "<tab>{$note->added_by} on ".
				Util::date($note->added_on).
				"\n<tab>- <highlight>{$note->note}<end>{$removeLink}";
		}
		$blob = "<header2>Notes in your org/alliance<end>\n\n".
			implode("\n\n", $chunks);
		$msg = $this->text->makeBlob('Org notes (' . $notes->count() . ')', $blob);
		$context->reply($msg);
	}

	/** Create a new, organization-wide notes */
	#[NCA\HandlesCommand('orgnotes')]
	public function cmdAddOrgNote(
		CmdContext $context,
		#[NCA\Str('add', 'new', 'create')] string $action,
		string $text
	): void {
		$note = $this->createOrgNote($context->char->name, $text, $context->forceSync);
		$context->reply("Note <highlight>#{$note->id}<end> created.");
	}

	/** Remove an organization-wide note */
	#[NCA\HandlesCommand('orgnotes')]
	public function cmdRemOrgNote(
		CmdContext $context,
		PRemove $action,
		PUuid $id
	): void {
		$id = $id();
		try {
			$removed = $this->removeOrgNoteId($id, $context->char->name, $context->forceSync);
		} catch (InsufficientAccessException $e) {
			$context->reply($e->getMessage());
			return;
		}
		if ($removed) {
			$context->reply("Org note <highlight>#{$id}<end> deleted.");
			return;
		}
		$context->reply("No org note <highlight>#{$id}<end> found.");
	}

	#[NCA\Event(
		name: SyncOrgNoteEvent::EVENT_MASK,
		description: 'Sync externally created org notes'
	)]
	public function processOrgNoteSyncEvent(SyncOrgNoteEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$note = $event->toOrgNote();
		$id = $this->db->table(OrgNote::getTable())
			->where('uuid', $event->uuid)
			->pluckStrings('id')
			->first();
		if (isset($id)) {
			$note->id = Uuid::fromString($id);
			$this->db->update($note);
		} else {
			$this->db->insert($note);
		}
	}

	#[NCA\Event(
		name: SyncOrgNoteDeleteEvent::EVENT_MASK,
		description: 'Sync externally deleted org notes'
	)]
	public function processNewsDeleteSyncEvent(SyncOrgNoteDeleteEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->db->table(OrgNote::getTable())
			->where('uuid', $event->uuid)
			->delete();
	}

	/** Check if $actor has sufficient rights to delete $note */
	protected function canDeleteOrgNote(OrgNote $note, string $actor): bool {
		$isAdmin = $this->accessManager->checkSingleAccess(
			$actor,
			$this->orgnoteDeleteOtherRank
		);
		if ($isAdmin) {
			return true;
		}
		$actorMain = $this->altsController->getMainOf($actor);
		$noteMain = $this->altsController->getMainOf($note->added_by);
		return $actorMain === $noteMain;
	}
}
