<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MenuExtractionService
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;
    private int $timeout;

    // ----- CONFIG -----
    private array $categories = [
        1=>'Pizza',2=>'Starters',3=>'Burger',4=>'Cold Coffee',5=>'Pav Bhaji',6=>'Vadapav',
        7=>'Samosa',8=>'Sandwich',9=>'Chaat',10=>'Kachori',11=>'Chole Kulche',12=>'Chole Bhature',
        13=>'Tikki',14=>'Pakode',15=>'Soups',16=>'Bedmi Poori',17=>'Beverages',18=>'Combos',
        19=>'Dahi Bhalla',20=>'Gol Gappe',21=>'Naan/Paranthe',22=>'Raita',23=>'Spring Roll',
        24=>'Bhel Puri',25=>'Choupsey',26=>'Maggi',27=>'Dal',28=>'Desserts',29=>'Egg & Omelette',
        30=>'Falafel',31=>'Frankie',32=>'Rice',33=>'Rolls',34=>'Ice Cream',35=>'Korean Dishes',
        36=>'Laphing',37=>'Lassi',38=>'Sabzi',39=>'Tikka',40=>'Patties',41=>'Puri Sabji',
        42=>'Shawarma',43=>'Special Chinese Flavours',44=>'Chaap',45=>'Tawa Se',46=>'Momos',
        47=>'With Rice',48=>'South Indian',49=>'Platter & more',50=>'Jalandhri',51=>'Monakos',
        52=>'New Variety',53=>'French Toast',54=>'Tandoori items',55=>'Afghani items',
        56=>'Garlic bread',57=>'Masala items',58=>'Onion items',59=>'Half fry',
        60=>'Butter Omelette',61=>'Boiled egg',62=>'Pulav',63=>'Extras',64=>'Noodles',
        65=>'Egg Dosa Omelette',66=>'Nutri kulcha',67=>'Rice bowl',68=>'Fries & pasta',
        69=>'Snacks',70=>'Pizza Omelette',71=>'Main Course',72=>'Chinese',73=>'Waffles',
        74=>'Waffle Sandwich',75=>'Shakes',76=>'Waffle cake',77=>'Waffle Sundaes',
        78=>'Mini pancakes',79=>'Fries',80=>'Salad',81=>'Corn',82=>'Ram Ladoo',83=>'Meal'
    ];
    private array $attributes = [1 => 'Veg', 2 => 'Non-Veg'];
    private array $variations = [1 => 'Half Plate', 2 => 'Full Plate'];

    public function __construct()
    {
        $this->apiKey  = (string) env('OPENROUTER_API_KEY');
        if (!$this->apiKey) {
            throw new \Exception('OpenRouter API key is not configured');
        }
        $this->baseUrl = (string) env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1');
        $this->model   = (string) env('OPENROUTER_MODEL', 'openai/gpt-4o-mini');
        $this->timeout = (int) env('OPENROUTER_REQUEST_TIMEOUT', 60);
    }

    public function setModel(string $model): void
    {
        $this->model = $model;
    }

    /**
     * MAIN: returns enhanced hierarchical menu array (ALWAYS returns or throws).
     */
    public function extractAndCategorizeMenu(UploadedFile $image): array
    {
        Log::info('Menu extraction started');
        $this->validateImage($image);

        // 1) Vision extract
        $jpegBytes = $this->preprocessImageToJpeg($image, 1600, 75);
        $base64    = base64_encode($jpegBytes);
        $mime      = 'image/jpeg';

        $raw = $this->callOpenRouterVision($base64, $mime);

        Log::info('Raw vision output', ['raw' => $raw]);

        // 2) Normalize extracted JSON
        $menu = $this->normalizeExtracted($raw);

        // 3) Enhance (categorize items)
        $enhanced = $this->enhanceWithAI($menu);

        // Always return an array with expected keys
        if (!isset($enhanced['restaurant_name'], $enhanced['menu_sections']) || !is_array($enhanced['menu_sections'])) {
            // As a safety fallback, return a minimal structure instead of returning null
            Log::warning('Enhanced structure missing keys; returning fallback.');
            return [
                'restaurant_name' => $enhanced['restaurant_name'] ?? 'Restaurant Menu',
                'menu_sections'   => $enhanced['menu_sections'] ?? [
                    ['section_name' => 'Menu Items', 'items' => []]
                ],
            ];
        }

        return $enhanced;
    }

    /**
     * Convert hierarchical menu to flat rows (for JSON).
     */
    public function generateCSVData(array $enhancedMenu): array
    {
        $rows = [];
        $id = 1;

        $sections = $enhancedMenu['menu_sections'] ?? [];
        foreach ($sections as $sec) {
            $items = $sec['items'] ?? [];
            foreach ($items as $item) {
                $ai = $item['ai_category'] ?? null;
                if (!$ai) {
                    // If not categorized, skip or add default
                    $ai = [
                        'category_id' => 71,
                        'attribute_id' => 1,
                        'variation_ids' => [2],
                    ];
                }

                // multi-pricing
                if (!empty($item['pricing']) && is_array($item['pricing'])) {
                    foreach ($item['pricing'] as $p) {
                        $size = $p['size'] ?? 'Full';
                        $variationId = strcasecmp($size, 'Half') === 0 ? 1 : 2;
                        $price = (float) ($p['price'] ?? 0);
                        $rows[] = $this->row($id++, $ai, $item['name'].' ('.$size.')', $price, $item['description'] ?? '', $variationId);
                    }
                } else {
                    $price = (float) ($item['price'] ?? 0);
                    $rows[] = $this->row($id++, $ai, $item['name'] ?? 'Item', $price, $item['description'] ?? '', null);
                }
            }
        }
        return $rows;
    }

    /* ==================== Internals ==================== */

    private function computeAllowVariation(array $item): int
{
    return 0;
}

    private function row(int $id, array $ai, string $name, float $price, string $desc, ?int $variationId): array
    {
        $allow = $variationId === null ? 1 : 0;

        return [
            'id'            => $id,
            'category_id'   => (int) ($ai['category_id'] ?? 71),
            'name'          => $name,
            'image'         => '',
            'allowvariation'=> $allow,
            'price'         => $price,
            'sell_price'    => $price,
            'status'        => 1,
            'attribute_id'  => (int) ($ai['attribute_id'] ?? 1),
            'is_taxable'    => 1,
            'tax_id'        => '1',
            'description'   => $desc,
            'regular_price' => $price,
            'group_id'      => (int) ($ai['category_id'] ?? 71),
            'variations'    => $allow
                                ? json_encode(array_values(array_unique(array_map('intval', $ai['variation_ids'] ?? [2]))))
                                : json_encode([
                                      'variation_id'   => (int) ($variationId ?? 2),
                                      'variation_name' => $this->variations[(int) ($variationId ?? 2)] ?? 'Full Plate'
                                  ]),
        ];
    }

    private function validateImage(UploadedFile $image): void
    {
        $allowed = ['image/jpeg','image/png','image/jpg','image/webp'];
        if (!in_array($image->getMimeType(), $allowed, true)) {
            throw new \Exception('Invalid image type. Only JPEG, PNG, JPG, and WebP are allowed.');
        }
        if ($image->getSize() > 10 * 1024 * 1024) {
            throw new \Exception('Image size too large. Maximum 10MB allowed.');
        }
    }

    private function toBase64(UploadedFile $image): string
    {
        return base64_encode(file_get_contents($image->getRealPath()));
    }

    /**
 * Preprocess the uploaded menu image:
 * - auto-orient (EXIF)
 * - downscale (max width)
 * - light sharpen
 * - strip metadata
 * - encode JPEG @ quality
 *
 * Returns raw JPEG bytes.
 */
