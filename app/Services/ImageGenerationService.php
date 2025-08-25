<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class ImageGenerationService
{
    private BunnyCdnService $bunnyCdnService;

    public function __construct(BunnyCdnService $bunnyCdnService)
    {
        $this->bunnyCdnService = $bunnyCdnService;
    }

    /**
     * Generate an image using Vyro AI API and upload to Bunny CDN
     *
     * @param string $prompt The image generation prompt (just the food item name)
     * @param string $style Style for the image (default: "photographic")
     * @param string $aspectRatio Aspect ratio (default: "1:1")
     * @param int|null $seed Random seed for generation (null for random)
     * @param string|null $uploadPath Custom upload path (optional)
     * @param bool $isMenuItem Whether this is for a menu item (adds food-specific prompting)
     * @return array Contains 'cdn_url' and other metadata
     * @throws RuntimeException
     */
    public function generateAndUpload(
        string $prompt,
        string $style = 'photographic',
        string $aspectRatio = '1:1',
        ?int $seed = null,
        ?string $uploadPath = null,
        bool $isMenuItem = true
    ): array {
        // Generate random seed if not provided to ensure different images
        if ($seed === null) {
            $seed = rand(1, 9999999);
        }

        // Enhance prompt for menu items with food photography keywords
        if ($isMenuItem) {
            $enhancedPrompt = $this->enhanceFoodPrompt($prompt);
        } else {
            $enhancedPrompt = $prompt;
        }

        // Step 1: Generate image using Vyro AI
        $imageData = $this->generateImageWithCurl($enhancedPrompt, $style, $aspectRatio, $seed);
        
        // Step 2: Save temporarily to local storage
        $tempFileName = $this->saveTempImage($imageData);
        
        // Step 3: Upload to Bunny CDN
        $cdnUrl = $this->uploadToBunnyCdn($tempFileName, $uploadPath);
        
        // Step 4: Clean up temporary file
        $this->cleanupTempFile($tempFileName);
        
        return [
            'cdn_url' => $cdnUrl,
            'original_prompt' => $prompt,
            'enhanced_prompt' => $enhancedPrompt ?? $prompt,
            'style' => $style,
            'aspect_ratio' => $aspectRatio,
            'seed' => $seed,
            'is_menu_item' => $isMenuItem
        ];
    }

    /**
     * Generate image using Vyro AI API
     */
    private function generateImage(string $prompt, string $style, string $aspectRatio, ?int $seed): string
    {
        $apiKey = env('VYRO_API_KEY', 'vk-tUbO0mTh6roLpFFdEZNzsAN2EFH2YmhQDue3XJfqrIwm8Yh');
        
        if (!$apiKey) {
            throw new RuntimeException('VYRO_API_KEY not configured in environment');
        }

        $httpClient = Http::timeout(120)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
            ]);

        // Build form data properly for multipart
        $httpClient->attach('prompt', $prompt);
        $httpClient->attach('style', $style);
        $httpClient->attach('aspect_ratio', $aspectRatio);

        // Add seed if provided
        if ($seed !== null) {
            $httpClient->attach('seed', (string)$seed);
        }

        $response = $httpClient->post('https://api.vyro.ai/v2/image/generations');

        if (!$response->successful()) {
            throw new RuntimeException(
                'Vyro AI API request failed: ' . $response->status() . ' ' . $response->body()
            );
        }

        $responseData = $response->json();
        
        // Handle different possible response formats
        if (isset($responseData['data'][0]['url'])) {
            return $this->downloadImageFromUrl($responseData['data'][0]['url']);
        } elseif (isset($responseData['image_url'])) {
            return $this->downloadImageFromUrl($responseData['image_url']);
        } elseif (isset($responseData['url'])) {
            return $this->downloadImageFromUrl($responseData['url']);
        } else {
            // If response contains raw image data
            $imageData = $response->body();
            if (empty($imageData) || !$this->isValidImageData($imageData)) {
                throw new RuntimeException('Invalid image data received from Vyro AI API');
            }
            return $imageData;
        }
    }

    /**
     * Enhance food item prompt for better menu photography
     */
    private function enhanceFoodPrompt(string $foodItem): string
    {
        // Clean the food item name
        $foodItem = trim($foodItem);
        
        // Food photography enhancement keywords
        $enhancements = [
            'professional food photography',
            'appetizing',
            'fresh',
            'restaurant quality',
            'well-lit',
            'clean white background',
            'high resolution',
            'detailed texture',
            'vibrant colors',
            'commercial photography style'
        ];
        
        // Randomly select 3-4 enhancement keywords to vary the style
        $selectedEnhancements = array_rand(array_flip($enhancements), rand(3, 4));
        $enhancementText = implode(', ', $selectedEnhancements);
        
        // Build enhanced prompt
        return "{$foodItem}, {$enhancementText}";
    }

    /**
     * Alternative method using cURL directly for Vyro AI API
     */
    private function generateImageWithCurl(string $prompt, string $style, string $aspectRatio, ?int $seed): string
    {
        $apiKey = env('VYRO_API_KEY', 'vk-tUbO0mTh6roLpFFdEZNzsAN2EFH2YmhQDue3XJfqrIwm8Yh');
        
        if (!$apiKey) {
            throw new RuntimeException('VYRO_API_KEY not configured in environment');
        }

        $postFields = [
            'prompt' => $prompt,
            'style' => $style,
            'aspect_ratio' => $aspectRatio,
        ];

        if ($seed !== null) {
            $postFields['seed'] = (string)$seed;
        }

        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.vyro.ai/v2/image/generations',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        
        curl_close($curl);

        if ($error) {
            throw new RuntimeException('cURL error: ' . $error);
        }

        if ($httpCode !== 200) {
            throw new RuntimeException('Vyro AI API request failed: ' . $httpCode . ' ' . $response);
        }

        // Try to decode as JSON first
        $responseData = json_decode($response, true);
        
        if (json_last_error() === JSON_ERROR_NONE) {
            // Handle JSON response with image URL
            if (isset($responseData['data'][0]['url'])) {
                return $this->downloadImageFromUrl($responseData['data'][0]['url']);
            } elseif (isset($responseData['image_url'])) {
                return $this->downloadImageFromUrl($responseData['image_url']);
            } elseif (isset($responseData['url'])) {
                return $this->downloadImageFromUrl($responseData['url']);
            } else {
                throw new RuntimeException('No image URL found in API response');
            }
        } else {
            // Response might be raw image data
            if ($this->isValidImageData($response)) {
                return $response;
            } else {
                throw new RuntimeException('Invalid response from Vyro AI API');
            }
        }
    }
    private function downloadImageFromUrl(string $url): string
    {
        $response = Http::timeout(60)->get($url);
        
        if (!$response->successful()) {
            throw new RuntimeException('Failed to download generated image from URL: ' . $url);
        }

        $imageData = $response->body();
        
        if (!$this->isValidImageData($imageData)) {
            throw new RuntimeException('Downloaded image data is invalid');
        }

        return $imageData;
    }

    /**
     * Check if data is valid image data
     */
    private function isValidImageData(string $data): bool
    {
        // Check for common image file signatures
        $signatures = [
            "\xFF\xD8\xFF", // JPEG
            "\x89PNG\r\n\x1A\n", // PNG
            "GIF87a", // GIF87a
            "GIF89a", // GIF89a
            "RIFF", // WebP (starts with RIFF)
        ];

        foreach ($signatures as $signature) {
            if (strpos($data, $signature) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Save image data to temporary file
     */
    private function saveTempImage(string $imageData): string
    {
        $extension = $this->getImageExtension($imageData);
        $fileName = 'temp_generated_' . Str::uuid() . '.' . $extension;
        $tempPath = storage_path('app/temp/' . $fileName);
        
        // Ensure temp directory exists
        $tempDir = dirname($tempPath);
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        if (file_put_contents($tempPath, $imageData) === false) {
            throw new RuntimeException('Failed to save temporary image file');
        }

        return $tempPath;
    }

    /**
     * Determine image extension from binary data
     */
    private function getImageExtension(string $data): string
    {
        if (strpos($data, "\xFF\xD8\xFF") === 0) {
            return 'jpg';
        } elseif (strpos($data, "\x89PNG\r\n\x1A\n") === 0) {
            return 'png';
        } elseif (strpos($data, "GIF87a") === 0 || strpos($data, "GIF89a") === 0) {
            return 'gif';
        } elseif (strpos($data, "RIFF") === 0 && strpos($data, "WEBP") !== false) {
            return 'webp';
        }
        
        return 'jpg'; // Default fallback
    }

    /**
     * Upload to Bunny CDN using the existing service
     */
    private function uploadToBunnyCdn(string $tempFilePath, ?string $customPath = null): string
    {
        // Create an UploadedFile instance from the temp file
        $uploadedFile = new \Illuminate\Http\UploadedFile(
            $tempFilePath,
            basename($tempFilePath),
            mime_content_type($tempFilePath),
            null,
            true // Mark as test file so it doesn't validate as uploaded via HTTP
        );

        // Generate upload path if not provided (menu-specific structure)
        if (!$customPath) {
            $date = now();
            $uuid = Str::uuid();
            $extension = pathinfo($tempFilePath, PATHINFO_EXTENSION);
            
            // Create menu-specific folder structure
            $cleanFoodName = $this->sanitizeFileName(basename($tempFilePath, '.' . $extension));
            $customPath = sprintf(
                'menu-items/%s/%s/%s/%s-%s.%s',
                $date->year,
                $date->format('m'),
                $date->format('d'),
                $cleanFoodName,
                $uuid,
                $extension
            );
        }

        return $this->bunnyCdnService->upload($uploadedFile, $customPath);
    }

    /**
     * Sanitize filename for food items
     */
    private function sanitizeFileName(string $filename): string
    {
        // Remove or replace special characters
        $filename = strtolower($filename);
        $filename = preg_replace('/[^a-z0-9\-_]/', '-', $filename);
        $filename = preg_replace('/-+/', '-', $filename);
        $filename = trim($filename, '-');
        
        return substr($filename, 0, 50); // Limit length
    }

    /**
     * Clean up temporary file
     */
    private function cleanupTempFile(string $tempFilePath): void
    {
        if (file_exists($tempFilePath)) {
            unlink($tempFilePath);
        }
    }
}