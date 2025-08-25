<?php

namespace App\Http\Controllers;

use App\Services\BunnyCdnService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ImageGenController extends Controller
{
   public function generate(Request $request, BunnyCdnService $bunny)
{
    $data = $request->validate([
        'prompt'        => ['required','string','max:1000'],
        'style'         => ['nullable','string', Rule::in(['realistic','photographic','digital-art','anime','cinematic'])],
        'aspect_ratio'  => ['nullable','string'], // "1:1","3:4","16:9"
        'seed'          => ['nullable','integer','min:0'],
        'path_prefix'   => ['nullable','string'],
    ]);

    $prompt       = $data['prompt'];
    $style        = $data['style']        ?? 'realistic';
    $aspect_ratio = $data['aspect_ratio'] ?? '1:1';
    $seed         = (string)($data['seed'] ?? 5);
    $prefix       = trim($data['path_prefix'] ?? 'menu', '/');

    $prompt = "Realistic food photo: ".$prompt." served on a single plate, freshly prepared, on a wooden table, natural lighting, shallow depth of field, delivery app style presentation. No text, no watermark.";

    // --- 1) Call Vyro/Imagine with plain multipart *fields* (no attach) ---
    try {
        $ai = Http::timeout(180)
            ->retry(2, 1000)
            ->accept('*/*')
            ->withToken(env('IMAGINE_API_KEY')) // put your vk-... key in .env
            ->asMultipart()
            ->post('https://api.vyro.ai/v2/image/generations', [
                // DO NOT use ->attach() for these – send as plain fields:
                'prompt'       => $prompt,
                'style'        => $style,
                'aspect_ratio' => $aspect_ratio,
                'seed'         => $seed,
            ]);
    } catch (\Illuminate\Http\Client\RequestException $e) {
        return response()->json([
            'ok'     => false,
            'stage'  => 'ai',
            'status' => optional($e->response)->status(),
            'error'  => optional($e->response)->body() ?: $e->getMessage(),
        ], 502);
    }

    if (!$ai->successful()) {
        // Bubble up Vyro’s JSON/text error so you can see exactly why it’s 422
        $ct = strtolower($ai->header('Content-Type', ''));
        $payload = str_contains($ct, 'application/json') ? $ai->json() : $ai->body();

        return response()->json([
            'ok'      => false,
            'stage'   => 'ai',
            'status'  => $ai->status(),
            'error'   => $payload,
            'headers' => $ai->headers(),
        ], 502);
    }

    $imageBytes = $ai->body();
    if (!$imageBytes) {
        return response()->json([
            'ok'    => false,
            'stage' => 'ai',
            'error' => 'Empty image response',
        ], 502);
    }

    // --- 2) Infer mime/ext ---
    $respMime = strtolower($ai->header('Content-Type', 'image/jpeg'));
    $ext = 'jpg';
    if (str_contains($respMime, 'png'))  $ext = 'png';
    if (str_contains($respMime, 'webp')) $ext = 'webp';

    if (str_starts_with($imageBytes, "\x89PNG")) {
        $respMime = 'image/png';  $ext = 'png';
    } elseif (str_starts_with($imageBytes, "RIFF") && str_contains($imageBytes, "WEBP")) {
        $respMime = 'image/webp'; $ext = 'webp';
    } else {
        if (!in_array($ext, ['png','webp'])) { $respMime = 'image/jpeg'; $ext = 'jpg'; }
    }

    // --- 3) Temp file -> UploadedFile ---
    $tmpPath = sys_get_temp_dir() . '/' . (string) Str::uuid() . '.' . $ext;
    file_put_contents($tmpPath, $imageBytes);

    $uploadedFile = new \Illuminate\Http\UploadedFile(
        $tmpPath,
        basename($tmpPath),
        $respMime,
        null,
        true
    );

    // --- 4) Bunny path + upload ---
    $destPath = sprintf(
        '%s/%s/%s/%s/%s.%s',
        $prefix,
        now()->format('Y'),
        now()->format('m'),
        now()->format('d'),
        (string) Str::uuid(),
        $ext
    );

    try {
        $cdnUrl = $bunny->upload($uploadedFile, $destPath, $respMime);
    } finally {
        if (is_file($tmpPath)) @unlink($tmpPath);
    }

    return response()->json([
        'ok'         => true,
        'cdn_url'    => $cdnUrl,
        'path'       => $destPath,
        'mime'       => $respMime,
        'size_bytes' => strlen($imageBytes),
        'meta'       => [
            'style'         => $style,
            'aspect_ratio'  => $aspect_ratio,
            'seed'          => (int) $seed,
        ],
    ]);
}
}