<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class MenuImageService
{
    public function __construct(
        private ?string $apiKey = null,
        private ?string $baseUrl = null,
        private ?string $model   = null,
        private int $timeout     = 90,
    ) {
        $this->apiKey = $this->apiKey ?: (string) env('OPENROUTER_API_KEY');
        if (!$this->apiKey) {
            throw new \Exception('OpenRouter API key is not configured');
        }
        $this->baseUrl = $this->baseUrl ?: (string) env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1');
        $this->model   = $this->model   ?: (string) env('OPENROUTER_IMAGE_MODEL', 'openai/gpt-image-1');
    }

    /**
     * Generate a realistic image for a menu item and save as 500x300 JPG.
     * Returns ['url' => public_url, 'path' => storage_path, 'filename' => ...]
     */
    public function generate(string $itemName, ?string $cuisine = null, ?string $notes = null): array
    {
        $prompt = $this->buildPrompt($itemName, $cuisine, $notes);

        // Some models don’t support 500x300 directly. Generate larger then downscale.
        $genSize = '1024x614'; // ~similar aspect ratio to 500x300
        $targetW = 500;
        $targetH = 300;

        $payload = [
            'model'            => $this->model,
            'prompt'           => $prompt,
            'size'             => $genSize,
            'n'                => 1,
            'response_format'  => 'b64_json',
            // 'quality' => 'high', // uncomment if the model supports it
        ];

        $resp = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->apiKey,
            'HTTP-Referer'  => request()->getSchemeAndHttpHost(),
            'X-Title'       => 'Menu Item Image Generator',
        ])->timeout($this->timeout)
          ->post(rtrim($this->baseUrl, '/').'/images', $payload);

        if (!$resp->successful()) {
            throw new \RuntimeException('Image API error: '.$resp->body());
        }

        $data = $resp->json('data.0.b64_json');
        if (!$data) {
            throw new \RuntimeException('Image API did not return b64 image data.');
        }

        $binary = base64_decode($data);
        if ($binary === false) {
            throw new \RuntimeException('Failed to decode image b64.');
        }

        // Resize to exactly 500x300 (letterbox crop if needed)
        $resized = $this->resizeTo500x300($binary, $targetW, $targetH);

        // Save to public storage
        $slug = Str::slug($itemName);
        $filename = $slug.'-'.time().'.jpg';
        $diskPath = 'menu_images/'.$filename;

        Storage::disk('public')->put($diskPath, $resized, 'public');

        return [
            'url'      => asset('storage/'.$diskPath),
            'path'     => Storage::disk('public')->path($diskPath),
            'filename' => $filename,
        ];
    }

    private function buildPrompt(string $itemName, ?string $cuisine, ?string $notes): string
    {
        $parts = [];
        $parts[] = "Ultra-realistic photograph of \"{$itemName}\" plated appetizingly";
        if ($cuisine) {
            $parts[] = "{$cuisine} cuisine styling";
        }
        $parts[] = "natural lighting, shallow depth of field, restaurant presentation";
        $parts[] = "neutral background (wood or stone), no text, no logos, no watermark";
        $parts[] = "high detail, crisp focus on the dish";
        if ($notes) {
            $parts[] = $notes;
        }
        return implode(', ', $parts).'.';
    }

    /**
     * Resize/crop to exactly 500x300.
     * Uses Imagick if present; else falls back to GD.
     */
    private function resizeTo500x300(string $binary, int $targetW, int $targetH): string
    {
        if (class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick();
                $im->readImageBlob($binary);
                $im->setImageColorspace(\Imagick::COLORSPACE_RGB);
                $im->setImageFormat('jpeg');
                $im->setImageCompression(\Imagick::COMPRESSION_JPEG);
                $im->setImageCompressionQuality(88);

                // cover/crop to exact size
                $im->cropThumbnailImage($targetW, $targetH);

                $out = $im->getImageBlob();
                $im->destroy();
                return $out;
            } catch (\Throwable $e) {
                Log::warning('Imagick resize failed, falling back to GD', ['e' => $e->getMessage()]);
            }
        }

        // ---- GD fallback ----
        $src = @imagecreatefromstring($binary);
        if (!$src) {
            throw new \RuntimeException('Failed to create image from binary (GD).');
        }
        $srcW = imagesx($src);
        $srcH = imagesy($src);

        // cover: compute crop that fills 500x300
        $targetRatio = $targetW / $targetH;
        $srcRatio = $srcW / $srcH;

        if ($srcRatio > $targetRatio) {
            // source is wider; crop width
            $newH = $srcH;
            $newW = (int) round($srcH * $targetRatio);
            $srcX = (int) floor(($srcW - $newW) / 2);
            $srcY = 0;
        } else {
            // source is taller; crop height
            $newW = $srcW;
            $newH = (int) round($srcW / $targetRatio);
            $srcX = 0;
            $srcY = (int) floor(($srcH - $newH) / 2);
        }

        $dst = imagecreatetruecolor($targetW, $targetH);
        imagecopyresampled($dst, $src, 0, 0, $srcX, $srcY, $targetW, $targetH, $newW, $newH);

        ob_start();
        imagejpeg($dst, null, 88);
        $out = ob_get_clean();

        imagedestroy($src);
        imagedestroy($dst);

        if ($out === false) {
            throw new \RuntimeException('Failed to encode JPEG (GD).');
        }

        return $out;
    }
}