<?php declare(strict_types=1);

namespace Nadybot\Core\Types;

use EventSauce\ObjectHydrator\MapperSettings;

/** This is a configuration setting for the Hydrator to skip serializing public functions */
#[MapperSettings(serializePublicMethods: false)]
interface DoNotSerializePublicFunctions {
}
