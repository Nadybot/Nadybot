<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Types\Skill;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	DB,
	ModuleInstance,
	Text,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: 'cluster',
		accessLevel: 'guest',
		description: 'Find which clusters buff a specified skill',
	)
]
class ClusterController extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	/** Get a list of skills/attributes you can get clusters for */
	#[NCA\HandlesCommand('cluster')]
	public function clusterListCommand(CmdContext $context): void {
		$data = $this->db->table(Cluster::getTable())
			->orderBy('long_name')
			->asObj(Cluster::class);
		$count = $data->count();

		$blob = "<header2>Clusters<end>\n";
		foreach ($data as $cluster) {
			if ($cluster->cluster_id === 0) {
				continue;
			}
			$blob .= '<tab>'.
				Text::makeChatcmd(
					$cluster->long_name,
					"/tell <myname> cluster {$cluster->long_name}"
				).
				"\n";
		}
		$msg = Text::makeBlob("Cluster List ({$count})", $blob);
		$context->reply($msg);
	}

	/** Find a cluster for a skill/attribute */
	#[NCA\HandlesCommand('cluster')]
	#[NCA\Help\Example('<symbol>cluster comp lit')]
	#[NCA\Help\Example('<symbol>cluster agility')]
	public function clusterCommand(CmdContext $context, string $search): void {
		$skills = Skill::getMatching($search);
		if (count($skills) === 0) {
			$msg = "No skills found that match <highlight>{$search}<end>.";
			$context->reply($msg);
			return;
		}
		$data = $this->db->table(Cluster::getTable())
			->whereIn('skill_id', array_column($skills, 'value'))
			->asObj(Cluster::class);
		$count = $data->count();

		if ($count === 0) {
			$msg = "No skills found that match <highlight>{$search}<end>.";
			$context->reply($msg);
			return;
		}
		$implantDesignerLink = Text::makeChatcmd('implant designer', '/tell <myname> implantdesigner');
		$blob = "Click 'Add' to add cluster to {$implantDesignerLink}.\n\n";
		foreach ($data as $cluster) {
			$results = $this->db->table(ClusterImplantMap::getTable(), 'cim')
				->where('cim.cluster_id', $cluster->cluster_id)
				->orderByDesc('cim.cluster_type_id')
				->select(['cim.implant_type_id as slot', 'cim.cluster_type_id AS grade'])
				->asObj(SlotClusterType::class);
			$blob .= "<pagebreak><header2>{$cluster->long_name}<end>:\n";

			foreach ($results as $row) {
				$impDesignerLink = Text::makeChatcmd(
					'add',
					"/tell <myname> implantdesigner {$row->slot->designSlotName()} {$row->grade->name} {$cluster->long_name}"
				);
				$blob .= "<tab><highlight>{$row->grade->name}<end>: {$row->slot->longName()} [{$impDesignerLink}]";
			}
			$blob .= "\n\n";
		}
		$msg = Text::makeBlob("Cluster search results ({$count})", $blob);
		$context->reply($msg);
	}
}
