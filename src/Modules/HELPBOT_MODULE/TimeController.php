<?php declare(strict_types=1);

namespace Nadybot\Modules\HELPBOT_MODULE;

use DateTime;
use Nadybot\Core\{
	Attributes as NCA,
	CmdContext,
	ModuleInstance,
	Text,
	Util,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\DefineCommand(
		command: "time",
		accessLevel: "guest",
		description: "Show the time in the different timezones",
	)
]
class TimeController extends ModuleInstance {
	#[NCA\Inject]
	private Util $util;

	#[NCA\Inject]
	private Text $text;

	/** Show the current time in a list of time zones */
	#[NCA\HandlesCommand("time")]
	public function timeListCommand(CmdContext $context): void {
		$link  = "<header2>Australia<end>\n";
		$link .= "<tab><highlight>Western Australia<end>\n";
		$link .= "<tab><tab>Standard Time (AWST = GMT +8:00): " . $this->safeGetTimezone("AWST")->time . "\n";
		$link .= "<tab><highlight>Northern Territory/South Australia<end>\n";
		$link .= "<tab><tab>Standard Time (ACST = UTC+9:30): " . $this->safeGetTimezone("ACST")->time . "\n";
		$link .= "<tab><tab>Summer Time (ACDT = UTC+10:30): " . $this->safeGetTimezone("ACDT")->time . "\n";
		$link .= "<tab><highlight>Queensland/Victoria/Tasmania<end>\n";
		$link .= "<tab><tab>Standard Time (AEST = UTC+10): " . $this->safeGetTimezone("AEST")->time . "\n";
		$link .= "<tab><tab>Summer Time (AEDT = UTC+11): " . $this->safeGetTimezone("AEDT")->time . "\n\n";

		$link .= "<header2>Asia<end>\n";
		$link .= "<tab><highlight>Thailand/Vietnam/Kambodscha<end>\n";
		$link .= "<tab><tab>Standard Time (ICT = UTC+7): " . $this->safeGetTimezone("ICT")->time . "\n";
		$link .= "<tab><highlight>China/Malaysia/Singapur/Indonesien<end>\n";
		$link .= "<tab><tab>Standard Time (CST = UTC+8): " . $this->safeGetTimezone("CCST")->time . "\n";
		$link .= "<tab><highlight>Japan/Korea<end>\n";
		$link .= "<tab><tab>Standard Time (JST = UTC+9): " . $this->safeGetTimezone("JST")->time . "\n\n";

		$link .= "<header2>Europe<end>\n";
		$link .= "<tab><highlight>England,Spain,Portugal<end>\n";
		$link .= "<tab><tab>Standard Time (GMT = UTC): " . $this->safeGetTimezone("GMT")->time . "\n";
		$link .= "<tab><tab>Summer Time (BST = UTC+1): " . $this->safeGetTimezone("BST")->time . "\n";
		$link .= "<tab><highlight>Germany/France/Netherlands/Italy/Norway<end>\n";
		$link .= "<tab><tab>Standard Time (CET = UTC+1): " . $this->safeGetTimezone("CET")->time . "\n";
		$link .= "<tab><tab>Summer Time (CEST = UTC+2): " . $this->safeGetTimezone("CEST")->time . "\n";
		$link .= "<tab><highlight>Egypt/Bulgary/Finland/Greece<end>\n";
		$link .= "<tab><tab>Standard Time (EET = UTC+2): " . $this->safeGetTimezone("EET")->time . "\n";
		$link .= "<tab><tab>Summer Time (EEST/EEDT = UTC+3): " . $this->safeGetTimezone("EEST")->time . "\n";
		$link .= "<tab><highlight>Bahrain/Iraq/Russia/Saudi Arabia<end>\n";
		$link .= "<tab><tab>Standard Time (MSK = UTC+3): " . $this->safeGetTimezone("MSK")->time . "\n";
		$link .= "<tab><tab>Summer Time (MSD = UTC+4): " . $this->safeGetTimezone("MSD")->time . "\n\n";

		$link .= "<header2>West Asia<end>\n";
		$link .= "<tab><highlight>India (UTC+5:30)<end>: " . $this->safeGetTimezone("IST")->time . "\n";
		$link .= "<tab><highlight>Iran (UTC+3:30)<end>: " . $this->safeGetTimezone("IRT")->time . "\n\n";

		$link .= "<header2>North America<end>\n";
		$link .= "<tab><highlight>Newfoundland<end>\n";
		$link .= "<tab><tab>Standard Time (NST = GMT-3:30): " . $this->safeGetTimezone("NST")->time . "\n";
		$link .= "<tab><tab>Summer Time (NDT = GMT-2:30): " . $this->safeGetTimezone("NDT")->time . "\n";
		$link .= "<tab><highlight>Toronto<end>\n";
		$link .= "<tab><tab>Standard Time (EDT = GMT-4): " . $this->safeGetTimezone("EDT")->time . "\n";
		$link .= "<tab><tab>Summer Time (AST = GMT-3): " . $this->safeGetTimezone("AST")->time . "\n";
		$link .= "<tab><highlight>Florida/Indiana/New York/Maine/New Jersey/Washington D.C./Winnipeg<end>\n";
		$link .= "<tab><tab>Standard Time (EST = GMT-5): " . $this->safeGetTimezone("EST")->time . "\n";
		$link .= "<tab><tab>Summer Time (EDT = GMT-4): " . $this->safeGetTimezone("EDT")->time . "\n";
		$link .= "<tab><highlight>Alaska<end>\n";
		$link .= "<tab><tab>Standard Time (AKST = GMT-9): " . $this->safeGetTimezone("AKST")->time . "\n";
		$link .= "<tab><tab>Summer Time (AKDT = GMT-8): " . $this->safeGetTimezone("AKDT")->time . "\n";
		$link .= "<tab><highlight>California/Nevada/Washington<end>\n";
		$link .= "<tab><tab>Standard Time (PST = GMT-8): " . $this->safeGetTimezone("PST")->time . "\n";
		$link .= "<tab><tab>Summer Time (PDT = GMT-7): " . $this->safeGetTimezone("PDT")->time . "\n";
		$link .= "<tab><highlight>Colorado/Montana/New Mexico/Utah/Vancouver<end>\n";
		$link .= "<tab><tab>Standard Time (MST = GMT-7): " . $this->safeGetTimezone("MST")->time . "\n";
		$link .= "<tab><tab>Summer Time (MDT = GMT-6): " . $this->safeGetTimezone("MDT")->time . "\n";
		$link .= "<tab><highlight>Alabama/Illinois/Iowa/Michigan/Minnesota/Oklahoma/Edmonton<end>\n";
		$link .= "<tab><tab>Standard Time (CST = GMT-6): " . $this->safeGetTimezone("CST")->time . "\n";
		$link .= "<tab><tab>Summer Time (CDT = GMT-5): " . $this->safeGetTimezone("CDT")->time . "\n\n";

		$link .= "<header2>Unix time<end>\n";
		$link .= "<tab><tab>" . time() . "\n";

		$msg = "<highlight>".$this->util->date(time())."<end>";
		$context->reply($this->text->blobWrap(
			"{$msg} ",
			$this->text->makeBlob("All Timezones", $link)
		));
	}

