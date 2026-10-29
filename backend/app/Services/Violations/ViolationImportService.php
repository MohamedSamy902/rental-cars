<?php

namespace App\Services\Violations;

use App\Models\Vehicle;
use App\Models\Violation;
use App\Models\ViolationSyncRun;
use App\Support\DigitNormalizer;
use DomainException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ViolationImportService
{
    public function __construct(
        private readonly ViolationMatchService $matcher,
    ) {}

    /**
     * CSV أعمدة متوقعة (عربي أو إنجليزي):
     * plate|لوحة, occurred_at|التاريخ, amount|المبلغ, location|المكان, external_number|رقم_المخالفة, notes|ملاحظات
     *
     * @return array{run: ViolationSyncRun, created: list<Violation>, errors: list<string>}
     */
    public function importCsv(UploadedFile $file, ?int $userId = null): array
    {
        $raw = file_get_contents($file->getRealPath() ?: '');
        if ($raw === false || trim($raw) === '') {
            throw new DomainException('ملف CSV فارغ.');
        }

        // إزالة BOM
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $lines = preg_split('/\r\n|\r|\n/', trim($raw)) ?: [];
        if (count($lines) < 2) {
            throw new DomainException('CSV يحتاج صف عناوين وصفاً واحداً على الأقل.');
        }

        $header = $this->parseCsvLine(array_shift($lines) ?? '');
        $map = $this->mapHeader($header);
        if (! isset($map['plate'], $map['occurred_at'], $map['amount'])) {
            throw new DomainException('أعمدة إلزامية ناقصة: لوحة، التاريخ، المبلغ.');
        }

        $run = ViolationSyncRun::query()->create([
            'channel' => 'csv_import',
            'status' => 'completed',
            'started_by' => $userId,
            'meta' => ['filename' => $file->getClientOriginalName()],
        ]);

        $created = [];
        $errors = [];
        $seen = 0;
        $skipped = 0;

        foreach ($lines as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $seen++;
            $cols = $this->parseCsvLine($line);
            try {
                $row = $this->rowFromColumns($cols, $map);
                $violation = $this->persistRow($row, $run->id, 'csv_import');
                if ($violation === null) {
                    $skipped++;
                } else {
                    $created[] = $violation;
                }
            } catch (\Throwable $e) {
                $skipped++;
                $errors[] = 'سطر '.($i + 2).': '.$e->getMessage();
            }
        }

        $run->update([
            'rows_seen' => $seen,
            'rows_created' => count($created),
            'rows_skipped' => $skipped,
            'status' => $errors === [] ? 'completed' : 'partial',
            'finished_at' => now(),
            'meta' => array_merge($run->meta ?? [], ['errors' => array_slice($errors, 0, 20)]),
        ]);

        return ['run' => $run->fresh(), 'created' => $created, 'errors' => $errors];
    }

    /**
     * حفظ صف واحد من المساعد اليدوي بعد الاستعلام على البوابة.
     *
     * @param  array{vehicle_id: int, occurred_at: string, amount: int, location?: string, external_number?: string, notes?: string, photo_path?: string}  $data
     */
    public function saveAssisted(array $data, ?int $userId = null): Violation
    {
        $run = ViolationSyncRun::query()->create([
            'channel' => 'assisted',
            'status' => 'completed',
            'rows_seen' => 1,
            'started_by' => $userId,
            'finished_at' => now(),
        ]);

        $violation = $this->persistRow([
            'vehicle_id' => (int) $data['vehicle_id'],
            'occurred_at' => $data['occurred_at'],
            'amount' => (int) $data['amount'],
            'location' => $data['location'] ?? null,
            'external_number' => $data['external_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'photo_path' => $data['photo_path'] ?? null,
        ], $run->id, 'assisted');

        if ($violation === null) {
            $run->update(['rows_skipped' => 1, 'rows_created' => 0, 'status' => 'partial']);
            throw new DomainException('المخالفة مسجّلة مسبقاً بنفس الرقم الخارجي.');
        }

        $run->update(['rows_created' => 1]);

        return $violation;
    }

    /**
     * @param  array{vehicle_id: int, occurred_at: string, amount: int, location?: ?string, external_number?: ?string, notes?: ?string, photo_path?: ?string}  $row
     */
    private function persistRow(array $row, int $runId, string $source): ?Violation
    {
        $external = $row['external_number'] ?? null;
        if (is_string($external) && $external !== '') {
            $exists = Violation::query()
                ->where('source', $source)
                ->where('external_number', $external)
                ->exists();
            if ($exists) {
                return null;
            }
        }

        $match = $this->matcher->match((int) $row['vehicle_id'], $row['occurred_at']);

        return DB::transaction(function () use ($row, $runId, $source, $match, $external) {
            return Violation::query()->create([
                'vehicle_id' => $row['vehicle_id'],
                'occurred_at' => $row['occurred_at'],
                'amount' => $row['amount'],
                'location' => $row['location'] ?? null,
                'notes' => $row['notes'] ?? null,
                'photo_path' => $row['photo_path'] ?? null,
                'contract_id' => $match['contract_id'],
                'customer_id' => $match['customer_id'],
                'status' => $match['contract_id'] ? 'assigned' : 'new',
                'source' => $source,
                'external_number' => $external ?: null,
                'needs_review' => $match['needs_review'],
                'sync_run_id' => $runId,
            ]);
        });
    }

    /**
     * @param  list<string>  $header
     * @return array<string, int>
     */
    private function mapHeader(array $header): array
    {
        $aliases = [
            'plate' => ['plate', 'لوحة', 'رقم_اللوحة', 'رقم اللوحة'],
            'occurred_at' => ['occurred_at', 'date', 'التاريخ', 'وقت_المخالفة', 'وقت المخالفة'],
            'amount' => ['amount', 'المبلغ', 'قيمة'],
            'location' => ['location', 'المكان', 'الموقع'],
            'external_number' => ['external_number', 'رقم_المخالفة', 'رقم المخالفة', 'violation_number'],
            'notes' => ['notes', 'ملاحظات'],
        ];

        $map = [];
        foreach ($header as $i => $raw) {
            $key = mb_strtolower(trim($raw));
            foreach ($aliases as $canon => $names) {
                foreach ($names as $name) {
                    if ($key === mb_strtolower($name)) {
                        $map[$canon] = $i;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @param  list<string>  $cols
     * @param  array<string, int>  $map
     * @return array{vehicle_id: int, occurred_at: string, amount: int, location?: ?string, external_number?: ?string, notes?: ?string}
     */
    private function rowFromColumns(array $cols, array $map): array
    {
        $plate = trim((string) DigitNormalizer::western($cols[$map['plate']] ?? ''));
        if ($plate === '') {
            throw new DomainException('اللوحة فارغة.');
        }

        $vehicle = Vehicle::query()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($plate) {
                $q->where('plate', $plate)
                    ->orWhereRaw('REPLACE(plate, " ", "") = ?', [str_replace(' ', '', $plate)]);
            })
            ->first();

        if (! $vehicle) {
            throw new DomainException('لا توجد سيارة باللوحة: '.$plate);
        }

        $occurred = (string) DigitNormalizer::western($cols[$map['occurred_at']] ?? '');
        $amountRaw = (string) DigitNormalizer::western($cols[$map['amount']] ?? '');
        $amount = (int) preg_replace('/\D+/', '', $amountRaw);
        if ($amount < 1) {
            throw new DomainException('المبلغ غير صالح.');
        }

        $ts = strtotime($occurred);
        if ($ts === false) {
            throw new DomainException('تاريخ غير صالح.');
        }
        $occurredAt = date('Y-m-d H:i:s', $ts);
        if ($ts > time() + 3600) {
            throw new DomainException('لا يُقبل تاريخ في المستقبل.');
        }

        $external = isset($map['external_number'])
            ? trim((string) DigitNormalizer::western($cols[$map['external_number']] ?? ''))
            : '';

        return [
            'vehicle_id' => (int) $vehicle->id,
            'occurred_at' => $occurredAt,
            'amount' => $amount,
            'location' => isset($map['location']) ? trim((string) ($cols[$map['location']] ?? '')) ?: null : null,
            'external_number' => $external !== '' ? $external : null,
            'notes' => isset($map['notes']) ? trim((string) ($cols[$map['notes']] ?? '')) ?: null : null,
        ];
    }

    /**
     * @return list<string>
     */
    private function parseCsvLine(string $line): array
    {
        $parsed = str_getcsv($line);
        if (! is_array($parsed)) {
            return [];
        }

        return array_map(static fn ($v) => trim((string) $v), $parsed);
    }
}
