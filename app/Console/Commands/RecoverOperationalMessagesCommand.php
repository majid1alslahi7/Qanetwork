<?php

namespace App\Console\Commands;

use App\Jobs\SendOperationalMessageJob;
use App\Models\OperationalMessage;
use App\Services\Delivery\QuickConnectSmsSender;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('messages:recover')]
#[Description('Recover queued operational SMS and track accepted messages without resending uncertain submissions')]
class RecoverOperationalMessagesCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(QuickConnectSmsSender $sender): int
    {
        if (! config('services.quick_connect.operational_enabled', false) || ! $sender->available()) {
            return self::SUCCESS;
        }
        OperationalMessage::query()->whereIn('status', ['queued', 'sending', 'submitted'])
            ->where('updated_at', '<=', now()->subMinutes(2))->orderBy('id')->limit(100)->get(['id'])
            ->each(fn (OperationalMessage $message) => SendOperationalMessageJob::dispatch($message->id));

        return self::SUCCESS;
    }
}
