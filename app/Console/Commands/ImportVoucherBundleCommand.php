<?php

namespace App\Console\Commands;

use App\Models\Network;
use App\Models\User;
use App\Services\Networks\ImportVoucherBundleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use JsonException;

#[Signature('qanetwork:import-vouchers {file} {--network= : Network ID or code} {--actor= : Active administrator or owner email} {--connection= : Stored-cards connection ID}')]
#[Description('Atomically import prepared voucher products and encrypted inventory without resetting existing cards')]
class ImportVoucherBundleCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ImportVoucherBundleService $service): int
    {
        $file = realpath((string) $this->argument('file'));
        $private = realpath(storage_path('app/private'));
        if ($file === false || $private === false || ! str_starts_with($file, $private.DIRECTORY_SEPARATOR) || ! is_file($file)
            || ! is_readable($file) || filesize($file) > 5 * 1024 * 1024
            || ! $this->option('network') || ! $this->option('actor')) {
            $this->error('Use a readable JSON file under storage/app/private (maximum 5 MB), and specify network and actor.');

            return self::FAILURE;
        }
        try {
            $stream = fopen($file, 'rb');
            if ($stream === false) {
                $this->error('Unable to read the private import file.');

                return self::FAILURE;
            }
            try {
                $contents = stream_get_contents($stream, 5 * 1024 * 1024 + 1);
            } finally {
                fclose($stream);
            }
            if ($contents === false || strlen($contents) > 5 * 1024 * 1024) {
                $this->error('The import file exceeds the size limit.');

                return self::FAILURE;
            }
            $bundle = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($bundle) || array_is_list($bundle)) {
                $this->error('Use a voucher bundle JSON object.');

                return self::FAILURE;
            }
            $actor = User::query()->where('email', $this->option('actor'))->firstOrFail();
            $network = Network::query()->where(fn ($query) => $query->whereKey($this->option('network'))->orWhere('code', $this->option('network')))->firstOrFail();
            $connection = $this->option('connection') ? $network->connections()->findOrFail($this->option('connection')) : null;
            $result = $service->handle($actor, $network, $connection, $bundle);
            $this->info('Created products: '.$result['products'].'; imported cards: '.$result['imported'].'; identical existing cards: '.$result['existing'].'.');
            $this->info('New products remain inactive. Review and enable them through network management before selling.');

            return self::SUCCESS;
        } catch (ValidationException $exception) {
            $this->error('The bundle was rejected; no changes were committed.');
            foreach (array_keys($exception->errors()) as $field) {
                $this->line('Invalid field: '.$field);
            }
        } catch (JsonException|ModelNotFoundException|AuthorizationException) {
            $this->error('Invalid JSON, unavailable records or unauthorized actor; no changes were committed.');
        }

        return self::FAILURE;
    }
}
