<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\MenuExtractionService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class MenuExtractionController extends Controller
{
    public function __construct(private MenuExtractionService $svc) {}

    // POST /api/menu/extract
    public function extract(Request $request): JsonResponse
    {
        $request->validate([
            'menu_image' => 'required|image|mimes:jpeg,png,jpg,webp|max:10240',
            'model'      => 'sometimes|string',
        ]);

        try {
            if ($request->filled('model')) {
                $this->svc->setModel($request->string('model'));
            }

            $enhancedMenu = $this->svc->extractAndCategorizeMenu($request->file('menu_image'));

            // “flat_rows” = the same structure you previously built for CSV, but returned as JSON
            $flatRows = $this->svc->generateCSVData($enhancedMenu);

            return response()->json([
                'success' => true,
                'data' => [
                    // hierarchical (sections/items/pricing)
                    'menu'      => $enhancedMenu,

                    // flat, row-wise array (what you called CSV rows)
                    'rows'      => $flatRows,

                    // quick summary for UI
                    'summary'   => [
                        'total_items'     => count($flatRows),
                        'categories_used' => array_values(array_unique(array_column($flatRows, 'category_id'))),
                        'veg_items'       => count(array_filter($flatRows, fn($i) => (int)$i['attribute_id'] === 1)),
                        'non_veg_items'   => count(array_filter($flatRows, fn($i) => (int)$i['attribute_id'] === 2)),
                    ],
                ],
                'message' => 'Menu extracted and categorized successfully.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    // POST /api/menu/preview  (optional helper)
    public function preview(Request $request): JsonResponse
    {
        $request->validate([
            'rows'  => 'required|array',
            'limit' => 'sometimes|integer|min:1|max:100',
        ]);

        $rows  = $request->input('rows');
        $limit = (int)($request->input('limit', 10));

        return response()->json([
            'success'      => true,
            'preview_data' => array_slice($rows, 0, $limit),
            'total_rows'   => count($rows),
        ]);
    }
}