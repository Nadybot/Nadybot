<?php declare(strict_types=1);

namespace Nadybot\Modules\IMPLANT_MODULE;

use Illuminate\Support\Collection;
use Nadybot\Core\{
	CommandReply,
	DB,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 *
 * @Instance
 *
 * Commands this class contains:
 *	@DefineCommand(
 *		command     = 'cluster',
 *		accessLevel = 'all',
 *		description = 'Find which clusters buff a specified skill',
 *		help        = 'cluster.txt'
 *	)
 */
class ClusterController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;

	/** @Inject */
	public DB $db;

	/** @Inject */
	public Text $text;

	/** @Inject */
	public Util $util;

	/**
	 * @HandlesCommand("cluster")
	 * @Matches("/^cluster$/i")
	 */
	public function clusterListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
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
		$sendto->reply($msg);
	}

	/**
	 * @HandlesCommand("cluster")
	 * @Matches("/^cluster (.+)$/i")
	 */
	public function clusterCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = trim($args[1]);
		$query = $this->db->table("Cluster");
		$this->db->addWhereFromParams($query, explode(' ', $search), 'LongName');

		/** @var Collection<Cluster> */
		$data = $query->asObj(Cluster::class);
		$count = $data->count();

		if ($count === 0) {
			$msg = "No skills found that match <highlight>{$search}<end>.";
			$sendto->reply($msg);
			return;
		}
		$implantDesignerLink = $this->text->makeChatcmd("implant designer", "/tell <myname> implantdesigner");
		$blob = "Click 'Add' to add cluster to $implantDesignerLink.\n\n";
		foreach ($data as $cluster) {
			$results = $this->db->table("ClusterImplantMap AS c1")
				->join("ClusterType AS c2", "c1.ClusterTypeID", "c2.ClusterTypeID")
				->join("ImplantType AS i", "c1.ImplantTypeID", "i.ImplantTypeID")
				->where("c1.ClusterID", $cluster->ClusterID)
				->orderByDesc("c2.ClusterTypeID")
				->select("i.ShortName as Slot", "c2.Name AS ClusterType")
				->asObj()->toArray();
			$blob .= "<pagebreak><header2>{$cluster->LongName}<end>:\n";

			foreach ($results as $row) {
				$impDesignerLink = $this->text->makeChatcmd(
					"Add",
					"/tell <myname> implantdesigner $row->Slot $row->ClusterType $cluster->LongName"
				);
				$clusterType = ucfirst($row->ClusterType);
				$blob .= "<tab><highlight>$clusterType<end>: $row->Slot ($impDesignerLink)";
			}
			$blob .= "\n\n";
		}
		$msg = $this->text->makeBlob("Cluster search results ($count)", $blob);
		$sendto->reply($msg);
	}
}
