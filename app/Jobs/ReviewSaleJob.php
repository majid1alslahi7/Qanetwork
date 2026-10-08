<?php

namespace App\Jobs;

use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\ManualSaleReview;
use App\Models\Sale;
use App\Services\Sales\ReconcileSaleService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use LogicException;
use Throwable;

class ReviewSaleJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    public array $backoff = [30, 60, 120, 300];

    public function __construct(public readonly string $reviewId, public readonly string $saleId)
    {
        $this->onQueue('reconciliation');
    }

    public function uniqueId(): string
    {
        return 'manual-review:'.$this->reviewId;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('sale-provider:'.$this->saleId))->shared()->releaseAfter(30)->expireAfter(120)];
    }

    public function handle(ReconcileSaleService $service): void
    {
        $state = DB::transaction(function (): string {
            $review = ManualSaleReview::query()->lockForUpdate()->findOrFail($this->reviewId);
            if ($review->sale_id !== $this->saleId) {
                throw new LogicException('The review job does not match its sale.');
            }
            if (! in_array($review->status, ['queued', 'processing'], true)) {
                return 'stop';
            }
            $actor = $review->requester()->firstOrFail();
            if ($actor->role !== UserRole::ADMIN || ! $actor->canAccessApplication()) {
                $review->status = 'cancelled';
                $review->completed_at = now();
                $review->save();
                $this->audit($review, 'sale.review_cancelled');

                return 'stop';
            }
            $sale = $review->sale()->firstOrFail();
            $transaction = $sale->providerTransaction()->firstOrFail();
            if ($transaction->status === 'processing' && ($transaction->request_started_at ?? $transaction->created_at)->gt(now()->subMinutes(2))) {
                return 'wait';
            }
            $review->status = 'processing';
            $review->started_at ??= now();
            $review->save();

            return 'check';
        }, 3);
        if ($state === 'stop') {
            return;
        }
        if ($state === 'wait') {
            $this->release(120);

            return;
        }
        $service->handle(Sale::query()->findOrFail($this->saleId));
        DB::transaction(function (): void {
            $review = ManualSaleReview::query()->lockForUpdate()->findOrFail($this->reviewId);
            $review->status = 'completed';
            $review->sale_status_after = $review->sale()->firstOrFail()->status;
            $sale = $review->sale()->firstOrFail();
            if ($sale->status === 'completed' || ($sale->status === 'failed' && $sale->reservation()->firstOrFail()->status === 'released')) {
                $transaction = $sale->providerTransaction()->lockForUpdate()->firstOrFail();
                $transaction->manual_review_required_at = null;
                $transaction->save();
            }
            $review->completed_at = now();
            $review->save();
            $this->audit($review, 'sale.review_completed');
        }, 3);
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function (): void {
            $review = ManualSaleReview::query()->lockForUpdate()->find($this->reviewId);
            if ($review === null || ! in_array($review->status, ['queued', 'processing'], true)) {
                return;
            }
            $review->status = 'failed';
            $review->completed_at = now();
            $review->sale_status_after = $review->sale()->firstOrFail()->status;
            $review->save();
            $this->audit($review, 'sale.review_failed');
        }, 3);
    }

    private function audit(ManualSaleReview $review, string $event): void
    {
        AuditEvent::query()->create(['actor_id' => $review->requested_by, 'event_type' => $event,
            'subject_type' => 'manual_sale_review', 'subject_id' => $review->id,
            'after' => ['sale_id' => $review->sale_id, 'status' => $review->status, 'sale_status' => $review->sale_status_after]]);
    }
}
