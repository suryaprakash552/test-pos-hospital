<?php

namespace App\Helpers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

trait HasUploader
{
    private function upload(Request $request, $input, $oldFile = null, $disk = null)
{
    $disk = $disk ?? config('filesystems.default');
    $path = 'uploads/' . date('y') . '/' . date('m');

    if ($oldFile) {
        Storage::disk($disk)->delete($oldFile);
    }

    // ✅ handle multipart file properly
    if ($request->hasFile($input) && $request->file($input)->isValid()) {
        $file = $request->file($input);

        // Validate file size (max 5MB)
        if ($file->getSize() > 5 * 1024 * 1024) {
            \Log::error("File size exceeds limit: " . $file->getSize());
            return null;
        }

        // Validate MIME type
        $allowedMimes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowedMimes)) {
            \Log::error("Invalid MIME type: " . $file->getMimeType());
            return null;
        }

        $filename = time() . '-' . uniqid() . '.' . $file->getClientOriginalExtension();
        $filePath = $file->storeAs($path, $filename, $disk);

        return $filePath;
    }

    // base64 fallback
    $base64 = $request->input($input);
    if (!$base64 || !is_string($base64)) return null;

    if (str_contains($base64, 'base64,')) {
        $base64 = explode('base64,', $base64)[1];
    }

    $base64 = str_replace(' ', '+', $base64);
    $imageData = base64_decode($base64);

    if (!$imageData) return null;

    // Validate base64 data size (max 5MB)
    if (strlen($imageData) > 5 * 1024 * 1024) {
        \Log::error("Base64 image size exceeds limit");
        return null;
    }

    // Validate that it's actually an image
    $imageInfo = @getimagesizefromstring($imageData);
    if (!$imageInfo) {
        \Log::error("Invalid base64 image data");
        return null;
    }

    // Determine extension from image type
    $extensions = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_GIF => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];
    $extension = $extensions[$imageInfo[2]] ?? 'jpg';

    $filename = time() . '-' . uniqid() . '.' . $extension;
    $filePath = $path . $filename;

    Storage::disk($disk)->put($filePath, $imageData);

    return $filePath;
}


    private function uploadWithFileName(Request $request, $input, $oldFile = null, $disk = null)
    {
        $file = $request->file($input);
        $filename = $file->getClientOriginalName();

        $path = 'files/';
        $filePath = $path.$filename;

        if($oldFile) {
            if (file_exists($oldFile)) {
                Storage::delete($oldFile);
            }
        }

        Storage::disk($disk ?? config('filesystems.default'))->put($filePath, file_get_contents($file));
        return $filePath;
    }

    private function multipleUpload(Request $request, $input, $oldFiles = [], $disk = null)
    {
         $uploadedFiles = [];

        foreach ($request->file($input) as $file) {
            $ext = $file->getClientOriginalExtension();
            $filename = now()->timestamp . '_' . uniqid() . '.' . $ext;

            $path = 'uploads/' . date('y') . '/' . date('m') . '/';
            $filePath = $path . $filename;

            foreach ($oldFiles as $oldFile) {
                if (file_exists($oldFile)) {
                    Storage::delete($oldFile);
                }
            }

            Storage::disk($disk ?? config('filesystems.default'))->put($filePath, file_get_contents($file));
            $uploadedFiles[] = $filePath;
        }

        return $uploadedFiles;
    }


    private function uploadSignature(
    Request $request,
    $input = 'signature',
    $oldFile = null,
    $disk = null
) {
    $disk = $disk ?? config('filesystems.default');

    $path = 'uploads/' . date('y') . '/' . date('m') . '/';

    $file = null;

    // Multipart file
    if ($request->hasFile($input) && $request->file($input)->isValid()) {
        $file = $request->file($input);
    }

    // Base64 fallback
    if (!$file) {
        $base64 = $request->input($input);

        if (!$base64 || !is_string($base64)) {
            return null;
        }

        if (str_contains($base64, 'base64,')) {
            $base64 = explode('base64,', $base64, 2)[1];
        }

        $base64 = str_replace(' ', '+', $base64);

        $imageData = base64_decode($base64, true);

        if ($imageData === false) {
            return null;
        }

        if (strlen($imageData) > 5 * 1024 * 1024) {
            \Log::error('Signature image exceeds 5MB.');
            return null;
        }

        $file = $imageData;
    }

    $image = null;

    try {

        /*
         * Load image.
         */
        if ($file instanceof \Illuminate\Http\UploadedFile) {
            $image = new \Imagick($file->getRealPath());
        } else {
            $image = new \Imagick();
            $image->readImageBlob($file);
        }

        $image->setIteratorIndex(0);

        /*
         * Remove any existing alpha channel.
         */
        $image->setImageAlphaChannel(
            \Imagick::ALPHACHANNEL_REMOVE
        );

        /*
         * Convert image to grayscale.
         *
         * Dark signature = low value
         * Light background = high value
         */
        $image->transformImageColorspace(
            \Imagick::COLORSPACE_GRAY
        );

        /*
         * Create alpha channel from grayscale.
         *
         * We negate the grayscale image:
         *
         * White background -> black alpha -> transparent
         * Dark signature   -> white alpha -> visible
         */
        $alpha = clone $image;

        $alpha->negateImage(false);

        /*
         * Increase contrast so the signature remains
         * visible while the light background becomes
         * transparent.
         */
        $alpha->contrastImage(true);
        $alpha->contrastImage(true);

        /*
         * Apply alpha channel.
         */
        $image->setImageAlphaChannel(
            \Imagick::ALPHACHANNEL_SET
        );

        $image->setImageChannelMask(
            \Imagick::CHANNEL_ALPHA
        );

        $image->compositeImage(
            $alpha,
            \Imagick::COMPOSITE_COPYOPACITY,
            0,
            0
        );

        /*
         * Restore normal channel mask.
         */
        $image->setImageChannelMask(
            \Imagick::CHANNEL_DEFAULT
        );

        /*
         * Convert to PNG.
         */
        $image->setImageFormat('png');

        /*
         * Generate filename.
         */
        $filename = time() . '-' . uniqid('signature_') . '.png';

        $filePath = $path . $filename;

        /*
         * Store processed PNG.
         */
        $stored = Storage::disk($disk)->put(
            $filePath,
            $image->getImageBlob()
        );

        if (!$stored) {
            throw new \RuntimeException(
                'Failed to store processed signature.'
            );
        }

        /*
         * Delete old signature only after
         * successful upload.
         */
        if ($oldFile) {
            Storage::disk($disk)->delete($oldFile);
        }

        return $filePath;

    } catch (\Throwable $e) {

        \Log::error('Signature processing failed', [
            'message' => $e->getMessage(),
            'input' => $input,
        ]);

        return null;

    } finally {

        if ($image instanceof \Imagick) {
            $image->clear();
            $image->destroy();
        }

        if (isset($alpha) && $alpha instanceof \Imagick) {
            $alpha->clear();
            $alpha->destroy();
        }
    }
}

}
