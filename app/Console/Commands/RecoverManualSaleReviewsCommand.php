<?php

namespace App\Console\Commands;

use App\Jobs\ReviewSaleJob;
use App\Models\ManualSaleReview;
use Illuminate\Console\Command;

class RecoverManualSaleReviewsCommand extends Command
{
    protected $signature = 'sales:recover-reviews {--limit=100 : Maximum number of interrupted reviews to enqueue}';

    protected $description = 'Requeue interrupted administrative sale status reviews';

    public function handle(): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        if ($limit === false) {
            $this->error('The --limit option must be an integer between 1 and 1000.');

            return self::FAILURE;
        }
        $count = 0;
        ManualSaleReview::query()->whereIn('status', ['queued', 'processing'])->where('updated_at', '<=', now()->subMinutes(2))
            ->orderBy('id')->limit($limit)->get(['id', 'sale_id'])->each(function (ManualSaleReview $review) use (&$count): void {
                ReviewSaleJob::dispatch($review->id, $review->sale_id);
                $count++;
            });
        $this->info('Queued '.$count.' interrupted review(s) for recovery.');

        return self::SUCCESS;
    }
}
