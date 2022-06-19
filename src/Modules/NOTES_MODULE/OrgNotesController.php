<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	DB,
	EventManager,
	InsufficientAccessException,
	ModuleInstance,
	Modules\ALTS\AltsController,
	Nadybot,
	ParamClass\PRemove,
	Text,
	Util,
};

/**
 * @author Nadyita (RK5)
 */
#[
	NCA\Instance,
	NCA\HasMigrations("Migrations/OrgNotes"),
	NCA\DefineCommand(
		command: "orgnotes",
		accessLevel: "guild",
		description: "Displays, adds, or removes a note from your list",
		alias: "orgnote"
	),
	NCA\ProvidesEvent(
		event: "sync(orgnote)",
		desc: "Triggered whenever someone creates an org note"
	),
	NCA\ProvidesEvent(
		event: "sync(orgnote-delete)",
		desc: "Triggered when deleting an org note"
	)
]
class OrgNotesController extends ModuleInstance {
	public const DB_TABLE = "org_notes";

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\Inject]
	public Nadybot $chatBot;

	#[NCA\Inject]
	public AccessManager $accessManager;

	#[NCA\Inject]
	public EventManager $eventManager;

	#[NCA\Inject]
	public AltsController $altsController;

	/** Rank required to delete other people's org notes */
	#[NCA\Setting\Rank]
	public string $orgnoteDeleteOtherRank = "mod";

	/**
	 * Get all org notes
	 *
	 * @return Collection<OrgNote>
	 */
	public function getOrgNotes(): Collection {
		return $this->db->table(self::DB_TABLE)
			->asObj(OrgNote::class);
	}

	/** Get a single org note */
	public function getOrgNote(int $id): ?OrgNote {
		return $this->db->table(self::DB_TABLE)
			->where("id", $id)
			->asObj(OrgNote::class)
			->first();
	}

	public function createOrgNote(string $creator, string $text, bool $forceSync=false): OrgNote {
		$note = new OrgNote();
		$note->added_by = $creator;
		$note->uuid = $this->util->createUUID();
		$note->note = $text;
		$note->id = $this->db->insert(self::DB_TABLE, $note);

		$event = SyncOrgNoteEvent::fromOrgNote($note);
		$event->forceSync = $forceSync;
		$this->eventManager->fireEvent($event);
		return $note;
	}

	/** Delete an org note form the DB and return success status */
	public function removeOrgNote(OrgNote $note, bool $forceSync=false): bool {
		$success = $this->db->table(self::DB_TABLE)->delete($note->id) > 0;
		if (!$success) {
			return false;
		}
		$event = new SyncOrgNoteDeleteEvent();
		$event->forceSync = $forceSync;
		$event->uuid = $note->uuid;
		$this->eventManager->fireEvent($event);
		return true;
	}

	/**
	 * Delete the given org note with the rights of $actor
	 *
	 * @throws InsufficientAccessException if no right to delete note
	 */
	public function removeOrgNoteId(int $noteId, string $actor, bool $forceSync=false): bool {
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
	#[NCA\HandlesCommand("orgnotes")]
	public function cmdShowOrgNotes(CmdContext $context): void {
		$notes = $this->getOrgNotes();
		if ($notes->isEmpty()) {
			$context->reply("Org notes (0)");
			return;
		}
		$chunks = [];
		foreach ($notes as $note) {
			$removeLink = "";
			if ($this->canDeleteOrgNote($note, $context->char->name)) {
				$removeLink = " [" . $this->text->makeChatcmd(
					"remove",
					"/tell <myname> orgnote rem {$note->id}"
				) . "]";
			}
			$chunks []= "<tab>{$note->added_by} on ".
				$this->util->date($note->added_on).
				"\n<tab>- <highlight>{$note->note}<end>{$removeLink}";
		}
		$blob = "<header2>Notes in your org/alliance<end>\n\n".
			join("\n\n", $chunks);
		$msg = $this->text->makeBlob("Org notes (" . $notes->count() . ")", $blob);
		$context->reply($msg);
	}

	/** Create a new, organization-wide notes */
	#[NCA\HandlesCommand("orgnotes")]
	public function cmdAddOrgNote(
		CmdContext $context,
		#[NCA\Str("add", "new", "create")] string $action,
		string $text
	): void {
		$note = $this->createOrgNote($context->char->name, $text, $context->forceSync);
		$context->reply("Note <highlight>#{$note->id}<end> created.");
	}

	/** Remove an organization-wide note */
	#[NCA\HandlesCommand("orgnotes")]
	public function cmdRemOrgNote(
		CmdContext $context,
		PRemove $action,
		int $id
	): void {
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
		name: "sync(orgnote)",
		description: "Sync externally created org notes"
	)]
	public function processOrgNoteSyncEvent(SyncOrgNoteEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$note = $event->toOrgNote();
		$note->id = $this->db->table(self::DB_TABLE)
			->where("uuid", $event->uuid)
			->pluckAs("id", "integer")
			->first();
		if (isset($note->id)) {
			$this->db->update(self::DB_TABLE, "id", $note);
		} else {
			$this->db->insert(self::DB_TABLE, $note);
		}
	}

	#[NCA\Event(
		name: "sync(orgnote-delete)",
		description: "Sync externally deleted org notes"
	)]
	public function processNewsDeleteSyncEvent(SyncOrgNoteDeleteEvent $event): void {
		if ($event->isLocal()) {
			return;
		}
		$this->db->table(self::DB_TABLE)
			->where("uuid", $event->uuid)
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
