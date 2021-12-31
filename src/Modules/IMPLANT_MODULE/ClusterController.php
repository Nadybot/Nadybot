<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Nadybot\Core\Attributes as NCA;
use Illuminate\Support\Collection;
use Nadybot\Core\{
	CmdContext,
	DB,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 * Commands this class contains:
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "cluster",
		accessLevel: "all",
		description: "Find which clusters buff a specified skill",
		help: "cluster.txt"
	)
]
class ClusterController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	#[NCA\Inject]
	public DB $db;

	#[NCA\Inject]
	public Text $text;

	#[NCA\Inject]
	public Util $util;

	#[NCA\HandlesCommand("cluster")]
	public function clusterListCommand(CmdContext $context): void {
		/** @var Collection<Cluster> */
		$data = $this->db->table("Cluster")
			->orderBy("LongName")
			->asObj(Cluster::class);
		$count = $data->count();

		$blob = "<header2>Clusters<end>\n";
		foreach ($data as $cluster) {
			if ($cluster->ClusterID === 0) {
				continue;
			}
			$blob .= "<tab>".
				$this->text->makeChatcmd(
					$cluster->LongName,
					"/tell <myname> cluster {$cluster->LongName}"
				).
				"\n";
		}
		$msg = $this->text->makeBlob("Cluster List ($count)", $blob);
		$context->reply($msg);
	}

	#[NCA\HandlesCommand("cluster")]
	public function clusterCommand(CmdContext $context, string $search): void {
		$query = $this->db->table("Cluster");
		$this->db->addWhereFromParams($query, explode(' ', $search), 'LongName');

		/** @var Collection<Cluster> */
		$data = $query->asObj(Cluster::class);
		$count = $data->count();

		if ($count === 0) {
			$msg = "No skills found that match <highlight>{$search}<end>.";
			$context->reply($msg);
			return;
		}
		$implantDesignerLink = $this->text->makeChatcmd("implant designer", "/tell <myname> implantdesigner");
		$blob = "Click 'Add' to add cluster to $implantDesignerLink.\n\n";
		foreach ($data as $cluster) {
			/** @var SlotClusterType[] */
			$results = $this->db->table("ClusterImplantMap AS c1")
				->join("ClusterType AS c2", "c1.ClusterTypeID", "c2.ClusterTypeID")
				->join("ImplantType AS i", "c1.ImplantTypeID", "i.ImplantTypeID")
				->where("c1.ClusterID", $cluster->ClusterID)
				->orderByDesc("c2.ClusterTypeID")
				->select("i.ShortName as Slot", "c2.Name AS ClusterType")
				->asObj(SlotClusterType::class)->toArray();
			$blob .= "<pagebreak><header2>{$cluster->LongName}<end>:\n";

			foreach ($results as $row) {
				$impDesignerLink = $this->text->makeChatcmd(
					"add",
					"/tell <myname> implantdesigner {$row->Slot} {$row->ClusterType} {$cluster->LongName}"
				);
				$clusterType = ucfirst($row->ClusterType);
				$blob .= "<tab><highlight>{$clusterType}<end>: {$row->Slot} [{$impDesignerLink}]";
			}
			$blob .= "\n\n";
		}
		$msg = $this->text->makeBlob("Cluster search results ($count)", $blob);
		$context->reply($msg);
	}
}
