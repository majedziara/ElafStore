<?php

namespace App\Console\Commands;

use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RefundService;
use Illuminate\Console\Command;

class ReconcileProviderId extends Command
{
    protected $signature = 'store:attach-provider-id {type : payment or refund} {reference : attempt UUID or refund request_key} {provider_id}';

    protected $description = 'Attach an independently confirmed provider ID after an ambiguous network result';

    public function handle(PaymentService $payments, RefundService $refunds): int
    {
        try {
            if ($this->argument('type') === 'payment') {
                $a = PaymentAttempt::where('uuid', $this->argument('reference'))->firstOrFail();
                $payments->attach($a, $this->argument('provider_id'));
                $payments->sync($a, true);
            } elseif ($this->argument('type') === 'refund') {
                $r = Refund::where('request_key', $this->argument('reference'))->firstOrFail();
                $refunds->attach($r, $this->argument('provider_id'));
            } else {
                $this->error('Type must be payment or refund.');

                return self::FAILURE;
            }
            $this->info('Provider result verified and reconciled.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
