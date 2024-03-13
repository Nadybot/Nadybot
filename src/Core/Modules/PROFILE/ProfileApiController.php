<?php declare(strict_types=1);

namespace Nadybot\Core\Modules\PROFILE;

use Amp\File\{FilesystemException};
use Amp\Http\HttpStatus;
use Amp\Http\Server\{Request, Response};
use Exception;
use Nadybot\Core\{Attributes as NCA, Filesystem, ModuleInstance};
use Nadybot\Modules\WEBSERVER_MODULE\{WebserverController};
use Nadybot\Modules\{
	WEBSERVER_MODULE\ApiResponse,
};
use Throwable;

#[NCA\Instance]
class ProfileApiController extends ModuleInstance {
	#[NCA\Inject]
	private ProfileController $profileController;

	#[NCA\Inject]
	private Filesystem $fs;

	/** Get a list of saved profiles */
	#[
		NCA\Api("/profile"),
		NCA\GET,
		NCA\AccessLevelFrom("profile"),
		NCA\ApiResult(code: 200, class: "string[]", desc: "A list of saved profiled")
	]
	public function moduleGetEndpoint(Request $request): Response {
		try {
			$profiles = $this->profileController->getProfileList();
		} catch (Throwable $e) {
			return new Response(status: HttpStatus::INTERNAL_SERVER_ERROR);
		}
		return ApiResponse::create($profiles);
	}

	/** View a profile */
	#[
		NCA\Api("/profile/%s"),
		NCA\GET,
		NCA\AccessLevelFrom("profile"),
		NCA\ApiResult(code: 200, class: "string", desc: "Profile found and shown"),
		NCA\ApiResult(code: 404, desc: "Profile not found")
	]
	public function viewProfileEndpoint(Request $request, string $profile): Response {
		$filename = $this->profileController->getFilename($profile);

		if (!$this->fs->exists($filename)) {
			return new Response(
				status: HttpStatus::NOT_FOUND,
				body: "Profile {$filename} not found."
			);
		}
		try {
			$content = $this->fs->read($filename);
		} catch (FilesystemException) {
			return new Response(
				status: HttpStatus::NOT_FOUND,
				body: "Profile {$filename} not accessible."
			);
		}
		return ApiResponse::create($content);
	}

	/** Delete a profile */
	#[
		NCA\Api("/profile/%s"),
		NCA\DELETE,
		NCA\AccessLevelFrom("profile"),
		NCA\ApiResult(code: 204, desc: "Profile successfully deleted"),
		NCA\ApiResult(code: 404, desc: "Profile not found")
	]
	public function deleteProfileEndpoint(Request $request, string $profile): Response {
		$filename = $this->profileController->getFilename($profile);

		try {
			$this->fs->deleteFile($filename);
		} catch (FilesystemException) {
			return new Response(
				status: HttpStatus::NOT_FOUND,
				body: "Profile {$filename} not found."
			);
		}
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/** Load a profile */
	#[
		NCA\Api("/profile/%s"),
		NCA\PATCH,
		NCA\PUT,
		NCA\AccessLevelFrom("profile"),
		NCA\RequestBody(class: "Operation", desc: "Must be \"load\"", required: true),
		NCA\ApiResult(code: 204, desc: "Profile load successfully"),
		NCA\ApiResult(code: 402, desc: "Wrong or no operation given"),
		NCA\ApiResult(code: 404, desc: "Profile not found")
	]
	public function loadProfileEndpoint(Request $request, string $profile): Response {
		$user = $request->getAttribute(WebserverController::USER) ?? "_";
		$body = $request->getAttribute(WebserverController::BODY);
		if (!is_object($body) || !isset($body->op)) {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}

		$op = $body->op;
		if ($op !== "load") {
			return new Response(status: HttpStatus::UNPROCESSABLE_ENTITY);
		}
		$filename = $this->profileController->getFilename($profile);

		if (!$this->fs->exists($filename)) {
			return new Response(
				status: HttpStatus::NOT_FOUND,
				body: "Profile {$filename} not found."
			);
		}
		$output = $this->profileController->loadProfile($filename, $user);
		if ($output === null) {
			return new Response(status: HttpStatus::INTERNAL_SERVER_ERROR);
		}
		return new Response(status: HttpStatus::NO_CONTENT);
	}

	/** Load a profile */
	#[
		NCA\Api("/profile/%s"),
		NCA\POST,
		NCA\AccessLevelFrom("profile"),
		NCA\ApiResult(code: 204, desc: "Profile saved successfully"),
		NCA\ApiResult(code: 409, desc: "Profile already exists")
	]
	public function saveProfileEndpoint(Request $request, string $profile): Response {
		try {
			$this->profileController->saveProfile($profile);
		} catch (Exception) {
			return new Response(
				status: HttpStatus::CONFLICT,
				body: "This profile already exists."
			);
		}
		return new Response(status: HttpStatus::NO_CONTENT);
	}
}
