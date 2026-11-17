<?php

namespace App\Services\Hrms;

use App\Auth\CompanyRole;
use App\Models\StandaloneIdCard;
use App\Models\StandaloneIdCardIssue;
use App\Models\User;
use App\Support\AuthenticatedWorkspace;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StandaloneIdCardBulkIssuer
{
    public const FORMATS = ['zip', 'combined'];

    public function __construct(
        private readonly StandaloneIdCardIssuer $issuer,
        private readonly EmployeeIdCardPdf $pdf,
    ) {}

    /**
     * @return array{
     *     candidates: list<array<string, mixed>>,
     *     formats: list<array{value: string, label: string}>,
     *     summary: array{total: int, eligible: int, notReady: int, alreadyIssued: int}
     * }
     */
    public function page(User $actor): array
    {
        $context = $this->issueContext($actor);
        $candidates = $this->candidates($context['tenantId']);

        return [
            'candidates' => $candidates,
            'formats' => [
                ['value' => 'zip', 'label' => 'ZIP of individual PDFs'],
                ['value' => 'combined', 'label' => 'One combined PDF'],
            ],
            'summary' => [
                'total' => count($candidates),
                'eligible' => count(array_filter($candidates, fn (array $row): bool => $row['eligible'] === true)),
                'notReady' => count(array_filter($candidates, fn (array $row): bool => $row['state'] === 'not_ready')),
                'alreadyIssued' => count(array_filter($candidates, fn (array $row): bool => $row['state'] === 'already_issued')),
            ],
        ];
    }

    /**
     * @param  list<int|string>  $cardIds
     * @return array{selected: list<array<string, mixed>>, eligibleCount: int, notReadyCount: int, alreadyIssuedCount: int, missingCount: int}
     */
    public function preview(User $actor, array $cardIds): array
    {
        $context = $this->issueContext($actor);
        $selected = $this->classify($context['tenantId'], $cardIds);

        return [
            'selected' => $selected,
            'eligibleCount' => count(array_filter($selected, fn (array $row): bool => $row['eligible'] === true)),
            'notReadyCount' => count(array_filter($selected, fn (array $row): bool => $row['state'] === 'not_ready')),
            'alreadyIssuedCount' => count(array_filter($selected, fn (array $row): bool => $row['state'] === 'already_issued')),
            'missingCount' => count(array_filter($selected, fn (array $row): bool => $row['state'] === 'missing')),
        ];
    }

    /**
     * @param  list<int|string>  $cardIds
     * @return array{
     *     message: string,
     *     format: string,
     *     successCount: int,
     *     failureCount: int,
     *     skippedCount: int,
     *     downloadUrl: string|null,
     *     downloadToken: string|null,
     *     rows: list<array<string, mixed>>
     * }
     */
    public function run(User $actor, array $cardIds, string $format): array
    {
        $context = $this->issueContext($actor);
        $format = strtolower(trim($format));

        if (! in_array($format, self::FORMATS, true)) {
            throw ValidationException::withMessages(['format' => 'Choose a ZIP of individual PDFs or one combined PDF.']);
        }

        if ($format === 'zip' && ! class_exists(\ZipArchive::class)) {
            throw ValidationException::withMessages(['format' => 'ZIP bulk download is not available on this server.']);
        }

        $classified = $this->classify($context['tenantId'], $cardIds);

        if ($classified === []) {
            throw ValidationException::withMessages(['card_ids' => 'Select at least one standalone ID card.']);
        }

        $rows = [];
        $issuedPdfs = [];
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $seenCards = [];

        foreach ($classified as $row) {
            if ($row['eligible'] !== true) {
                if (in_array($row['state'], ['already_issued', 'missing'], true)) {
                    $skipped++;
                } else {
                    $failed++;
                }
                $rows[] = $row;

                continue;
            }

            $cardId = (int) ($row['cardId'] ?? 0);
            if ($cardId < 1 || isset($seenCards[$cardId])) {
                $skipped++;
                $rows[] = [
                    ...$row,
                    'eligible' => false,
                    'state' => 'skipped',
                    'outcome' => 'skipped',
                    'message' => 'Duplicate selection was ignored.',
                    'blockers' => ['Duplicate selection was ignored.'],
                ];

                continue;
            }
            $seenCards[$cardId] = true;

            $card = StandaloneIdCard::query()->inCompany($context['tenantId'])->whereKey($cardId)->first();
            if ($card === null) {
                $failed++;
                $rows[] = [
                    ...$row,
                    'eligible' => false,
                    'state' => 'failed',
                    'outcome' => 'failed',
                    'message' => 'Standalone ID card was not found.',
                    'blockers' => ['Standalone ID card was not found.'],
                ];

                continue;
            }

            if ($card->status === 'active' && $this->hasIssuedPdf($context['tenantId'], $card)) {
                $skipped++;
                $rows[] = [
                    ...$row,
                    'eligible' => false,
                    'state' => 'already_issued',
                    'outcome' => 'skipped',
                    'message' => 'Already issued. Use individual reissue to rotate the QR.',
                    'blockers' => ['Already issued. Use individual reissue to rotate the QR.'],
                ];

                continue;
            }

            try {
                $this->issuer->issue($actor, $card);
                $card = $card->fresh() ?? $card;
                $pdfPath = $this->latestPdfPath($context['tenantId'], (int) $card->id);
                $bytes = $pdfPath !== null ? Storage::disk('local')->get($pdfPath) : null;

                if (! is_string($bytes) || $bytes === '') {
                    throw ValidationException::withMessages(['card' => 'Issued PDF could not be read.']);
                }

                $filename = $this->pdfFilename((string) $card->card_number, (int) $card->id);
                $issuedPdfs[] = ['filename' => $filename, 'bytes' => $bytes, 'cardId' => (int) $card->id];
                $success++;
                $rows[] = [
                    ...$row,
                    'cardId' => (int) $card->id,
                    'status' => (string) $card->status,
                    'eligible' => true,
                    'state' => 'issued',
                    'outcome' => 'issued',
                    'message' => 'Issued successfully.',
                    'blockers' => [],
                    'pdfUrl' => '/id-cards/standalone/'.$card->id.'/pdf',
                ];
            } catch (ValidationException $exception) {
                $failed++;
                $message = collect($exception->errors())->flatten()->first() ?: 'Issuing failed.';
                $rows[] = [
                    ...$row,
                    'eligible' => false,
                    'state' => 'failed',
                    'outcome' => 'failed',
                    'message' => (string) $message,
                    'blockers' => [(string) $message],
                ];
            }
        }

        $downloadToken = null;
        $downloadUrl = null;

        if ($issuedPdfs !== []) {
            [$downloadToken, $downloadUrl] = $this->storeBundle($context['tenantId'], $format, $issuedPdfs);
        }

        return [
            'message' => $success.' issued, '.$failed.' failed, '.$skipped.' skipped.',
            'format' => $format,
            'successCount' => $success,
            'failureCount' => $failed,
            'skippedCount' => $skipped,
            'downloadUrl' => $downloadUrl,
            'downloadToken' => $downloadToken,
            'rows' => $rows,
        ];
    }

    public function download(User $actor, string $token): BinaryFileResponse|StreamedResponse
    {
        $context = $this->issueContext($actor);

        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            abort(404);
        }

        foreach (['zip' => 'application/zip', 'pdf' => 'application/pdf'] as $extension => $mime) {
            $path = 'id-cards/'.$context['tenantId'].'/standalone/bulk/'.$token.'.'.$extension;
            if (! $this->privateBulk($context['tenantId'], $path)) {
                continue;
            }

            $filename = $extension === 'zip' ? 'standalone-id-cards.zip' : 'standalone-id-cards.pdf';

            return Storage::disk('local')->download($path, $filename, [
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Type' => $mime,
            ]);
        }

        abort(404);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function candidates(int $tenantId): array
    {
        return StandaloneIdCard::query()
            ->inCompany($tenantId)
            ->whereIn('status', ['draft', 'active'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (StandaloneIdCard $card): array => $this->presentRow($tenantId, $card))
            ->values()
            ->all();
    }

    /**
     * @param  list<int|string>  $cardIds
     * @return list<array<string, mixed>>
     */
    private function classify(int $tenantId, array $cardIds): array
    {
        $ids = collect($cardIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $rows = [];
        foreach ($ids as $cardId) {
            $card = StandaloneIdCard::query()->inCompany($tenantId)->whereKey($cardId)->first();
            if ($card === null) {
                $rows[] = [
                    'cardId' => $cardId,
                    'cardNumber' => '',
                    'name' => 'Unknown card',
                    'department' => '',
                    'designation' => '',
                    'country' => '',
                    'status' => null,
                    'eligible' => false,
                    'state' => 'missing',
                    'outcome' => 'skipped',
                    'message' => 'Standalone ID card was not found in this company.',
                    'blockers' => ['Standalone ID card was not found in this company.'],
                ];

                continue;
            }

            $rows[] = $this->presentRow($tenantId, $card);
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRow(int $tenantId, StandaloneIdCard $card): array
    {
        $base = [
            'cardId' => (int) $card->id,
            'cardNumber' => (string) $card->card_number,
            'name' => (string) $card->full_name,
            'department' => (string) ($card->department ?? ''),
            'designation' => (string) ($card->designation ?? ''),
            'country' => (string) $card->country,
            'status' => (string) $card->status,
        ];

        if ($card->status === 'active' && $this->hasIssuedPdf($tenantId, $card)) {
            return [
                ...$base,
                'eligible' => false,
                'state' => 'already_issued',
                'outcome' => 'skipped',
                'message' => 'Already issued. Use individual reissue to rotate the QR.',
                'blockers' => ['Already issued. Use individual reissue to rotate the QR.'],
                'pdfUrl' => '/id-cards/standalone/'.$card->id.'/pdf',
            ];
        }

        $readiness = $this->issuer->readiness($tenantId, $card);
        if (! $readiness['ready']) {
            return [
                ...$base,
                'eligible' => false,
                'state' => 'not_ready',
                'outcome' => 'failed',
                'message' => $readiness['blockers'][0] ?? 'Card details are incomplete.',
                'blockers' => $readiness['blockers'],
            ];
        }

        return [
            ...$base,
            'eligible' => true,
            'state' => 'eligible',
            'outcome' => 'pending',
            'message' => 'Ready to issue.',
            'blockers' => [],
        ];
    }

    /**
     * @param  list<array{filename: string, bytes: string, cardId: int}>  $issuedPdfs
     * @return array{0: string, 1: string}
     */
    private function storeBundle(int $tenantId, string $format, array $issuedPdfs): array
    {
        $token = bin2hex(random_bytes(32));

        if ($format === 'combined') {
            $bytes = $this->pdf->merge(array_column($issuedPdfs, 'bytes'));
            $path = 'id-cards/'.$tenantId.'/standalone/bulk/'.$token.'.pdf';
            Storage::disk('local')->put($path, $bytes);

            return [$token, '/id-cards/standalone/bulk/download/'.$token];
        }

        $temporary = tempnam(sys_get_temp_dir(), 'standalone-idcard-zip-');
        $zip = new \ZipArchive;

        if ($temporary === false || $zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            if (is_string($temporary) && $temporary !== '') {
                @unlink($temporary);
            }

            throw ValidationException::withMessages(['format' => 'Could not prepare the bulk ZIP download.']);
        }

        try {
            foreach ($issuedPdfs as $file) {
                $zip->addFromString($file['filename'], $file['bytes']);
            }
            $zip->close();

            $path = 'id-cards/'.$tenantId.'/standalone/bulk/'.$token.'.zip';
            Storage::disk('local')->put($path, (string) file_get_contents($temporary));
        } finally {
            @unlink($temporary);
        }

        return [$token, '/id-cards/standalone/bulk/download/'.$token];
    }

    private function hasIssuedPdf(int $tenantId, StandaloneIdCard $card): bool
    {
        return $this->latestPdfPath($tenantId, (int) $card->id) !== null;
    }

    private function latestPdfPath(int $tenantId, int $cardId): ?string
    {
        $path = StandaloneIdCardIssue::query()
            ->inCompany($tenantId)
            ->where('id_card_id', $cardId)
            ->orderByDesc('id')
            ->value('pdf_path');

        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return null;
        }

        if (! str_starts_with($path, 'id-cards/'.$tenantId.'/standalone/pdfs/')) {
            return null;
        }

        return Storage::disk('local')->exists($path) ? $path : null;
    }

    private function pdfFilename(string $code, int $cardId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $code) ?: ('card-'.$cardId);

        return $safe.'-id-card.pdf';
    }

    private function privateBulk(int $tenantId, mixed $path): bool
    {
        if (! is_string($path) || $path === '' || str_contains($path, '..')) {
            return false;
        }

        if (! str_starts_with($path, 'id-cards/'.$tenantId.'/standalone/bulk/')) {
            return false;
        }

        return Storage::disk('local')->exists($path);
    }

    /**
     * @return array{tenantId: int, userId: int}
     */
    private function issueContext(User $actor): array
    {
        $role = AuthenticatedWorkspace::resolve($actor)['role'];
        $permissions = CompanyRole::permissions(is_string($role) ? $role : '');

        if (($permissions['idCardsIssue'] ?? false) !== true || $actor->companyId() === null) {
            abort(403);
        }

        if (! Schema::hasTable('standalone_id_cards')) {
            abort(404);
        }

        return [
            'tenantId' => (int) $actor->companyId(),
            'userId' => (int) $actor->id,
        ];
    }
}
