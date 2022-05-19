<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DBSchema\EventCfg,
	ModuleInstance,
	Text,
	DB,
	EventManager,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "eventlist",
		accessLevel: "guild",
		description: "Shows a list of all events on the bot",
		defaultStatus: 1
	)
]
class EventlistController extends ModuleInstance {
	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public DB $db;

	/**
	 * Show a list of all events on the bot. Give &lt;event type&gt; to show only events matching a string
	 */
	#[NCA\HandlesCommand("eventlist")]
	public function eventlistCommand(CmdContext $context, ?string $eventType): void {
		$query = $this->db->table(EventManager::DB_TABLE)
			->select("type", "description", "module", "file", "status")
			->orderBy("type")
			->orderBy("module");
		if (isset($eventType)) {
			$query->whereIlike("type", "%{$eventType}%");
		}
		/** @var EventCfg[] $data */
		$data = $query->asObj(EventCfg::class)->toArray();
		$count = count($data);

		if ($count === 0) {
			$msg = "No events of type <highlight>{$eventType}<end> found.";
			$context->reply($msg);
			return;
		}
		$blob = '';
		$lastType = '';
		foreach ($data as $row) {
			$on = $this->text->makeChatcmd('ON', "/tell <myname> config event $row->type $row->file enable all");
			$off = $this->text->makeChatcmd('OFF', "/tell <myname> config event $row->type $row->file disable all");

			if ($row->status === 1) {
				$status = "<on>Enabled<end>";
			} else {
				$status = "<off>Disabled<end>";
			}

			if ($lastType !== $row->type) {
				$blob .= "<pagebreak>\n<header2>{$row->type}<end>\n";
				$lastType = $row->type;
			}
			$blob .= "<tab>$on  $off  $row->module ($status)";
			if ($row->description !== null && $row->description !== '') {
				$blob .= " - $row->description\n";
			}
		}

		$msg = $this->text->makeBlob("Event List ($count)", $blob);
		$context->reply($msg);
	}
}
