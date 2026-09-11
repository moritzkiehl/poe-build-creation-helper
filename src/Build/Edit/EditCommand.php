<?php

declare(strict_types=1);

namespace App\Build\Edit;

/**
 * One edit, on its way to its handler. Carries the build's id rather than the
 * entity so a command stays a value: it is built from an HTTP request and must
 * not drag a managed entity across the bus.
 */
interface EditCommand
{
}
