<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use App\Services\MenuExtractionService;

class MenuUploadController extends Controller
{
    public function upload(Request $request)
    {
        $request->validate([
            'image' => 'required|image|max:8192',
        ]);

        $file  = $request->file('image');
        $mime  = $file->getMimeType() ?: 'image/jpeg';
        $bytes = file_get_contents($file->getRealPath());
        $b64   = base64_encode($bytes);
        $dataUrl = "data:{$mime};base64,{$b64}";

        $system = <<<SYS
You are a menu-extraction agent. You will be given an image of a restaurant menu.
Return ONLY valid JSON (no prose, no markdown) in this exact schema:
{
  "items": [
    {"id": number, "name": string, "category": string, "attribute": "Veg"|"Non-Veg"|"Egg", "price": number, "variations": string[]}
  ]
}
Sorting rules (apply BEFORE returning):
1) Category order: Tikka < Tandoori < Kebab < Paneer Dishes < Curry < Biryani < Roll < Other
2) Name: A–Z
3) Variation: Full Plate < Half Plate < Quarter Plate
IDs must be 1..N after sorting. Price must be integer rupees.
SYS;

        $userText = "Extract and sort the full menu from this image. Return JSON only.";

        $endpoint = rtrim(env('DO_AGENT_ENDPOINT',''), '/');
        $key      = env('DO_AGENT_ACCESS_KEY');

        if (!$endpoint || !$key) {
            throw ValidationException::withMessages([
                'agent' => 'Missing DO_AGENT_ENDPOINT or DO_AGENT_ACCESS_KEY in .env'
            ]);
        }

        $payload = [
            "messages" => [
                ["role" => "system", "content" => $system],
                [
                    "role" => "user",
                    "content" => [
                        ["type" => "text", "text" => $userText],
                        ["type" => "image_url", "image_url" => $dataUrl]
                    ]
                ],
            ],
            "max_tokens" => 2048,
        ];

        $resp = Http::withToken($key)->acceptJson()->post($endpoint, $payload);

        if (!$resp->ok()) {
            return response()->json([
                'success' => false,
                'error' => 'Agent request failed',
                'details' => $resp->body(),
            ], $resp->status());
        }

        $json  = $resp->json();
        $items = data_get($json, 'items');

        if (!$items) {
            $content = data_get($json, 'choices.0.message.content')
                    ?? data_get($json, 'message.content');
            if ($content) {
                $decoded = json_decode($content, true);
                $items   = $decoded['items'] ?? null;
            }
        }

        if (!is_array($items)) {
            return response()->json([
                'success' => false,
                'error'   => 'Agent did not return expected JSON { items: [...] }',
                'raw'     => $json,
            ], 422);
        }

        // Sanity cleanup
        $items = array_map(function($row, $i){
            $row['id'] = $row['id'] ?? ($i+1);
            $row['price'] = (int) ($row['price'] ?? 0);
            $row['variations'] = array_values(array_unique(array_map('strval', $row['variations'] ?? [])));
            return $row;
        }, $items, array_keys($items));

        return response()->json(['success' => true, 'items' => $items]);
    }
}