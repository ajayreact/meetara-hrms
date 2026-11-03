<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

class I983StudentSignature
{
    /**
     * Each drawing is a distinct image so a later case is not rejected as a reused file.
     *
     * @return array<string, mixed>
     */
    public static function fields(): array
    {
        $image = imagecreatetruecolor(680, 48);
        $ink = imagecolorallocate($image, random_int(0, 180), random_int(0, 180), random_int(0, 180));
        imagefilledrectangle($image, random_int(8, 80), 12, random_int(220, 560), 34, $ink);
        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.'/student-signature-'.bin2hex(random_bytes(8)).'.png';
        imagepng($image, $path);
        imagedestroy($image);

        return [
            'confirm_certification' => '1',
            'confirm_name' => '1',
            'signature' => new UploadedFile($path, 'student-signature.png', 'image/png', null, true),
        ];
    }
}
