<?php declare(strict_types=1);

namespace Nadybot\Core;

use AO\Utils;
use Exception;
use Nadybot\Core\{
	Attributes as NCA,
	Config\BotConfig,
	DBSchema\Audit,
	Modules\ALTS\AltsController,
	Modules\SECURITY\AuditController,
	Types\AccessLevel,
	Types\AccessLevelProvider,
};
use Psr\Log\LoggerInterface;
use SplObjectStorage;

/**
 * The AccessLevel class provides functionality for checking a player's access level.
 */
#[NCA\Instance]
class AccessManager {
	/** Display name for the rank "superadmin" */
	#[NCA\Setting\Text]
	public string $rankNameSuperadmin = 'superadmin';

	/** Display name for the rank "admin" */
	#[NCA\Setting\Text]
	public string $rankNameAdmin = 'administrator';

	/** Display name for the rank "moderator" */
	#[NCA\Setting\Text]
	public string $rankNameMod = 'moderator';

	/** Display name for the rank "guild" */
	#[NCA\Setting\Text]
	public string $rankNameGuild = 'guild';

	/** Display name for the rank "member" */
	#[NCA\Setting\Text]
	public string $rankNameMember = 'member';

	/** Display name for the rank "guest" */
	#[NCA\Setting\Text]
	public string $rankNameGuest = 'guest';

	/** Display name for the temporary rank "raidleader" */
	#[NCA\Setting\Text]
	public string $rankNameRL = 'raidleader';

	#[NCA\Logger]
	private LoggerInterface $logger;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private AuditController $auditController;

	#[NCA\Inject]
	private AltsController $altsController;

	#[NCA\Inject]
	private BotConfig $config;

	/**
	 * A list of all AccessLevelProviders that are registered with the AccessManager,
	 * keyed by the provider object.
	 *
	 * @var SplObjectStorage<AccessLevelProvider,AccessLevelProvider>
	 */
	private SplObjectStorage $providers;

	public function __construct() {
		$this->providers = new SplObjectStorage();
	}

