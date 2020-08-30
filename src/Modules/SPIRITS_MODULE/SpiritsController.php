<?php declare(strict_types=1);

namespace Nadybot\Modules\SPIRITS_MODULE;

use Nadybot\Core\{
	CommandReply,
	DB,
	Text,
};
use Nadybot\Modules\ITEMS_MODULE\AODBEntry;

/**
 * @author Tyrence (RK2)
 *
 * Originally Written for Budabot By Jaqueme
 * Database Adapted From One Originally Compiled by Wolfbiter For BeBot
 *
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'spirits',
 *		accessLevel = 'all',
 *		description = 'Search for spirits',
 *		help        = 'spirits.txt'
 *	)
 */
class SpiritsController {

	/**
	 * Name of the module.
	 * Set automatically by module loader.
	 */
	public string $moduleName;
	
	/** @Inject */
	public DB $db;
	
	/** @Inject */
	public Text $text;
	
	/**
	 * @Setup
	 */
	public function setup(): void {
		$this->db->loadSQLFile($this->moduleName, 'spirits');
	}

	/**
	 * @HandlesCommand("spirits")
	 * @Matches("/^spirits (.+)$/i")
	 */
	public function spiritsCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$spirits = "";
		if (preg_match("/^spirits ([^0-9,]+)$/i", $message, $arr)) {
			$name = $arr[1];
			$name = ucwords(strtolower($name));
			$title = "Spirits Database for $name";
			/** @var Spirit[] */
			$data = $this->db->fetchAll(
				Spirit::class,
				"SELECT * FROM spiritsdb WHERE name LIKE ? OR spot LIKE ? ORDER BY level",
				'%'.$name.'%',
				'%'.$name.'%'
			);
			if (count($data) === 0) {
				$spirits .= "There were no matches found for <highlight>$name<end>.\n".
					"Try putting a comma between search values.\n\n";
				$spirits .= $this->getValidSlotTypes();
			} else {
				$spirits .= $this->formatSpiritOutput($data);
			}
			//If searched by name and slot
		} elseif (preg_match("/^spirits ([^0-9]+),([^0-9]+)$/i", $message, $arr)) {
			if (preg_match("/(chest|ear|eye|feet|head|larm|legs|lhand|lwrist|rarm|rhand|rwrist|waist)/i", $arr[1])) {
				$slot = $arr[1];
				$name = $arr[2];
				$title = "Spirits Database for $name $slot";
			} elseif (preg_match("/(chest|ear|eye|feet|head|larm|legs|lhand|lwrist|rarm|rhand|rwrist|waist)/i", $arr[2])) {
				$name = $arr[1];
				$slot = $arr[2];
				$title = "Spirits Database for $name $slot";
			} else {
				$name = $arr[1];
				$slot = $arr[2];
				$spirits .= "No matches were found for <highlight>$name $slot<highlight>.\n\n";
				$spirits .= $this->getValidSlotTypes();
			}
			$name = ucwords(strtolower($name));
			$name = trim($name);
			$slot = ucwords(strtolower($slot));
			$slot = trim($slot);
			/** @var Spirit[] */
			$data = $this->db->fetchAll(
				Spirit::class,
				"SELECT * FROM spiritsdb WHERE name LIKE ? AND spot = ? ORDER BY level",
				'%'.$name.'%',
				$slot
			);
			$spirits .= $this->formatSpiritOutput($data);
			// If searched by ql
		} elseif (preg_match("/^spirits ([0-9]+)$/i", $message, $arr)) {
			$ql = (int)$arr[1];
			if ($ql < 1 or $ql > 300) {
				$msg = "Invalid QL specified.";
				$sendto->reply($msg);
				return;
			}
			$title = "Spirits QL $ql";
			/** @var Spirit[] */
			$data = $this->db->fetchAll(
				Spirit::class,
				"SELECT * FROM spiritsdb where ql = ? ORDER BY ql",
				$ql
			);
			$spirits .= $this->formatSpiritOutput($data);
			// If searched by ql range
		} elseif (preg_match("/^spirits ([0-9]+)-([0-9]+)$/i", $message, $arr)) {
			$qllorange = (int)$arr[1];
			$qlhirange = (int)$arr[2];
			if ($qllorange < 1 or $qlhirange > 300 or $qllorange >= $qlhirange) {
				$msg = "Invalid Ql range specified.";
				$sendto->reply($msg);
				return;
			}
			$title = "Spirits QL $qllorange to $qlhirange";
			/** @var Spirit[] */
			$data = $this->db->fetchAll(
				Spirirt::class,
				"SELECT * FROM spiritsdb where ql >= ? AND ql <= ? ORDER BY ql",
				$qllorange,
				$qlhirange
			);
			$spirits .= $this->formatSpiritOutput($data);
			// If searched by ql and slot
		} elseif (preg_match("/^spirits ([0-9]+) (.+)$/i", $message, $arr)) {
			$ql = (int)$arr[1];
			$slot = ucwords(strtolower($arr[2]));
			$title = "$slot Spirits QL $ql";
			if ($ql < 1 or $ql > 300) {
				$msg = "Invalid Ql specified.";
				$sendto->reply($msg);
				return;
			} elseif (preg_match("/[^chest|ear|eye|feet|head|larm|legs|lhand|lwrist|rarm|rhand|rwrist|waist]/i", $slot)) {
				$spirits .= "Invalid Input\n\n";
				$spirits .= $this->getValidSlotTypes();
			} else {
				/** @var Spirit[] */
				$data = $this->db->fetchAll(
					Spirit::class,
					"SELECT * FROM spiritsdb where spot = ? AND ql = ? ORDER BY ql",
					$slot,
					$ql
				);
				$spirits .= $this->formatSpiritOutput($data);
			}
			// If searched by ql range and slot
		} elseif (preg_match("/^spirits (\d+)-(\d+) (.+)$/i", $message, $arr)) {
			$qllorange = (int)$arr[1];
			$qlhirange = (int)$arr[2];
			$slot = ucwords(strtolower($arr[3]));
			$title = "$slot Spirits QL $qllorange to $qlhirange";
			if ($qllorange < 1 or $qlhirange > 300 or $qllorange >= $qlhirange) {
				$msg = "Invalid Ql range specified.";
				$sendto->reply($msg);
				return;
			} elseif (preg_match("/[^chest|ear|eye|feet|head|larm|legs|lhand|lwrist|rarm|rhand|rwrist|waist]/i", $slot)) {
				$spirits .= "Invalid Input\n\n";
				$spirits .= $this->getValidSlotTypes();
			} else {
				/** @var Spirit[] */
				$data = $this->db->fetchAll(
					Spirit::class,
					"SELECT * FROM spiritsdb where spot = ? AND ql >= ? AND ql <= ? ORDER BY ql",
					$slot,
					$qllorange,
					$qlhirange
				);
				$spirits .= $this->formatSpiritOutput($data);
			}
		}
		if ($spirits) {
			$spirits = $this->text->makeBlob("Spirits", $spirits, $title);
			$sendto->reply($spirits);
		}
	}

	/**
	 * @param Spirit[] $spirits
	 * @return string
	 */
	public function formatSpiritOutput(array $spirits): string {
		if (count($spirits) === 0) {
			return "No matches found.";
		}

		$msg = '';
		foreach ($spirits as $spirit) {
			/** @var ?AODBEntry */
			$dbSpirit = $this->db->fetch(
				AODBEntry::class,
				"SELECT * FROM aodb WHERE lowid = ? ".
				"UNION ".
				"SELECT * FROM aodb WHERE highid = ? ".
				"LIMIT 1 ",
				$spirit->id,
				$spirit->id
			);
			if ($dbSpirit) {
				$msg .= $this->text->makeImage($dbSpirit->icon) . ' ';
				$msg .= $this->text->makeItem($dbSpirit->lowid, $dbSpirit->highid, $dbSpirit->highql, $dbSpirit->name) . "\n";
				$msg .= "Minimum Level=$spirit->level   Slot=$spirit->spot   Agility/Sense Needed=$spirit->agility\n\n";
			}
		}
		return $msg;
	}

	public function getValidSlotTypes(): string {
		$output = "<header2>Valid slots for spirits<end>\n";
		$output .= "<tab>Head\n";
		$output .= "<tab>Eye\n";
		$output .= "<tab>Ear\n";
		$output .= "<tab>Chest\n";
		$output .= "<tab>Larm\n";
		$output .= "<tab>Rarm\n";
		$output .= "<tab>Waist\n";
		$output .= "<tab>Lwrist\n";
		$output .= "<tab>Rwrist\n";
		$output .= "<tab>Legs\n";
		$output .= "<tab>Lhand\n";
		$output .= "<tab>Rhand\n";
		$output .= "<tab>Feet\n";

		return $output;
	}
}
