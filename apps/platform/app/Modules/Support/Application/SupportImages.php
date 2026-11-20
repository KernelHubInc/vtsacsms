<?php

declare(strict_types=1);

namespace App\Modules\Support\Application;

use App\Modules\Support\Domain\Models\SupportTicket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class SupportImages
{
    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return ['images' => ['sometimes', 'array', 'max:5'],
            'images.*' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png', 'max:2048', 'dimensions:max_width=3000,max_height=3000']];
    }

    /**
     * @param  list<UploadedFile>  $files
     * @return list<array{content:string,mime_type:string,size_bytes:int,digest:string}>
     */
    public function prepare(array $files): array
    {
        Validator::make(['images' => $files], self::rules())->validate();
        $images = [];
        foreach ($files as $position => $file) {
            $raw = file_get_contents($file->getPathname());
            if ($raw === false || ($image = @imagecreatefromstring($raw)) === false) {
                throw ValidationException::withMessages(['images.'.$position => 'This image could not be read. Choose a JPEG or PNG image.']);
            }
            $mime = $file->getMimeType();
            if ($mime === 'image/png') {
                imagesavealpha($image, true);
            }
            ob_start();
            try {
                // Re-encode pixels to remove EXIF location and appended/polyglot content.
                $written = $mime === 'image/png' ? imagepng($image) : imagejpeg($image, null, 85);
                $content = (string) ob_get_contents();
            } finally {
                ob_end_clean();
                unset($image);
            }
            if (! $written || strlen($content) > 2 * 1024 * 1024) {
                throw ValidationException::withMessages(['images.'.$position => 'This image is too large after processing. Choose a smaller image.']);
            }
            $images[] = ['content' => $content, 'mime_type' => (string) $mime, 'size_bytes' => strlen($content), 'digest' => hash('sha256', $content)];
        }

        return $images;
    }

    /** @param list<array{content:string,mime_type:string,size_bytes:int,digest:string}> $images */
    public function save(SupportTicket $ticket, array $images): void
    {
        foreach ($images as $position => $image) {
            $ticket->images()->create(['position' => $position, 'mime_type' => $image['mime_type'],
                'size_bytes' => $image['size_bytes'], 'content' => $image['content']]);
        }
    }
}
