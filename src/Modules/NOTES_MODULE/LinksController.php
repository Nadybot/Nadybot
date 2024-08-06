<?php declare(strict_types=1);

namespace Nadybot\Modules\NOTES_MODULE;

use InvalidArgumentException;
use Nadybot\Core\Config\BotConfig;
use Nadybot\Core\ParamClass\PUuid;
use Nadybot\Core\{
	AccessManager,
	Attributes as NCA,
	CmdContext,
	DB,
	ExportCharacter,
	ExporterInterface,
	ImporterInterface,
	ModuleInstance,
	ParamClass\PRemove,
	ParamClass\PWord,
	Text,
};
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations('Migrations/Links'),
	NCA\Exporter('links'),
	NCA\Importer('links', ExportLink::class),
	NCA\DefineCommand(
		command: 'links',
		accessLevel: 'guild',
		description: 'Displays, adds, or removes links from the org link list',
	),
]
class LinksController extends ModuleInstance implements ImporterInterface, ExporterInterface {
	/** Enable full urls in the link list output */
	#[NCA\Setting\Boolean]
	public bool $showfullurls = false;

	#[NCA\Inject]
	private DB $db;

	#[NCA\Inject]
	private Text $text;

	#[NCA\Inject]
	private BotConfig $config;

	#[NCA\Inject]
	private AccessManager $accessManager;

	/** Show all links */
	#[NCA\HandlesCommand('links')]
	public function linksListCommand(CmdContext $context): void {
		$links = $this->db->table(Link::getTable())
			->orderBy('name')
			->asObj(Link::class);
		if ($links->count() === 0) {
			$msg = 'No links found.';
			$context->reply($msg);
			return;
		}

		$blob = "<header2>All my links<end>\n";
		foreach ($links as $link) {
			$remove = Text::makeChatcmd('remove', "/tell <myname> links rem {$link->id}");
			if ($this->showfullurls) {
				$website = Text::makeChatcmd($link->website, "/start {$link->website}");
			} else {
				$website = '[' . Text::makeChatcmd('visit', "/start {$link->website}") . ']';
			}
			$blob .= "<tab>{$website} <highlight>{$link->comments}<end> (by {$link->name}) [{$remove}]\n";
		}

		$msg = $this->text->makeBlob('Links', $blob);
		$context->reply($msg);
	}

	/** Add a link to the list */
	#[NCA\HandlesCommand('links')]
	public function linksAddCommand(CmdContext $context, #[NCA\Str('add')] string $action, PWord $url, string $comments): void {
		$website = htmlspecialchars($url());
		if (filter_var($website, \FILTER_VALIDATE_URL) === false) {
			$msg = "<highlight>{$website}<end> is not a valid URL.";
			$context->reply($msg);
			return;
		}

		$this->db->insert(new Link(
			name: $context->char->name,
			website: $website,
			comments: $comments,
			dt: time(),
		));
		$msg = 'Link added successfully.';
		$context->reply($msg);
	}

	/** Remove a link from the list */
	#[NCA\HandlesCommand('links')]
	public function linksRemoveCommand(CmdContext $context, PRemove $action, PUuid $id): void {
		$id = $id();

		/** @var ?Link */
		$obj = $this->db->table(Link::getTable())
			->where('id', $id)
			->asObj(Link::class)
			->first();
		if ($obj === null) {
			$msg = "Link with ID <highlight>{$id}<end> could not be found.";
		} elseif ($obj->name === $context->char->name
			|| $this->accessManager->compareCharacterAccessLevels($context->char->name, $obj->name) > 0) {
			$this->db->table(Link::getTable())->delete($id);
			$msg = "Link with ID <highlight>{$id}<end> deleted successfully.";
		} else {
			$msg = "You do not have permission to delete links added by <highlight>{$obj->name}<end>";
		}
		$context->reply($msg);
	}

	/** @return list<ExportLink> */
	public function export(DB $db, LoggerInterface $logger): array {
		return $db->table(Link::getTable())
			->asObj(Link::class)
			->map(static function (Link $link): ExportLink {
				return new ExportLink(
					createdBy: new ExportCharacter(name: $link->name),
					creationTime: $link->dt,
					url: $link->website,
					description: $link->comments,
				);
			})->toList();
	}

	public function import(DB $db, LoggerInterface $logger, array $data, array $rankMap): void {
		$logger->notice('Importing {num_links} links', [
			'num_links' => count($data),
		]);
		$db->awaitBeginTransaction();
		try {
			$logger->notice('Deleting all links');
			$db->table(Link::getTable())->truncate();
			foreach ($data as $link) {
				if (!($link instanceof ExportLink)) {
					throw new InvalidArgumentException(__CLASS__ . ':' . __METHOD__ . '() called with wrong data');
				}
				$this->db->insert(new Link(
					name: $link->createdBy?->tryGetName() ?? $this->config->main->character,
					website: $link->url,
					comments: $link->description ?? '',
					dt: $link->creationTime ?? null,
				));
			}
		} catch (Throwable $e) {
			$logger->error('{error}. Rolling back changes.', [
				'error' => rtrim($e->getMessage(), '.'),
				'exception' => $e,
			]);
			$db->rollback();
			return;
		}
		$db->commit();
		$logger->notice('All links imported');
	}
}
