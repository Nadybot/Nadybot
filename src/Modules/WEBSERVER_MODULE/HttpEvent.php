<?php declare(strict_types=1);

namespace Nadybot\Modules\WEBSERVER_MODULE;

use Nadybot\Core\Event;

class HttpEvent extends Event {
	public Request $request;
}