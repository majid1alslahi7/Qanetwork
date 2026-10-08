<?php

namespace App\Providers\MikroTik;

interface RouterOsClient
{
    /** @param list<string> $words */
    public function execute(RouterOsConnectionConfig $config, #[\SensitiveParameter] array $words): RouterOsReply;
}
