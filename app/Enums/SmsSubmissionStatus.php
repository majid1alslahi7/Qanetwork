<?php

namespace App\Enums;

enum SmsSubmissionStatus: string
{
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case RETRYABLE = 'retryable';
    case UNKNOWN = 'unknown';
}
