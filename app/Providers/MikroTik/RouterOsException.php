<?php

namespace App\Providers\MikroTik;

use RuntimeException;

final class RouterOsException extends RuntimeException
{
    public function __construct(public readonly RouterOsFailure $failure)
    {
        parent::__construct('RouterOS request failed: '.$failure->value.'.');
    }
}
