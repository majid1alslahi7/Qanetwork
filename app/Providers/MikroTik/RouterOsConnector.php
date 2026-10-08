<?php

namespace App\Providers\MikroTik;

interface RouterOsConnector
{
    public function connect(RouterOsConnectionConfig $config): RouterOsTransport;
}
