<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\MenuImageService;

class MenuImageController extends Controller
{
    public function __construct(private MenuImageService $svc) {}

    /**
     * POST /api/menu/generate-image
     * body: { name: string, cuisine?: string, notes?: string }
     */
    public function generate(Request $request)
    {
        $validated = $request->validate([
            'name'    => 'required|string|min:2|max:120',
            'cuisine' => 'sometimes|string|max:60',
            'notes'   => 'sometimes|string|max:200',
        ]);

        $res = $this->svc->generate(
            $validated['name'],
            $validated['cuisine'] ?? null,
            $validated['notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'image'   => [
                'url'      => $res['url'],
                'filename' => $res['filename'],
                'width'    => 500,
                'height'   => 300,
                'format'   => 'jpg',
            ],
        ]);
    }
}