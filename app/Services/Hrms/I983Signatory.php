<?php

namespace App\Services\Hrms;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class I983Signatory
{
    /**
     * @return array{name: string, title: string, hasImage: bool}
     */
    public function present(int $tenantId): array
    {
        $row = $this->row($tenantId);

        return [
            'name' => trim((string) ($row->signatory_name ?? '')),
            'title' => trim((string) ($row->signatory_title ?? '')),
            'hasImage' => $row !== null && is_string($row->image_path) && $row->image_path !== '' && Storage::disk('local')->exists($row->image_path),
        ];
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function image(int $tenantId): array
    {
        $row = $this->row($tenantId);
        $path = (string) ($row->image_path ?? '');
        $bytes = $path === '' ? null : Storage::disk('local')->get($path);

        if (! is_string($bytes) || $bytes === '') {
            abort(404);
        }

        return [
            'bytes' => $bytes,
            'mime' => str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg',
        ];
    }

    public function save(User $actor, string $name, string $title, ?UploadedFile $file): void
    {
        $tenantId = (int) $actor->companyId();
        $name = trim($name);
        $title = trim($title);
        $errors = [];

        if ($name === '') {
            $errors['signatory_name'] = 'Enter the authorized signatory\'s full name.';
        }

        if ($title === '') {
            $errors['signatory_title'] = 'Enter the signatory\'s official title.';
        }

        $current = $this->row($tenantId);

        if ($file === null && ($current === null || $current->image_path === null || $current->image_path === '')) {
            $errors['signature'] = 'Upload a PNG or JPEG signature image.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $path = $current->image_path ?? null;
        $mime = $current->image_mime ?? null;
        $width = $current->image_width ?? null;
        $height = $current->image_height ?? null;

        if ($file !== null) {
            $stored = $this->storeImage($tenantId, $file);
            if (is_string($path) && $path !== '' && $path !== $stored['path']) {
                Storage::disk('local')->delete($path);
            }
            $path = $stored['path'];
            $mime = $stored['mime'];
            $width = $stored['width'];
            $height = $stored['height'];
        }

        $values = [
            'signatory_name' => mb_substr($name, 0, 160),
            'signatory_title' => mb_substr($title, 0, 120),
            'image_path' => $path,
            'image_mime' => $mime,
            'image_width' => $width,
            'image_height' => $height,
            'updated_by' => $actor->id,
            'updated_at' => now(),
        ];

        if ($current === null) {
            DB::table('i983_signatories')->insert([
                ...$values,
                'tenant_id' => $tenantId,
                'created_at' => now(),
            ]);
        } else {
            DB::table('i983_signatories')->where('id', $current->id)->update($values);
        }

        DB::table('i983_events')->insert([
            'tenant_id' => $tenantId,
            'case_id' => null,
            'actor_id' => $actor->id,
            'actor_type' => 'hr',
            'action' => 'signature_uploaded',
            'subject' => 'signatory',
            'created_at' => now(),
        ]);
    }

    /**
     * @return array{name: string, title: string, path: string, stored: string}
     */
    public function ready(int $tenantId): array
    {
        $row = $this->row($tenantId);
        $path = (string) ($row->image_path ?? '');
        $absolute = $path === '' ? '' : Storage::disk('local')->path($path);

        if ($row === null || trim((string) $row->signatory_name) === '' || trim((string) $row->signatory_title) === '' || $absolute === '' || ! is_file($absolute)) {
            throw ValidationException::withMessages([
                'signature' => 'Upload the employer signature and enter the signatory name and title before applying it.',
            ]);
        }

        return [
            'name' => trim((string) $row->signatory_name),
            'title' => trim((string) $row->signatory_title),
            'path' => $absolute,
            'stored' => $path,
        ];
    }

    private function row(int $tenantId): ?object
    {
        if (! DB::getSchemaBuilder()->hasTable('i983_signatories')) {
            return null;
        }

        return DB::table('i983_signatories')->where('tenant_id', $tenantId)->first();
    }

    /**
     * @return array{path: string, mime: string, width: int, height: int}
     */
    private function storeImage(int $tenantId, UploadedFile $file): array
    {
        $info = @getimagesize($file->getPathname());
        $type = is_array($info) ? ($info[2] ?? 0) : 0;

        if (! in_array($type, [IMAGETYPE_PNG, IMAGETYPE_JPEG], true)) {
            throw ValidationException::withMessages([
                'signature' => 'Upload a PNG or JPEG signature image.',
            ]);
        }

        $width = (int) $info[0];
        $height = (int) $info[1];

        if ($width < 40 || $width > 2000 || $height < 15 || $height > 800) {
            throw ValidationException::withMessages([
                'signature' => 'Use a signature image between 40 and 2000 pixels wide and 15 and 800 pixels tall.',
            ]);
        }

        $extension = $type === IMAGETYPE_PNG ? 'png' : 'jpg';
        $path = 'i983/'.$tenantId.'/signatory.'.$extension;
        Storage::disk('local')->put($path, (string) file_get_contents($file->getPathname()));

        return [
            'path' => $path,
            'mime' => $type === IMAGETYPE_PNG ? 'image/png' : 'image/jpeg',
            'width' => $width,
            'height' => $height,
        ];
    }
}
