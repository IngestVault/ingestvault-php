<?php

declare(strict_types=1);

namespace IngestVault;

final readonly class RawJson
{
    /**
     * @throws \InvalidArgumentException When the text is not valid JSON.
     */
    public function __construct(public string $json)
    {
        if (function_exists('json_validate')) {
            $valid = json_validate($json);
        } else {
            json_decode($json);
            $valid = json_last_error() === JSON_ERROR_NONE;
        }

        if (! $valid) {
            throw new \InvalidArgumentException('The payload is not valid JSON.');
        }
    }
}
