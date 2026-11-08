<?php

use App\Services\Accounting\HistoricAccountingRemediationService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

/**
 * Runs reviewed historic accounting heal once on deploy (idempotent service).
 * Covers missing payment journals, overpay→credit, rent month realign, FIFO 39→40.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $result = app(HistoricAccountingRemediationService::class)->run();
            Log::info('historic_accounting_remediation', $result);
        } catch (Throwable $e) {
            // Do not block migrate on empty/partial DBs during CI — rethrow only unexpected schema errors.
            if (str_contains($e->getMessage(), 'no such table') || str_contains($e->getMessage(), "doesn't exist")) {
                return;
            }
            // Fresh sqlite tests may not have seed data; service is safe. Propagate real failures.
            throw $e;
        }
    }

    public function down(): void
    {
        // Non-destructive heal; no automatic reverse of posted journals.
    }
};
