<?php

namespace App\Services\Sales;

use App\Enums\UserRole;
use App\Jobs\ReviewSaleJob;
use App\Models\AuditEvent;
use App\Models\ManualSaleReview;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RequestManualSaleReviewService
{
    public function handle(User $actor, Sale $sale, string $key, string $reason): ManualSaleReview
    {
        $review = DB::transaction(function () use ($actor, $sale, $key, $reason): ManualSaleReview {
            $currentActor = User::query()->lockForUpdate()->findOrFail($actor->id);
            if ($currentActor->role !== UserRole::ADMIN || ! $currentActor->canAccessApplication()) {
                throw new AuthorizationException;
            }
            $current = Sale::query()->lockForUpdate()->findOrFail($sale->id);
            $requestKey = 'review:'.$current->id.':'.$actor->id.':'.hash('sha256', $key);
            $existing = $current->manualReviews()->where('idempotency_key', $requestKey)->first();
            if ($existing !== null) {
                if ($existing->reason !== $reason) {
                    throw new ConflictHttpException('The review request key has already been used with a different reason.');
                }

                return $existing;
            }
            if ($current->manualReviews()->whereIn('status', ['queued', 'processing'])->exists()) {
                throw new ConflictHttpException('A review of this sale is already pending.');
            }
            $transaction = $current->providerTransaction()->firstOrFail();
            if ($current->status === 'completed' || ! in_array($transaction->status,
                ['processing', 'timeout', 'unknown', 'reconciliation_required', 'confirmed', 'failed'], true)) {
                throw new ConflictHttpException('This sale does not require a provider status review.');
            }
            $review = new ManualSaleReview(['sale_id' => $current->id, 'requested_by' => $actor->id, 'idempotency_key' => $requestKey,
                'reason' => $reason, 'sale_status_before' => $current->status]);
            $review->status = 'queued';
            $review->save();
            AuditEvent::query()->create(['actor_id' => $actor->id, 'event_type' => 'sale.review_requested',
                'subject_type' => 'manual_sale_review', 'subject_id' => $review->id,
                'after' => ['sale_id' => $current->id, 'status' => 'queued']]);

            return $review;
        }, 3);
        if (in_array($review->status, ['queued', 'processing'], true)) {
            ReviewSaleJob::dispatch($review->id, $review->sale_id)->afterCommit();
        }

        return $review;
    }
}
