<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PREFERENCES;

use Amp\Http\HttpStatus;
use Amp\Http\Server\{Request, Response};
use AO\Utils;
use Nadybot\Core\{
	Attributes as NCA,
	Attributes\Http,
	DB,
	ModuleInstance,
	Types\AccessLevel,
};
use Nadybot\Core\DBSchema\Preferences as DBSchemaPreferences;
use Nadybot\Modules\WEBSERVER_MODULE\{
	ApiResponse,
	WebserverController,
};

/**
 * @author Tyrence (RK2)
 */
#[
	NCA\Instance,
	NCA\HasMigrations
]
class Preferences extends ModuleInstance {
	#[NCA\Inject]
	private DB $db;

	public function save(string $sender, string $name, string $value): void {
		$this->db->upsert(new DBSchemaPreferences(
			sender: Utils::normalizeCharacter($sender),
			name: strtolower($name),
			value: $value
		));
	}

	public function get(string $sender, string $name): ?string {
		$sender = Utils::normalizeCharacter($sender);
		$name = strtolower($name);
		return $this->db->table(DBSchemaPreferences::getTable())
			->where('sender', $sender)
			->where('name', $name)
			->select('value')
			->pluckStrings('value')
			->first();
	}

	public function delete(string $sender, string $name): bool {
		$sender = Utils::normalizeCharacter($sender);
		$name = strtolower($name);
		return $this->db->table(DBSchemaPreferences::getTable())
			->where('sender', $sender)
			->where('name', $name)
			->delete() !== 0;
	}

	/**
	 * Get the value of a setting
	 *
	 * @param string $key The name of the setting
	 */
	#[
		Http\Api('/setting/%s'),
		Http\GET,
		Http\AccessLevel(AccessLevel::All),
		Http\ApiResult(code: 200, class: 'string', desc: 'The stored value'),
		Http\ApiResult(code: 204, desc: 'No value stored')
	]
	public function apiSettingGetEndpoint(Request $request, string $key): Response {
		$user = $request->getAttribute(WebserverController::USER) ?? '_';
		$result = $this->get($user, $key);
		if ($result === null) {
			return new Response(status: HttpStatus::NO_CONTENT);
		}
		return ApiResponse::create($result);
	}

	/**
	 * Create a new setting
	 *
	 * @param string $key The name of the setting
	 */
	#[
		Http\Api('/setting/%s'),
		Http\POST,
		Http\AccessLevel(AccessLevel::All),
		Http\ApiResult(code: 201, desc: 'The new setting was stored successfully'),
		Http\ApiResult(code: 409, desc: 'There is already a setting stored'),
		Http\ApiResult(code: 415, desc: 'You tried to pass more than just a simple string'),
		Http\RequestBody(class: 'string', desc: 'The data you want to store', required: true)
	]
	public function apiSettingPostEndpoint(Request $request, string $key): Response {
		$user = $request->getAttribute(WebserverController::USER) ?? '_';
		$result = $this->get($user, $key);
		if ($result !== null) {
			return new Response(
				status: HttpStatus::CONFLICT,
				headers: ['Content-type' => 'text/plain'],
				body: 'The given setting already exists'
			);
		}
		$body = $request->getAttribute(WebserverController::BODY);
		if (!is_string($body)) {
			return new Response(
				status: HttpStatus::UNSUPPORTED_MEDIA_TYPE,
				headers: ['Content-type' => 'text/plain'],
				body: 'Only plain strings supported'
			);
		}
		$this->save($user, $key, $body);
		return new Response(status: HttpStatus::CREATED);
	}

	/**
	 * Store a setting
	 *
	 * @param string $key The name of the setting
	 */
	#[
		Http\Api('/setting/%s'),
		Http\PUT,
		Http\AccessLevel(AccessLevel::All),
		Http\ApiResult(code: 204, desc: 'The new setting was stored successfully'),
		Http\ApiResult(code: 415, desc: 'You tried to pass more than just a simple string'),
		Http\RequestBody(class: 'string', desc: 'The data you want to store', required: true)
	]
	public function apiSettingPutEndpoint(Request $request, string $key): Response {
		$body = $request->getAttribute(WebserverController::BODY);
		if (!is_string($body)) {
			return new Response(
				status: HttpStatus::UNSUPPORTED_MEDIA_TYPE,
				headers: ['Content-type' => 'text/plain'],
				body: 'Only plain strings supported'
			);
		}
		$user = $request->getAttribute(WebserverController::USER) ?? '_';
		$this->save($user, $key, $body);
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/**
	 * Delete a setting
	 *
	 * @param string $key The name of the setting
	 */
	#[
		Http\Api('/setting/%s'),
		Http\DELETE,
		Http\AccessLevel(AccessLevel::All),
		Http\ApiResult(code: 204, desc: 'The new setting was deleted successfully'),
		Http\ApiResult(code: 409, desc: 'No setting found for that key')
	]
	public function apiSettingDeleteEndpoint(Request $request, string $key): Response {
		$user = $request->getAttribute(WebserverController::USER) ?? '_';
		$result = $this->delete($user, $key);
		if (!$result) {
			return new Response(
				status: HttpStatus::CONFLICT,
				headers: ['Content-type' => 'text/plain'],
				body: "The given setting doesn't exist"
			);
		}
		return new Response(status: HttpStatus::NO_CONTENT);
	}
}
