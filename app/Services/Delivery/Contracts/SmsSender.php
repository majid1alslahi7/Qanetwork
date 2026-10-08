<?php

namespace App\Services\Delivery\Contracts;

use App\Services\Delivery\Data\SmsSubmissionResult;
use SensitiveParameter;

interface SmsSender
{
    /** Check local configuration only; this must not contact the provider. */
    public function available(): bool;

    /**
     * RETRYABLE means the provider confirmed that no message was accepted.
     * An uncertain timeout must return UNKNOWN, never RETRYABLE.
     *
     * @param  array<string, string>  $credentials
     */
    public function submit(#[SensitiveParameter] string $recipient, #[SensitiveParameter] array $credentials, string $idempotencyKey): SmsSubmissionResult;
}
