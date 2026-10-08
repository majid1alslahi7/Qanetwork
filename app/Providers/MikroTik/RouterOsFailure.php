<?php

namespace App\Providers\MikroTik;

enum RouterOsFailure: string
{
    case CONNECTION = 'connection';
    case AUTHENTICATION = 'authentication';
    case TIMEOUT = 'timeout';
    case DISCONNECTED = 'disconnected';
    case PROTOCOL = 'protocol';
    case COMMAND_REJECTED = 'command_rejected';
}