private function preprocessImageToJpeg(UploadedFile $image, int $maxWidth = 1600, int $quality = 75): string
{
    if (extension_loaded('imagick')) {
        $img = new \Imagick($image->getRealPath());

        // If multi-frame (e.g., GIF/PDF), use first frame
        if ($img->getNumberImages() > 1) {
            $img = $img->coalesceImages();
            $img->setIteratorIndex(0);
        }

        // Auto-orient
        if (method_exists($img, 'autoOrient')) {
            $img->autoOrient();
        } elseif (method_exists($img, 'setImageOrientation')) {
            $img->setImageOrientation(\Imagick::ORIENTATION_TOPLEFT);
        }

        // Downscale
        $w = $img->getImageWidth();
        $h = $img->getImageHeight();
        if ($w > $maxWidth) {
            $newH = (int) round($h * ($maxWidth / $w));
            $img->resizeImage($maxWidth, $newH, \Imagick::FILTER_LANCZOS, 1);
        }

        // Light sharpen
        $img->sharpenImage(0.0, 0.6);

        // Strip metadata and encode
        $img->stripImage();
        $img->setImageFormat('jpeg');
        $img->setImageCompression(\Imagick::COMPRESSION_JPEG);
        $img->setImageCompressionQuality($quality);

        return (string) $img;
    }

    // ---- GD fallback ----
    $bytes = file_get_contents($image->getRealPath());
    $src = @imagecreatefromstring($bytes);
    if (!$src) {
        return $bytes; // fallback to original if GD fails
    }

    // Best-effort EXIF orientation (JPEG only)
    $ext = strtolower($image->getClientOriginalExtension());
    if (function_exists('exif_read_data') && in_array($ext, ['jpg','jpeg'], true)) {
        $exif = @exif_read_data($image->getRealPath());
        if (!empty($exif['Orientation'])) {
            switch ($exif['Orientation']) {
                case 3: $src = imagerotate($src, 180, 0); break;
                case 6: $src = imagerotate($src, -90, 0); break;
                case 8: $src = imagerotate($src, 90, 0); break;
            }
        }
    }

    $w = imagesx($src); $h = imagesy($src);
    if ($w > $maxWidth) {
        $nh  = (int) round($h * ($maxWidth / $w));
        $dst = imagecreatetruecolor($maxWidth, $nh);
        // white bg for formats with alpha
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $nh, $w, $h);
        imagedestroy($src);
        $src = $dst;
    }

    // Light sharpen kernel
    $matrix  = [[-1,-1,-1],[-1,16,-1],[-1,-1,-1]];
    $divisor = 8; $offset = 0;
    @imageconvolution($src, $matrix, $divisor, $offset);

    ob_start();
    imagejpeg($src, null, $quality);
    $out = (string) ob_get_clean();
    imagedestroy($src);

    return $out;
}

    private function callOpenRouterVision(string $b64, string $mime): string
{
    $payload = [
        'model' => $this->model, // e.g. openai/gpt-4o  (vision-capable)
        'response_format' => ['type' => 'json_object'], // <- force a single JSON object
        'messages' => [
            [
                'role' => 'system',
                'content' => 'You are an OCR + structuring engine. Return ONE valid JSON object only. Do NOT include markdown or code fences.'
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Read ALL text from this restaurant menu image (small fonts, multi columns, split prices like "50 / 90").
                        Convert into JSON ONLY with this schema:
                        {
                        "restaurant_name": "string",
                        "menu_sections": [
                            {
                            "section_name": "string",
                            "items": [
                                {
                                "name": "string",
                                "description": "short (<=120 chars, invent if not visible)",
                                "pricing": [
                                    {"size": "Full|Half|Regular|Plain|etc", "price": "number as string", "currency": "₹"}
                                ]
                                }
                            ]
                            }
                        ]
                        }
                        - Do not skip items.
                        - If the JSON would be very long, you may shorten descriptions but keep all items.
                        - Return ONLY the JSON object. No extra text.'
                    ],
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => "data:{$mime};base64,{$b64}" // no detail flag
                        ]
                    ]
                ]
            ]
        ],
        'max_tokens'  => 8000, // give room; OpenRouter will cap as needed
        'temperature' => 0.0,
    ];

    $res = Http::withHeaders([
        'Authorization' => 'Bearer '.$this->apiKey,
        'Content-Type'  => 'application/json',
        'HTTP-Referer'  => request()->getSchemeAndHttpHost(),
        'X-Title'       => 'Menu Extraction API',
    ])->timeout($this->timeout)->post($this->baseUrl.'/chat/completions', $payload);

    if (!$res->successful()) {
        throw new \Exception('OpenRouter API error: '.$res->body());
    }

    $json = $res->json();
    return $json['choices'][0]['message']['content'] ?? '';
}



    /**
 * Remove code fences, illegal control chars, and ensure UTF-8 string.
 */
