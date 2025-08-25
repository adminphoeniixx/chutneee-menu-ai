<?php

namespace App\Http\Controllers;

use App\Services\ImageGenerationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ImageGenerationController extends Controller
{
    private ImageGenerationService $imageGenerationService;

    public function __construct(ImageGenerationService $imageGenerationService)
    {
        $this->imageGenerationService = $imageGenerationService;
    }

    /**
     * Generate menu item image and upload to CDN
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generateMenuItem(Request $request): JsonResponse
    {
        try {
            // Validate request for menu items
            $validated = $request->validate([
                'food_name' => 'required|string|min:2|max:100',
                'style' => 'nullable|string|in:photographic,realistic,cinematic,analog-film',
                'aspect_ratio' => 'nullable|string|in:1:1,4:3,16:9',
                'upload_path' => 'nullable|string|max:200'
            ]);

            // Generate and upload image with menu-specific optimizations
            $result = $this->imageGenerationService->generateAndUpload(
                prompt: $validated['food_name'],
                style: $validated['style'] ?? 'photographic',
                aspectRatio: $validated['aspect_ratio'] ?? '1:1',
                seed: null, // Always random for different images
                uploadPath: $validated['upload_path'] ?? null,
                isMenuItem: true // Enable food-specific enhancements
            );

            return response()->json([
                'success' => true,
                'message' => 'Menu item image generated successfully',
                'data' => $result
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Menu item image generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Menu item image generation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate image and upload to CDN (general purpose)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generate(Request $request): JsonResponse
    {
        try {
            // Validate request
            $validated = $request->validate([
                'prompt' => 'required|string|min:3|max:500',
                'style' => 'nullable|string|in:realistic,anime,cartoon,abstract,oil-painting,watercolor,photographic,digital-art,comic-book,fantasy,analog-film,neon-punk,isometric,line-art,craft-clay,cinematic,3d-model,pixel-art,origami,silhouette,minimalist',
                'aspect_ratio' => 'nullable|string|in:1:1,16:9,9:16,4:3,3:4,3:2,2:3',
                'seed' => 'nullable|integer|min:1|max:9999999',
                'upload_path' => 'nullable|string|max:200'
            ]);

            // Generate and upload image
            $result = $this->imageGenerationService->generateAndUpload(
                prompt: $validated['prompt'],
                style: $validated['style'] ?? 'photographic',
                aspectRatio: $validated['aspect_ratio'] ?? '1:1',
                seed: $validated['seed'] ?? null, // Use provided seed or null for random
                uploadPath: $validated['upload_path'] ?? null,
                isMenuItem: false // General purpose image
            );

            return response()->json([
                'success' => true,
                'message' => 'Image generated and uploaded successfully',
                'data' => $result
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Image generation failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Image generation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate multiple menu items in batch
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generateMenuBatch(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'food_items' => 'required|array|min:1|max:10',
                'food_items.*' => 'required|string|min:2|max:100',
                'style' => 'nullable|string|in:photographic,realistic,cinematic,analog-film',
                'aspect_ratio' => 'nullable|string|in:1:1,4:3,16:9',
            ]);

            $results = [];
            $errors = [];

            foreach ($validated['food_items'] as $index => $foodName) {
                try {
                    $result = $this->imageGenerationService->generateAndUpload(
                        prompt: $foodName,
                        style: $validated['style'] ?? 'photographic',
                        aspectRatio: $validated['aspect_ratio'] ?? '1:1',
                        seed: null, // Always random for different images
                        uploadPath: null,
                        isMenuItem: true
                    );
                    $results[] = $result;
                    
                    // Small delay to avoid rate limiting
                    sleep(1);
                    
                } catch (\Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'food_name' => $foodName,
                        'error' => $e->getMessage()
                    ];
                }
            }

            return response()->json([
                'success' => empty($errors),
                'message' => count($results) . ' menu items generated successfully' . 
                           (count($errors) > 0 ? ', ' . count($errors) . ' failed' : ''),
                'data' => [
                    'successful' => $results,
                    'failed' => $errors,
                    'total_requested' => count($validated['food_items']),
                    'successful_count' => count($results),
                    'failed_count' => count($errors)
                ]
            ], empty($errors) ? 201 : 207);

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Menu batch generation failed', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Menu batch generation failed: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Generate multiple images in batch (general purpose)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function generateBatch(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'requests' => 'required|array|min:1|max:5',
                'requests.*.prompt' => 'required|string|min:3|max:500',
                'requests.*.style' => 'nullable|string|in:realistic,anime,cartoon,abstract,oil-painting,watercolor,photographic,digital-art,comic-book,fantasy,analog-film,neon-punk,isometric,line-art,craft-clay,cinematic,3d-model,pixel-art,origami,silhouette,minimalist',
                'requests.*.aspect_ratio' => 'nullable|string|in:1:1,16:9,9:16,4:3,3:4,3:2,2:3',
                'requests.*.seed' => 'nullable|integer|min:1|max:9999999',
                'requests.*.upload_path' => 'nullable|string|max:200'
            ]);

            $results = [];
            $errors = [];

            foreach ($validated['requests'] as $index => $requestData) {
                try {
                    $result = $this->imageGenerationService->generateAndUpload(
                        prompt: $requestData['prompt'],
                        style: $requestData['style'] ?? 'photographic',
                        aspectRatio: $requestData['aspect_ratio'] ?? '1:1',
                        seed: $requestData['seed'] ?? null,
                        uploadPath: $requestData['upload_path'] ?? null,
                        isMenuItem: false
                    );
                    $results[] = $result;
                } catch (\Exception $e) {
                    $errors[] = [
                        'index' => $index,
                        'error' => $e->getMessage(),
                        'prompt' => $requestData['prompt']
                    ];
                }
            }

            return response()->json([
                'success' => empty($errors),
                'message' => count($results) . ' images generated successfully' . 
                           (count($errors) > 0 ? ', ' . count($errors) . ' failed' : ''),
                'data' => [
                    'successful' => $results,
                    'failed' => $errors,
                    'total_requested' => count($validated['requests']),
                    'successful_count' => count($results),
                    'failed_count' => count($errors)
                ]
            ], empty($errors) ? 201 : 207); // 207 Multi-Status for partial success

        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);

        } catch (\Exception $e) {
            \Log::error('Batch image generation failed', [
                'error' => $e->getMessage(),
                'request' => $request->all()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Batch generation failed: ' . $e->getMessage()
            ], 500);
        }
    }
}