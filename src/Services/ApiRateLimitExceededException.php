<?php

declare(strict_types=1);

namespace EventMenu\Services;

use RuntimeException;

final class ApiRateLimitExceededException extends RuntimeException
{
}
