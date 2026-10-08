<?php

namespace App\Services\Delivery;

use App\Services\Delivery\Contracts\SmsSender;
use App\Services\Delivery\Data\SmsSubmissionResult;
use LogicException;
use SensitiveParameter;

class UnavailableSmsSender implements SmsSender
{
    public function available(): bool
    {
        return false;
    }

    public function submit(#[SensitiveParameter] string $recipient, #[SensitiveParameter] array $credentials, string $idempotencyKey): SmsSubmissionResult
    {
        throw new LogicException('SMS provider is not configured.');
    }
}