	/** Show the current time in a given time zones */
	#[NCA\HandlesCommand("time")]
	#[NCA\Help\Example("<symbol>time MST")]
	#[NCA\Help\Example("<symbol>time CET")]
	public function timeShowCommand(CmdContext $context, string $timeZone): void {
		$timeZone = strtoupper($timeZone);
		$timeZone = $this->getTimezone($timeZone);
		if ($timeZone !== null) {
			$msg = "{$timeZone->name} is <highlight>{$timeZone->time}<end>";
		} else {
			$msg = "Unknown timezone.";
		}

		$context->reply($msg);
	}

	public function safeGetTimezone(string $tz): Timezone {
		$obj = $this->getTimezone($tz);
		if (isset($obj)) {
			return $obj;
		}
		$obj = new Timezone();
		$obj->name = $tz;
		$obj->offset = 0;
		$obj->time = "&lt;unknown&gt;";
		return $obj;
	}

	public function getTimezone(string $tz): ?Timezone {
		$date = new DateTime();
		$time = time() - $date->getOffset();
		$time_format = "F j, Y, H:i";

		switch ($tz) {
			case "CST":
				$name = "Central Standard Time (GMT-6)";
				$offset = -(3600*6);
				break;
			case "CDT":
				$name = "Central Daylight Time (GMT-5)";
				$offset = -(3600*5);
				break;
			case "MST":
				$name = "Mountain Standard Time (GMT-7)";
				$offset = -(3600*7);
				break;
			case "MDT":
				$name = "Mountain Daylight Time (GMT-6)";
				$offset = -(3600*6);
				break;
			case "PST":
				$name = "Pacific Standard Time (GMT-8)";
				$offset = -(3600*8);
				break;
			case "PDT":
				$name = "Pacific Daylight Time (GMT-7)";
				$offset = -(3600*7);
				break;
			case "AKST":
				$name = "Alaska Standard Time (GMT-9)";
				$offset = -(3600*9);
				break;
			case "AKDT":
				$name = "Alaska Daylight Time (GMT-8)";
				$offset = -(3600*8);
				break;
			case "AST":
				$name = "Atlantic Standard Time (GMT-6)";
				$offset = -(3600*6);
				break;
			case "EST":
				$name = "Eastern Standard Time (GMT-5)";
				$offset = -(3600*5);
				break;
			case "EDT":
				$name = "Eastern Daylight Time (GMT-4)";
				$offset = -(3600*4);
				break;
			case "NST":
				$name = "Newfoundland Standard Time (GMT-3:30)";
				$offset = -(3600*3.5);
				break;
			case "NDT":
				$name = "Newfoundland Daylight Time (GMT-2:30)";
				$offset = -(3600*2.5);
				break;
			case "UTC":
			case "GMT":
				$name = "Greenwich Mean Time (GMT / AO)";
				$offset = 0;
				break;
			case "BST":
				$name = "British Summer Time (UTC+1)";
				$offset = 3600;
				break;
			case "CET":
				$name = "Central European Time (UTC+1)";
				$offset = 3600;
				break;
			case "CEST":
				$name = "Central European Summer Time (UTC+2)";
				$offset = 3600*2;
				break;
			case "EET":
				$name = "Eastern European Time (UTC+2)";
				$offset = 3600*2;
				break;
			case "EEST":
				$name = "Eastern European Summer Time (UTC+3)";
				$offset = 3600*3;
				break;
			case "EEDT":
				$name = "Eastern European Daylight Time (UTC+3)";
				$offset = 3600*3;
				break;
			case "MSK":
				$name = "Moscow Time (UTC+3)";
				$offset = 3600*3;
				break;
			case "MSD":
				$name = "Moscow Daylight Time (UTC+4)";
				$offset = 3600*4;
				break;
			case "IRT":
				$name = "Iran Time (UTC+3:30)";
				$offset = 3600*3.5;
				break;
			case "IST":
				$name = "Indian Standard Time (UTC+5:30)";
				$offset = 3600*5.5;
				break;
			case "ICT":
				$name = "Indochina Time (UTC+7)";
				$offset = 3600*7;
				break;
			case "CCST":
				$name = "China Standard Time (UTC+8)";
				$offset = 3600*8;
				break;
			case "JST":
				$name = "Japan Standard Time (UTC+9)";
				$offset = 3600*9;
				break;
			case "AWST":
				$name = "Australian Western Standard Time (UTC+8)";
				$offset = 3600*8;
				break;
			case "ACST":
				$name = "Australian Central Standard Time (UTC+9:30)";
				$offset = 3600*9.5;
				break;
			case "ACDT":
				$name = "Australian Central Daylight Time (UTC+10:30)";
				$offset = 3600*10.5;
				break;
			case "AEST":
				$name = "Australian Eastern Standard Time (UTC+10)";
				$offset = 3600*10;
				break;
			case "AEDT":
				$name = "Australian Eastern Daylight Time (UTC+11)";
				$offset = 3600*11;
				break;
			default:
				return null;
		}

		$obj = new Timezone();
		$obj->name = $name;
		$obj->offset = $offset;
		$obj->time = \Safe\date($time_format, (int)($time + $offset));
		return $obj;
	}
}
