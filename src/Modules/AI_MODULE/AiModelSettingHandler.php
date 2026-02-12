<?php declare(strict_types=1);

namespace Nadybot\Modules\AI_MODULE;

use function Safe\json_decode;
use Amp\Http\Client\{HttpClientBuilder, Request};
use Exception;
use Nadybot\Core\{Attributes as NCA, Hydrator, Text};
use Nadybot\Core\SettingHandlers\SettingHandler;
use Nadybot\Modules\AI_MODULE\Attributes\AiModel;
use Psr\Log\LoggerInterface;

use Throwable;

/**
 * Class to represent an ai model setting
 */
#[NCA\SettingHandler(AiModel::TYPE)]
class AiModelSettingHandler extends SettingHandler {
	#[NCA\Inject]
	private HttpClientBuilder $builder;

	#[NCA\Inject]
	private AIController $aiController;

	#[NCA\Logger]
	private LoggerInterface $logger;

	/** {@inheritDoc} */
	public function getDescription(): string {
		$msg = "For this setting you need to enter the name of a supported model.\n\n".
			"To change this setting:\n\n".
			"<highlight>/tell <myname> settings save {$this->row->name} <i>model</i><end>\n\n";
		return $msg;
	}

	/** Get a displayable representation of the setting */
	public function displayValue(string $sender): string {
		if (!isset($this->row->value) || $this->row->value === '') {
			return '<grey>&lt;none selected&gt;<end>';
		}
		return parent::displayValue($sender);
	}

	public function getOptions(): string {
		$client = $this->builder->build();

		$request = new Request($this->aiController->aiApiUrl . '/models');
		if ($this->aiController->aiApiToken !== '') {
			$request->addHeader('Authorization', "Bearer {$this->aiController->aiApiToken}");
		}
		$response = $client->request($request);
		if ($response->getStatus() === 401) {
			throw new Exception('Your API token is invalid.');
		}
		if ($response->getStatus() !== 200) {
			$this->logger->error('Error fetching model list from API. Status: {status}, Body: {body}', [
				'status' => $response->getStatus(),
				'body' => $response->getBody()->buffer(),
			]);
			throw new Exception('Please choose a valid API endpoint first');
		}
		$body = $response->getBody()->buffer();
		try {
			$reply = json_decode($body, true);

			/** @psalm-suppress MixedArgument */
			$modelList = Hydrator::hydrate(Models\ModelList::class, $reply);
		} catch (Throwable $e) {
			$this->logger->error('Error decoding model list from API. Body: {body}', [
				'body' => $body,
				'exception' => $e,
			]);
			throw new Exception('Error sending request to the API. Please check your logs.', previous: $e);
		}
		$msg = "<header2>Available Models<end>\n";
		foreach ($modelList->data as $model) {
			$saveLink = Text::makeChatcmd('select', "/tell <myname> settings save {$this->row->name} {$model->id}");
			$msg .= '<tab><highlight>' . htmlspecialchars($model->display_name ?? $model->id) . "<end> [{$saveLink}]\n";
		}

		return $msg;
	}
}
