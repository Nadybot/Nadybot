<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\CONFIG;

use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	DBSchema\EventCfg,
	ModuleInstance,
	Text,
	Types\AccessLevel,
	Types\Status,
};

#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'eventlist',
		accessLevel: AccessLevel::Guild,
		description: 'Shows a list of all events on the bot',
		defaultStatus: Status::Enabled
	)
]
class EventlistController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	/** Show a list of all events on the bot. Give &lt;event type&gt; to show only events matching a string */
	#[NCA\HandlesCommand('eventlist')]
	public function eventlistCommand(CmdContext $context, ?string $eventType): void {
		$query = $this->db->table(EventCfg::getTable())
			->select('type', 'description', 'module', 'file', 'status')
			->orderBy('type')
			->orderBy('module');
		if (isset($eventType)) {
			$query->whereIlike('type', "%{$eventType}%");
		}

		$data = $query->asObj(EventCfg::class);
		$count = $data->count();

		if ($count === 0) {
			$msg = "No events of type <highlight>{$eventType}<end> found.";
			$context->reply($msg);
			return;
		}
		$blob = '';
		$lastType = '';
		foreach ($data as $row) {
			$on = Text::makeChatcmd('ON', "/tell <myname> config event {$row->type} {$row->file} enable all");
			$off = Text::makeChatcmd('OFF', "/tell <myname> config event {$row->type} {$row->file} disable all");

			if ($row->status === Status::Enabled) {
				$status = '<on>Enabled<end>';
			} else {
				$status = '<off>Disabled<end>';
			}

			if ($lastType !== $row->type) {
				$blob .= "<pagebreak>\n<header2>{$row->type}<end>\n";
				$lastType = $row->type;
			}
			$blob .= "<tab>{$on}  {$off}  {$row->module} ({$status})";
			if ($row->description !== '') {
				$blob .= " - {$row->description}\n";
			}
		}

		$msg = Text::makeBlob("Event List ({$count})", $blob);
		$context->reply($msg);
	}
}
