<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class BunnyCdnService
{
    /**
     * Upload a file to Bunny Storage and return the Pull Zone URL.
     *
     * @param UploadedFile $file      The local file to upload
     * @param string       $destPath  The destination path including filename (e.g. "menu/2025/08/25/uuid.jpg")
     * @param string|null  $mime      Optional mime-type override (defaults to file's mime)
     * @return string                 Public CDN URL
     */
    public function upload(UploadedFile $file, string $destPath, ?string $mime = null): string
    {
        $zone      = env('BUNNYCDN_STORAGE_ZONE');
        $apiKey    = env('BUNNYCDN_API_KEY');
        $region    = env('BUNNYCDN_REGION', 'storage'); // e.g. storage, sg, ny, la, de
        $host      = env('BUNNYCDN_HOST', 'storage.bunnycdn.com'); // usually storage.bunnycdn.com
        $pullBase  = rtrim(env('BUNNYCDN_PULL_ZONE_URL'), '/');

        if (!$zone || !$apiKey || !$pullBase) {
            throw new RuntimeException('BunnyCDN env not configured (BUNNYCDN_STORAGE_ZONE, BUNNYCDN_API_KEY, BUNNYCDN_PULL_ZONE_URL).');
        }

        $storageUrl = sprintf(
            'https://%s.%s/%s/%s',
            $region,
            $host,
            $zone,
            ltrim($destPath, '/')
        );

        $mime = $mime ?: ($file->getMimeType() ?: 'application/octet-stream');
        $bytes = file_get_contents($file->getRealPath());
        if ($bytes === false) {
            throw new RuntimeException('Unable to read file for Bunny upload.');
        }

        $res = Http::timeout(120)
            ->withHeaders([
                'AccessKey'      => $apiKey,
                'Content-Type'   => $mime,
                'Content-Length' => strlen($bytes),
            ])
            ->withBody($bytes, $mime)
            ->put($storageUrl);

        if (!$res->successful()) {
            throw new RuntimeException('Bunny upload failed: '.$res->status().' '.$res->body());
        }

        return $pullBase . '/' . ltrim($destPath, '/');
    }
}