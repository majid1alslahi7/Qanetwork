<?php

namespace App\Services\Delivery\Data;

use App\Enums\SmsSubmissionStatus;

readonly class SmsSubmissionResult
{
    public function __construct(public SmsSubmissionStatus $status, public ?string $providerReference = null) {}
}
