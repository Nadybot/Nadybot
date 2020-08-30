<?php declare(strict_types=1);

namespace Nadybot\Modules\NANO_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	SettingManager,
	Text,
	Util,
};

/**
 * @author Nadyita (RK5)
 * @author Tyrence (RK2)
 * @author Healnjoo (RK2)
 * @author Mdkdoc420 (RK2)
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'nano',
 *		accessLevel = 'all',
 *		description = 'Searches for a nano and tells you were to get it',
 *		help        = 'nano.txt'
 *	)
 *	@DefineCommand(
 *		command     = 'nanolines',
 *		accessLevel = 'all',
 *		description = 'Shows nanos based on nanoline',
 *		help        = 'nanolines.txt',
 *		alias		= 'nl'
 *	)
 *	@DefineCommand(
 *		command     = 'nanolinesfroob',
 *		accessLevel = 'all',
 *		description = 'Shows nanos for froobs based on nanoline ',
 *		help        = 'nanolinesfroob.txt',
 *		alias		= 'nlf'
 *	)
 *	@DefineCommand(
 *		command     = 'nanoloc',
 *		accessLevel = 'all',
 *		description = 'Browse nanos by location',
 *		help        = 'nano.txt'
 *	)
 */
class NanoController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public SettingManager $settingManager;
	
	/** @Inject */
	public Text $text;
	
	/** @Inject */
	public Util $util;
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'nanos');
		
		$this->settingManager->add(
			$this->moduleName,
			'maxnano',
			'Number of Nanos shown on the list',
			'edit',
			"number",
			'40',
			'30;40;50;60',
			"",
			"mod"
		);
		$this->settingManager->add(
			$this->moduleName,
			"shownanolineicons",
			"Show icons for the nanolines",
			"edit",
			"options",
			"0",
			"true;false",
			"1;0"
		);
	}

	/**
	 * @HandlesCommand("nano")
	 * @Matches("/^nano (.+)$/i")
	 */
	public function nanoCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$search = $args[1];

		$search = htmlspecialchars_decode($search);
		$tmp = explode(" ", $search);
		[$query, $params] = $this->util->generateQueryFromParams($tmp, 'nano_name');
		array_push($params, $this->settingManager->getInt("maxnano"));

		$sql = "SELECT * ".
			"FROM nanos ".
			"WHERE $query ".
			"ORDER BY ".
				"strain ASC, ".
				"sub_strain ASC, ".
				"ql DESC, ".
				"nano_cost DESC, ".
				"nano_name LIKE 'Improved%' DESC, ".
				"nano_name ASC ".
			"LIMIT ?";

		/** @var Nano[] */
		$data = $this->db->fetchAll(Nano::class, $sql, ...$params);

		$count = count($data);
		if ($count === 0) {
			$msg = "No nanos found.";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		$currentNanoline = -1;
		$currentSubstrain = null;
		foreach ($data as $row) {
			if ($currentNanoline !== $row->strain || $currentSubstrain !== $row->sub_strain) {
				if (!empty($row->strain)) {
					$nanolineLink = $this->text->makeChatcmd("see all nanos", "/tell <myname> nanolines $row->strain");
					$blob .= "\n<header2>$row->school<end> &gt; <header2>$row->strain<end>";
					if ($row->sub_strain) {
						$blob .= " &gt; <header2>$row->sub_strain<end>";
					}
					$blob .= " - [$nanolineLink]\n";
				} else {
					$blob .= "\n<header2>Unknown/General<end>\n";
				}
				$currentNanoline = $row->strain;
				$currentSubstrain = $row->sub_strain;
			}
			$nanoLink = $this->makeNanoLink($row);
			$crystalLink = $this->text->makeItem($row->crystal_id, $row->crystal_id, $row->ql, "Crystal");
			$info = "QL" . $this->text->alignNumber($row->ql, 3) . " [$crystalLink] $nanoLink ($row->location)";
			$info .= " - <highlight>" . implode("<end>, <highlight>", explode(":", $row->professions)) . "<end>";
			$blob .= "<tab>$info\n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("Nano Search Results ($count)", $blob);
		if (count($data) === 1) {
			$msg = $info . " [" . $this->text->makeBlob("details", $blob) . "]";
		}

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("nanolines")
	 * @Matches("/^nanolines$/i")
	 */
	public function nanolinesListProfsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->listNanolineProfs($sendto, false);
	}

	/**
	 * @HandlesCommand("nanolinesfroob")
	 * @Matches("/^nanolinesfroob$/i")
	 */
	public function nanolinesFroobListProfsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->listNanolineProfs($sendto, true);
	}

	/**
	 * List all professions for which nanolines exist
	 *
	 * @param \Nadybot\Core\CommandReply $sendto Where to send the reply to
	 * @param bool $froobObly Is set, only show professions a froob can play
	 * @return void
	 */
	public function listNanolineProfs(CommandReply $sendto, bool $froobOnly): void {
		$froobWhere = "";
		if ($froobOnly) {
			$froobWhere = " AND professions NOT IN ('Keeper', 'Shade') ";
		}
		$sql = "SELECT DISTINCT professions ".
			"FROM nanos ".
			"WHERE professions NOT LIKE '%:%' ".
				$froobWhere.
			"ORDER BY professions ASC";
		$data = $this->db->query($sql);

		$blob = "<header2>Choose a profession<end>\n";
		$command = $froobOnly ? "nanolinesfroob" : "nanolines";
		foreach ($data as $row) {
			$blob .= "<tab>" . $this->text->makeChatcmd($row->professions, "/tell <myname> $command $row->professions");
			$blob .= "\n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob('Nanolines', $blob);

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("nanolines")
	 * @Matches("/^nanolines (.+)$/i")
	 */
	public function nanolinesListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->listNanolines($sendto, false, $args);
	}

	/**
	 * @HandlesCommand("nanolinesfroob")
	 * @Matches("/^nanolinesfroob (.+)$/i")
	 */
	public function nanolinesFroobListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$this->listNanolines($sendto, true, $args);
	}

	public function listNanolines(CommandReply $sendto, bool $froobOnly, array $args): void {
		$args[1] = html_entity_decode($args[1]);
		$nanoArgs = explode(" > ", $args[1]);
		$profArg = array_shift($nanoArgs);
		$profession = $this->util->getProfessionName($profArg);
		if (in_array($profArg, ["general", "General"])) {
			$profession = "General";
		}
		if ($profession === '') {
			$this->nanolinesShow($args[1], null, $froobOnly, $sendto);
		} elseif (count($nanoArgs)) {
			$this->nanolinesShow(implode(" > ", $nanoArgs), $profession, $froobOnly, $sendto);
		} else {
			$this->nanolinesList($profession, $froobOnly, $sendto);
		}
	}
	
	/**
	 * Show all nanos of a nanoline grouped by sub-strain
	 */
	private function nanolinesShow(string $nanoline, ?string $prof, bool $froobOnly, CommandReply $sendto): void {
		$profWhere = "";
		$froobWhere = "";
		$sqlArgs = [];
		if ($prof !== null) {
			$profWhere = "AND professions LIKE ? ";
			$sqlArgs []= "%$prof%";
		}
		if ($froobOnly) {
			if ($prof !== null && in_array($prof, ["Keeper", "Shade"])) {
				$msg = "<highlight>$prof<end> is not playable as froob.";
				$sendto->reply($msg);
				return;
			}
			$froobWhere = "AND froob_friendly IS TRUE ";
		}
		$sql = "SELECT *  ".
			"FROM nanos ".
			"WHERE strain = ? ".
			$profWhere.
			$froobWhere.
			"ORDER BY ".
				"sub_strain ASC, ".
				"ql DESC, ".
				"nano_cost DESC, ".
				"nano_name LIKE 'Improved%' DESC, ".
				"nano_name ASC ";
		/** @var Nano[] */
		$data = $this->db->fetchAll(Nano::class, $sql, $nanoline, ...$sqlArgs);
		if (!count($data)) {
			$msg = "No nanoline named <highlight>$nanoline<end> found.";
			if ($prof !== null) {
				$msg = "No nanoline named <highlight>$nanoline<end> found for <highlight>$prof<end>.";
			}
			$sendto->reply($msg);
			return;
		}

		$lastSubStrain = null;
		$blob = "<header2>{$data[0]->strain}<end>\n";
		foreach ($data as $nano) {
			if ($nano->sub_strain !== null && $nano->sub_strain !== '' && $nano->sub_strain !== $lastSubStrain) {
				$blob .= "\n<highlight>{$nano->sub_strain}<end>\n";
				$lastSubStrain = $nano->sub_strain;
			}
			$nanoLink = $this->makeNanoLink($nano);
			$crystalLink = $this->text->makeItem($nano->crystal_id, $nano->crystal_id, $nano->ql, "Crystal");
			$blob .= "<tab>" . $this->text->alignNumber($nano->ql, 3) . " [$crystalLink] $nanoLink ($nano->location)\n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("All $nano->strain Nanos", $blob);
		if ($prof !== null) {
			$msg = $this->text->makeBlob("All $nano->strain Nanos for $prof", $blob);
		}

		$sendto->reply($msg);
	}

	/**
	 * List all nanolines for a profession, grouped by school
	 *
	 * @param string $profession The full name of the profession
	 * @param bool   $froobOnly  If true, only show nanolines containing nanos a froob can use
	 * @param \Nadybot\Core\CommandReply $sendto Object to send the reply to
	 * @return void
	 */
	private function nanolinesList(string $profession, bool $froobOnly, CommandReply $sendto): void {
		$froobWhere = "";
		if ($froobOnly) {
			$froobWhere = "AND froob_friendly IS TRUE ";
			if ($profession !== null && in_array($profession, ["Keeper", "Shade"])) {
				$msg = "<highlight>$profession<end> is not playable as froob.";
				$sendto->reply($msg);
				return;
			}
		}
		$sql = "SELECT distinct school,strain,froob_friendly ".
		"FROM nanos ".
		"WHERE professions LIKE ? ".
		$froobWhere.
		"GROUP BY school,strain ".
		"ORDER BY ".
			"school ASC, ".
			"strain ASC";
		$data = $this->db->query($sql, "%${profession}%");

		$shortProf = $profession;
		if ($profession !== 'General') {
			$shortProf = $this->util->getProfessionAbbreviation($profession);
		}
		$blob = '';
		$lastSchool = null;
		$command = "nanolines";
		if ($froobWhere) {
			$command = "nanolinesfroob";
		}
		foreach ($data as $row) {
			$strain = $row->strain;
			if ($lastSchool === null || $lastSchool !== $row->school) {
				$blob .= "<pagebreak><header2>{$row->school}<end>\n";
				$lastSchool = $row->school;
			}
			$blob .= "<tab>" . $this->text->makeChatcmd($strain, "/tell <myname> $command $shortProf > $row->strain");
			$blob .= "\n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("$profession Nanolines", $blob);

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("nanoloc")
	 * @Matches("/^nanoloc$/i")
	 */
	public function nanolocListCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$data = $this->db->query(
			"SELECT location, count(location) AS count ".
			"FROM nanos ".
			"GROUP BY location ".
			"ORDER BY location ASC"
		);

		$blob = '';
		foreach ($data as $row) {
			$blob .= $this->text->makeChatcmd(
				$row->location,
				"/tell <myname> nanoloc $row->location"
			) . " ($row->count) \n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("Nano Locations", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("nanoloc")
	 * @Matches("/^nanoloc (.+)$/i")
	 */
	public function nanolocViewCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$location = $args[1];

		$sql = "SELECT * ".
			"FROM nanos ".
			"WHERE location LIKE ? ".
			"ORDER BY nano_name ASC";

		/** @var Nano[] */
		$nanos = $this->db->fetchAll(Nano::class, $sql, $location);

		$count = count($nanos);
		if ($count == 0) {
			$msg = "No nanos found.";
			$sendto->reply($msg);
			return;
		}
		$blob = '';
		foreach ($nanos as $nano) {
			$nanoLink = $this->makeNanoLink($nano);
			$crystalLink = $this->text->makeItem($nano->crystal_id, $nano->crystal_id, $nano->ql, "Crystal");
			$blob .= "QL" . $this->text->alignNumber($nano->ql, 3) . " [$crystalLink] $nanoLink";
			if ($nano->professions) {
				$blob .= " - <highlight>" . join("<end>, <highlight>", explode(":", $nano->professions)) . "<end>";
			}
			$blob .= "\n";
		}

		$msg = $this->text->makeBlob("Nanos for Location '$location' ($count)", $blob);
		$sendto->reply($msg);
	}

	private function getFooter(): string {
		return "\n\nNanos DB originally provided by Saavick & Lucier, now enhanced with AOIA+ data";
	}

	/**
	 * Creates a link to a nano - not a crystal
	 */
	public function makeNanoLink(Nano $nano): string {
		return "<a href='itemid://53019/{$nano->nano_id}'>{$nano->nano_name}</a>";
	}
}