private function sanitizeJsonString(string $s): string
{
    // strip code fences if any slipped through
    $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
    $s = preg_replace('/```$/', '', $s);

    // normalize newlines, collapse weird whitespace
    $s = str_replace(["\r\n", "\r"], "\n", $s);

    // remove illegal ASCII control chars except \n \t
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);

    // ensure valid UTF-8
    if (!mb_detect_encoding($s, 'UTF-8', true)) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }

    return trim($s);
}

/**
 * Try to cut the string to the longest balanced JSON object substring { ... }.
 */
private function cutToBalancedJsonObject(string $s): ?string
{
    $start = strpos($s, '{');
    if ($start === false) return null;

    $depth = 0;
    $inStr = false;
    $prev  = '';
    $lastBalancedPos = null;

    $len = strlen($s);
    for ($i = $start; $i < $len; $i++) {
        $ch = $s[$i];

        if ($inStr) {
            if ($ch === '"' && $prev !== '\\') {
                $inStr = false;
            }
        } else {
            if ($ch === '"') {
                $inStr = true;
            } elseif ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $lastBalancedPos = $i;
                    break; // we found a full top-level JSON object
                }
            }
        }
        $prev = $ch;
    }

    if ($lastBalancedPos !== null) {
        return substr($s, $start, $lastBalancedPos - $start + 1);
    }

    // If we didn't close, try a softer cut at the last '}' seen
    $lastBrace = strrpos($s, '}');
    if ($lastBrace !== false && $lastBrace > $start) {
        return substr($s, $start, $lastBrace - $start + 1);
    }

    return null;
}




    private function normalizeExtracted(string $raw): array
{
    // 1) Sanitize
    $clean = $this->sanitizeJsonString($raw);

    // 2) Try decode as-is
    $decoded = json_decode($clean, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // 3) Try cut to balanced { ... }
        $balanced = $this->cutToBalancedJsonObject($clean);
        if ($balanced !== null) {
            $decoded = json_decode($balanced, true);
        }
    }

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        Log::warning('Vision JSON parse failed after repair', [
            'error' => json_last_error_msg(),
            'snippet' => substr($clean, 0, 600).'...'
        ]);
        return [
            'restaurant_name' => 'Restaurant Menu',
            'menu_sections'   => [['section_name' => 'Menu Items', 'items' => []]],
        ];
    }

    // Accept either format
    if (isset($decoded['items']) && is_array($decoded['items'])) {
        return [
            'restaurant_name' => $decoded['restaurant_name'] ?? 'Restaurant Menu',
            'menu_sections'   => [[
                'section_name' => 'Menu Items',
                'items'        => $decoded['items'],
            ]]
        ];
    }

    if (isset($decoded['menu_sections']) && is_array($decoded['menu_sections'])) {
        $decoded['restaurant_name'] = $decoded['restaurant_name'] ?? 'Restaurant Menu';
        return $decoded;
    }

    // Fallback skeleton
    return [
        'restaurant_name' => 'Restaurant Menu',
        'menu_sections'   => [['section_name' => 'Menu Items', 'items' => []]],
    ];
}




    private function enhanceWithAI(array $menuData): array
    {
        $sectionsOut = [];
        $sections = $menuData['menu_sections'] ?? [];

        foreach ($sections as $sec) {
            $itemsOut = [];
            $items = $sec['items'] ?? [];
            foreach ($items as $item) {
                $itemsOut[] = $this->categorizeItem($item);
            }
            $sectionsOut[] = [
                'section_name' => $sec['section_name'] ?? 'Menu Items',
                'items'        => $itemsOut
            ];
        }

        return [
            'restaurant_name' => $menuData['restaurant_name'] ?? 'Restaurant Menu',
            'menu_sections'   => $sectionsOut
        ];
    }

    private function categorizeItem(array $item): array
    {
        $name = $item['name'] ?? '';
        $desc = $item['description'] ?? '';

        try {
            $categoriesText = implode("\n", array_map(
                fn($id, $nm) => "$id: $nm",
                array_keys($this->categories), $this->categories
            ));

            $prompt = "Categorize this menu item: '{$name}' (Description text from menu: '{$desc}')

Available Categories (IDs):
{$categoriesText}

Available Attributes:
1: Veg
2: Non-Veg

Available Variations:
1: Half Plate
2: Full Plate

Return ONLY this JSON:
{
  \"category_id\": 39,
  \"attribute_id\": 2,
  \"variation_ids\": [1, 2],
  \"description_120\": \"A concise, neutral, factual description <= 120 chars (no emojis, no marketing).\"
}";

            $res = Http::withHeaders([
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => request()->getSchemeAndHttpHost(),
                'X-Title'       => 'Menu Categorization',
            ])->timeout(30)->post($this->baseUrl.'/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an expert food categorization assistant.'],
                    ['role' => 'user',   'content' => $prompt]
                ],
                'max_tokens' => 200,
                'temperature' => 0.1,
            ]);

            if ($res->successful()) {
                $data = $res->json();
                $content = $data['choices'][0]['message']['content'] ?? '';
                $clean = trim(preg_replace('/```json\s*|\s*```/i', '', $content));
                $ai = json_decode($clean, true);

                if (json_last_error() === JSON_ERROR_NONE && isset($ai['category_id'], $ai['attribute_id'])) {
                    $item['ai_category'] = [
                        'category_id'    => (int)$ai['category_id'],
                        'category_name'  => $this->categories[(int)$ai['category_id']] ?? 'Unknown',
                        'attribute_id'   => (int)$ai['attribute_id'],
                        'attribute_name' => $this->attributes[(int)$ai['attribute_id']] ?? 'Unknown',
                        'variation_ids'  => array_values(array_unique(array_map('intval', $ai['variation_ids'] ?? [2]))),
                        'variation_names'=> array_map(fn($id) => $this->variations[(int)$id] ?? 'Unknown', $ai['variation_ids'] ?? [2]),
                    ];

                    $aiDesc = '';
                    if (!empty($ai['description_120']) && is_string($ai['description_120'])) {
                        $aiDesc = $this->clamp120($ai['description_120']);
                    }
                    $item['ai_description'] = $aiDesc;
                    $item['allowvariation'] = (count($item['ai_category']['variation_ids'] ?? []) > 1) ? 1 : 0;

                    return $item;
                }
            }
        } catch (\Throwable $e) {
            Log::warning("AI categorize failed: {$name}", ['e' => $e->getMessage()]);
        }

        // Rule-based fallback (ALWAYS set ai_category)
        $attrId = 1; // Veg
        foreach (['chicken','mutton','fish','egg','meat','beef','pork','prawns','tikka'] as $kw) {
            if (stripos($name, $kw) !== false) { $attrId = 2; break; }
        }
        $catId = 71;
        if (stripos($name,'tikka')!==false)          $catId = 39;
        elseif (stripos($name,'tandoori')!==false)   $catId = 54;
        elseif (stripos($name,'rice')!==false)       $catId = 32;
        elseif (stripos($name,'naan')!==false || stripos($name,'roti')!==false) $catId = 21;
        elseif (stripos($name,'roll')!==false)       $catId = 33;

        $item['ai_category'] = [
            'category_id'    => $catId,
            'category_name'  => $this->categories[$catId],
            'attribute_id'   => $attrId,
            'attribute_name' => $this->attributes[$attrId],
            'variation_ids'  => [1,2],
            'variation_names'=> ['Half Plate','Full Plate'],
        ];
        $item['ai_description'] = ''; 
         $item['allowvariation'] = (count($item['ai_category']['variation_ids'] ?? []) > 1) ? 1 : 0;
        return $item;
    }


    private function clamp120(string $text): string
{
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));
    if (function_exists('mb_strwidth')) {
        if (mb_strwidth($text, 'UTF-8') > 120) {
            $text = rtrim(mb_strimwidth($text, 0, 120, '', 'UTF-8'));
        }
    } else {
        if (strlen($text) > 120) {
            $text = rtrim(substr($text, 0, 120));
        }
    }
    return $text;
}


    /**
     * Build a concise description (<= 120 chars). If empty, compose a fallback.
     */
    private function shortDescription(string $desc, array $ai, string $name): string
    {
        // normalize whitespace
        $desc = trim(preg_replace('/\s+/', ' ', (string) $desc));

        // compose fallback if missing
        if ($desc === '') {
            $cat  = $ai['category_name']  ?? ($this->categories[$ai['category_id'] ?? 71] ?? 'Menu Item');
            $attr = $ai['attribute_name'] ?? ($this->attributes[$ai['attribute_id'] ?? 1] ?? 'Veg');
            $desc = "{$name} — {$attr} • {$cat}";
        }

        // multibyte-safe clamp at 120 chars
        if (function_exists('mb_strwidth')) {
            if (mb_strwidth($desc, 'UTF-8') > 120) {
                $desc = rtrim(mb_strimwidth($desc, 0, 120, '', 'UTF-8'));
            }
        } else {
            if (strlen($desc) > 120) {
                $desc = rtrim(substr($desc, 0, 120));
            }
        }

        return $desc;
    }


}