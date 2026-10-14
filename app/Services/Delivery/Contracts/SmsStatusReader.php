<?php

namespace App\Services\Delivery\Contracts;

interface SmsStatusReader
{
    /** Returns sent, failed, pending, or null when the result cannot be verified. */
    public function status(string $providerReference): ?string;
}
