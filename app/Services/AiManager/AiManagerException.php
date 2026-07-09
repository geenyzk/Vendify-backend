<?php

namespace App\Services\AiManager;

use RuntimeException;

/**
 * Raised for any AI Manager failure worth surfacing to the admin — a missing
 * API key, an OpenAI error, or a tool that couldn't run. The controller maps
 * these to a clean `fail()` response.
 */
class AiManagerException extends RuntimeException
{
}
