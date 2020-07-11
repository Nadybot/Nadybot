<?php

namespace Budabot\Modules\NANO_MODULE;

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
	public $moduleName;
	
	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;
	
	/**
	 * @var \Budabot\Core\SettingManager $settingManager
	 * @Inject
	 */
	public $settingManager;
	
	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;
	
	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, 'nanos');
		
		$this->settingManager->add($this->moduleName, 'maxnano', 'Number of Nanos shown on the list', 'edit', "number", '40', '30;40;50;60', "", "mod");
		$this->settingManager->add($this->moduleName, "shownanolineicons", "Show icons for the nanolines", "edit", "options", "0", "true;false", "1;0");
	}

	/**
	 * @HandlesCommand("nano")
	 * @Matches("/^nano (.+)$/i")
	 */
	public function nanoCommand($message, $channel, $sender, $sendto, $args) {
		$search = $args[1];

		$search = htmlspecialchars_decode($search);
		$tmp = explode(" ", $search);
		list($query, $params) = $this->util->generateQueryFromParams($tmp, '`nano_name`');
		array_push($params, intval($this->settingManager->get("maxnano")));

		$sql =
			"SELECT
				crystal_id,
				nano_id,
				ql,
				crystal_name,
				nano_name,
				location,
				professions,
				school,
				strain AS nanoline_name,
				sub_strain
			FROM
				nanos
			WHERE
				$query
			ORDER BY
				strain ASC,
				sub_strain ASC,
				ql DESC,
				nano_cost DESC,
				nano_name LIKE 'Improved%' DESC,
				nano_name ASC
			LIMIT
				?";

		$data = $this->db->query($sql, $params);

		$count = count($data);
		if ($count == 0) {
			$msg = "No nanos found.";
		} else {
			$blob = '';
			$currentNanoline = -1;
			$currentSubstrain = null;
			foreach ($data as $row) {
				if ($currentNanoline !== $row->nanoline_name || $currentSubstrain !== $row->sub_strain) {
					if (!empty($row->nanoline_name)) {
						$nanolineLink = $this->text->makeChatcmd("see all nanos", "/tell <myname> nanolines $row->nanoline_name");
						$blob .= "\n<header2>$row->school<end> &gt; <header2>$row->nanoline_name<end>";
						if ($row->sub_strain) {
							$blob .= " &gt; <header2>$row->sub_strain<end>";
						}
						$blob .= " - [$nanolineLink]\n";
					} else {
						$blob .= "\n<header2>Unknown/General<end>\n";
					}
					$currentNanoline = $row->nanoline_name;
					$currentSubstrain = $row->sub_strain;
				}
				$nanoLink = $this->makeNano($row->nano_id, $row->nano_name);
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
		}

		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("nanolines")
	 * @Matches("/^nanolines$/i")
	 */
	public function nanolinesListProfsCommand($message, $channel, $sender, $sendto, $args) {
		$this->listNanolineProfs($sendto, false);
	}

	/**
	 * @HandlesCommand("nanolinesfroob")
	 * @Matches("/^nanolinesfroob$/i")
	 */
	public function nanolinesFroobListProfsCommand($message, $channel, $sender, $sendto, $args) {
		$this->listNanolineProfs($sendto, true);
	}

	/**
	 * List all professions for which nanolines exist
	 *
	 * @param \Budabot\Core\CommandReply $sendto Where to send the reply to
	 * @param bool $froobObly Is set, only show professions a froob can play
	 * @return void
	 */
	public function listNanolineProfs($sendto, $froobOnly) {
		$froobWhere = "";
		if ($froobOnly) {
			$froobWhere = "AND professions NOT IN ('Keeper', 'Shade')";
		}
		$sql = "SELECT
			DISTINCT professions
			FROM
				nanos
			WHERE
				professions NOT LIKE '%:%'
				$froobWhere
			ORDER BY
				professions ASC";
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
	public function nanolinesListCommand($message, $channel, $sender, $sendto, $args) {
		$this->listNanolines($sendto, false, $args);
	}

	/**
	 * @HandlesCommand("nanolinesfroob")
	 * @Matches("/^nanolinesfroob (.+)$/i")
	 */
	public function nanolinesFroobListCommand($message, $channel, $sender, $sendto, $args) {
		$this->listNanolines($sendto, true, $args);
	}

	public function listNanolines($sendto, $froobOnly, $args) {
		$args[1] = html_entity_decode($args[1]);
		$nanoArgs = explode(" > ", $args[1]);
		$profArg = array_shift($nanoArgs);
		$profession = $this->util->getProfessionName($profArg);
		if (in_array($profArg, array("general", "General"))) {
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
	 *
	 * @param string $nanoline   The full name of the nanoline to show
	 * @param string $prof       If set, only show nanos that profession can use
	 * @param bool   $froobOnly  If true, only show nanos a froob can use
	 * @param \Budabot\Core\CommandReply $sendto Object to send the reply to
	 * @return void
	 */
	private function nanolinesShow($nanoline, $prof, $froobOnly, $sendto) {
		$profWhere = "";
		$froobWhere = "";
		if ($prof !== null) {
			$profWhere = "AND professions LIKE ?";
		}
		if ($froobOnly) {
			if ($prof !== null && in_array($prof, array("Keeper", "Shade"))) {
				$msg = "<highlight>$prof<end> is not playable as froob.";
				$sendto->reply($msg);
				return;
			}
			$froobWhere = "AND froob_friendly IS TRUE";
		}
		$sql = "SELECT * 
			FROM
				nanos
			WHERE
				strain = ?
			$profWhere
			$froobWhere
			ORDER BY
				sub_strain ASC,
				ql DESC,
				nano_cost DESC,
				nano_name LIKE 'Improved%' DESC,
				nano_name ASC
		";
		if ($prof !== null) {
			$data = $this->db->query($sql, $nanoline, "%$prof%");
		} else {
			$data = $this->db->query($sql, $nanoline);
		}
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
			$nanoLink = $this->makeNano($nano->nano_id, $nano->nano_name);
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
	 * @param \Budabot\Core\CommandReply $sendto Object to send the reply to
	 * @return void
	 */
	private function nanolinesList($profession, $froobOnly, $sendto) {
		$froobWhere = "";
		if ($froobOnly) {
			$froobWhere = "AND froob_friendly IS TRUE";
			if ($profession !== null && in_array($profession, array("Keeper", "Shade"))) {
				$msg = "<highlight>$profession<end> is not playable as froob.";
				$sendto->reply($msg);
				return;
			}
		}
		$sql = "SELECT
			distinct school,strain
		FROM
			nanos
		WHERE
			professions LIKE ?
		$froobWhere
		GROUP BY
			school,strain
		ORDER BY
			school ASC,
			strain ASC";
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
	public function nanolocListCommand($message, $channel, $sender, $sendto, $args) {
		$data = $this->db->query("SELECT location, count(location) AS count FROM nanos GROUP BY location ORDER BY location ASC");

		$blob = '';
		foreach ($data as $row) {
			$blob .= $this->text->makeChatcmd($row->location, "/tell <myname> nanoloc $row->location") . " ($row->count) \n";
		}
		$blob .= $this->getFooter();
		$msg = $this->text->makeBlob("Nano Locations", $blob);
		$sendto->reply($msg);
	}
	
	/**
	 * @HandlesCommand("nanoloc")
	 * @Matches("/^nanoloc (.+)$/i")
	 */
	public function nanolocViewCommand($message, $channel, $sender, $sendto, $args) {
		$location = $args[1];

		$sql =
			"SELECT
				crystal_id,
				nano_id,
				ql,
				nano_name,
				crystal_name,
				location,
				professions
			FROM
				nanos
			WHERE
				location LIKE ?
			ORDER BY
				nano_name ASC";

		$data = $this->db->query($sql, $location);

		$count = count($data);
		if ($count == 0) {
			$msg = "No nanos found.";
		} else {
			$blob = '';
			foreach ($data as $row) {
				$nanoLink = $this->makeNano($row->nano_id, $row->nano_name);
				$crystalLink = $this->text->makeItem($row->crystal_id, $row->crystal_id, $row->ql, "Crystal");
				$blob .= "QL" . $this->text->alignNumber($row->ql, 3) . " [$crystalLink] $nanoLink";
				if ($row->professions) {
					$blob .= " - <highlight>" . join("<end>, <highlight>", explode(":", $row->professions)) . "<end>";
				}
				$blob .= "\n";
			}

			$msg = $this->text->makeBlob("Nanos for Location '$location' ($count)", $blob);
		}

		$sendto->reply($msg);
	}

	private function getFooter() {
		return "\n\nNanos DB originally provided by Saavick & Lucier";
	}

	/**
	 * Creates a link to a nano - not a crystal
	 *
	 * @param  int    $id   The ID of the nano
	 * @param  string $name The name of the name to display
	 * @return string       A link to the nano
	 */
	public function makeNano($id, $name) {
		return "<a href='itemid://53019/${id}'>${name}</a>";
	}
}