	/** Prevent configurable rank names to be identical */
	#[
		NCA\SettingChangeHandler('rank_name_superadmin'),
		NCA\SettingChangeHandler('rank_name_admin'),
		NCA\SettingChangeHandler('rank_name_mod'),
		NCA\SettingChangeHandler('rank_name_guild'),
		NCA\SettingChangeHandler('rank_name_member'),
		NCA\SettingChangeHandler('rank_name_guest'),
		NCA\SettingChangeHandler('rank_name_rl'),
	]
	public function preventRankNameDupes(string $setting, string $old, string $new): void {
		$new = strtolower($new);
		if (strtolower($this->rankNameSuperadmin) === $new
			|| strtolower($this->rankNameAdmin) === $new
			|| strtolower($this->rankNameMod) === $new
			|| strtolower($this->rankNameGuild) === $new
			|| strtolower($this->rankNameMember) === $new
			|| strtolower($this->rankNameGuest) === $new
			|| strtolower($this->rankNameRL) === $new
		) {
			throw new Exception("The display name <highlight>{$new}<end> is already used for another rank.");
		}
	}

	/**
	 * Registers the given AccessLevelProvider to be queried for the
	 * access level it provides every time we are calculating a character's
	 * access level.
	 */
	public function registerProvider(AccessLevelProvider $provider): void {
		$this->providers->attach($provider);
	}

	/**
	 * This method checks if given $sender has at least $accessLevel rights.
	 *
	 * Normally, you don't have to worry about access levels in the bot.
	 * The bot will automatically restrict access to commands based on the
	 * access level setting on the command and the access level of the user
	 * trying to access the command.
	 *
	 * However, there are some cases where you may need this functionality.
	 * For instance, you may have a command that displays the names of the last
	 * ten people to send a tell to the bot.  You may wish to display a "ban"
	 * link when a moderator or higher uses that command.
	 *
	 * To check if a character named 'Tyrence' has moderator access,
	 * you would do:
	 *
	 * ```php
	 * if ($this->accessManager->checkAccess("Tyrence", "moderator")) {
	 *    // Tyrence has [at least] moderator access level
	 * } else {
	 *    // Tyrence does not have moderator access level
	 * }
	 * ```
	 *
	 * Note that this will return true if 'Tyrence' is a moderator on your
	 * bot, but also if he is anything higher, such as administrator, or superadmin.
	 *
	 * This command will check the character's "effective" access level, meaning
	 * the higher of it's own access level and that of it's main, if it has a main
	 * and if it has been validated as an alt.
	 */
	public function checkAccess(string $sender, AccessLevel $accessLevel): bool {
		$this->logger->info(
			"Checking access level '{checkLevel}' against character '{sender}'",
			[
				'checkLevel' => $accessLevel,
				'sender' => $sender,
			]
		);

		$returnVal = $this->checkSingleAccess($sender, $accessLevel);

		if ($returnVal === false) {
			// if current character doesn't have access,
			// and if the current character is not a main character,
			// and if the current character is validated,
			// then check access against the main character,
			// otherwise just return the result
			$altInfo = $this->altsController->getAltInfo($sender);
			if ($sender !== $altInfo->main && $altInfo->isValidated($sender)) {
				$this->logger->info(
					"Checking access level '{accessLevel}' against the main of '{sender}' which is '{main}'",
					[
						'accessLevel' => $accessLevel,
						'sender' => $sender,
						'main' => $altInfo->main,
					]
				);
				$returnVal = $this->checkSingleAccess($altInfo->main, $accessLevel);
			}
		}

		return $returnVal;
	}

	/**
	 * This method checks if given `$sender` has at least `$accessLevel` rights.
	 *
	 * This is the same `checkAccess()` but doesn't check alts
	 */
	public function checkSingleAccess(string $sender, AccessLevel $accessLevel): bool {
		$sender = Utils::normalizeCharacter($sender);

		$charAccessLevel = $this->getSingleAccessLevel($sender);
		return $charAccessLevel->atLeast($accessLevel);
	}

	/** Returns the access level of `$sender`, ignoring guild admin and inheriting access level from main */
	public function getSingleAccessLevel(string $sender): AccessLevel {
		if (in_array($sender, $this->config->general->superAdmins, true)) {
			return AccessLevel::Superadmin;
		} elseif (!count($this->config->general->superAdmins) && $sender === '<no superadmin set>') {
			return AccessLevel::Superadmin;
		}

		/** @var array<int,AccessLevel> */
		$ranks = [];
		foreach ($this->providers as $provider) {
			$rank = $provider->getSingleAccessLevel($sender);
			if (isset($rank)) {
				$ranks[$rank->toInt()] = $rank;
			}
		}
		if (!count($ranks)) {
			return AccessLevel::All;
		}
		ksort($ranks);

		return array_shift($ranks);
	}

	/**
	 * Returns the access level of `$sender`,
	 * accounting for guild admin and inheriting access level from main
	 */
	public function getAccessLevelForCharacter(string $sender): AccessLevel {
		$sender = Utils::normalizeCharacter($sender);

		$accessLevel = $this->getSingleAccessLevel($sender);

		$altInfo = $this->altsController->getAltInfo($sender);
		if ($sender !== $altInfo->main && $altInfo->isValidated($sender)) {
			$mainAccessLevel = $this->getSingleAccessLevel($altInfo->main);
			if ($mainAccessLevel->higherThan($accessLevel)) {
				$accessLevel = $mainAccessLevel;
			}
		}

		return $accessLevel;
	}

	/**
	 * Compare the access levels of 2 characters, taking alts into account
	 *
	 * @return int 1 if the access level of $char1 is greater than the access level of $char2,
	 *             -1 if the access level of $char1 is less than the access level of $char2,
	 *             0 if the access levels of $char1 and $char2 are equal.
	 */
	public function compareCharacterAccessLevels(string $char1, string $char2): int {
		$char1AccessLevel = $this->getAccessLevelForCharacter($char1);
		$char2AccessLevel = $this->getAccessLevelForCharacter($char2);

		return $char1AccessLevel->compare($char2AccessLevel);
	}

	/** Log the given audit entry, if auditing is allowed */
	public function addAudit(Audit $audit): void {
		if (!$this->auditController->auditEnabled) {
			return;
		}
		if (isset($audit->value) && in_array($audit->action, [AuditAction::AddRank, AuditAction::DelRank], true)) {
			$toLevel = AccessLevel::fromInt((int)$audit->value);
			$audit->value = $audit->value . " ({$toLevel->displayName()})";
		}
		$this->db->insert($audit);
	}
}
