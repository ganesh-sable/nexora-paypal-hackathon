<?php
/**
 * Nexora AI Assistant - Intent Detection & Typo Correction Engine
 * 
 * Provides deterministic natural language intent detection, typo correction,
 * fuzzy matching, brand mismatch detection, and category-accurate database queries.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/chatbot_state.php';

if (!defined('INR_TO_USD_RATE')) {
    define('INR_TO_USD_RATE', 83.0);
}

class ChatbotIntent {

    /**
     * Master Dictionary of Canonical Terms, Categories, Brands & Synonyms
     */
    public static array $productTypes = [
        'laptop' => [
            'canonical' => 'laptop',
            'display'   => 'laptop',
            'category_id' => 2,
            'keywords'  => ['laptop', 'ultrabook', 'notebook', 'macbook', 'chromebook'],
            'typos'     => ['lptop', 'loptop', 'laptopp', 'leptop', 'labtop', 'laptp', 'latop', 'laptopt']
        ],
        'desktop' => [
            'canonical' => 'desktop',
            'display'   => 'desktop computer',
            'category_id' => 2,
            'keywords'  => ['desktop', 'all-in-one', 'workstation', 'mini pc'],
            'typos'     => ['desktp', 'deskop', 'descktop', 'pce']
        ],
        'monitor' => [
            'canonical' => 'monitor',
            'display'   => 'monitor',
            'category_id' => 2,
            'keywords'  => ['monitor', 'curved monitor', 'display', 'screen'],
            'typos'     => ['monitr', 'moniter', 'scren', 'disply']
        ],
        'keyboard' => [
            'canonical' => 'keyboard',
            'display'   => 'keyboard',
            'category_id' => 2,
            'keywords'  => ['keyboard', 'mechanical keyboard', 'ergonomic keyboard'],
            'typos'     => ['keybrd', 'keybord', 'kyboard', 'keaboard']
        ],
        'mouse' => [
            'canonical' => 'mouse',
            'display'   => 'mouse',
            'category_id' => 2,
            'keywords'  => ['mouse', 'wireless mouse', 'gaming mouse'],
            'typos'     => ['mous', 'mose', 'moose', 'mice']
        ],
        'watch' => [
            'canonical' => 'watch',
            'display'   => 'watch',
            'category_id' => 5,
            'keywords'  => ['watch', 'smartwatch', 'chronograph', 'timepiece'],
            'typos'     => ['wtch', 'wath', 'whatch', 'watche', 'watsh', 'smartwtch', 'smartwath']
        ],
        'belt' => [
            'canonical' => 'belt',
            'display'   => 'belt',
            'category_id' => 5,
            'keywords'  => ['belt', 'leather dress belt', 'business belt'],
            'typos'     => ['blt', 'beltt', 'belet']
        ],
        'wallet' => [
            'canonical' => 'wallet',
            'display'   => 'wallet',
            'category_id' => 5,
            'keywords'  => ['wallet', 'cardholder', 'bifold'],
            'typos'     => ['walet', 'walett', 'wollet']
        ],
        'sunglasses' => [
            'canonical' => 'sunglasses',
            'display'   => 'sunglasses',
            'category_id' => 5,
            'keywords'  => ['sunglasses', 'aviator', 'shades', 'polarized'],
            'typos'     => ['sunglass', 'sunglases', 'shads', 'aviators']
        ],
        'phone' => [
            'canonical' => 'phone',
            'display'   => 'smartphone',
            'category_id' => 1,
            'keywords'  => ['smartphone', 'phone', '5g smartphone', 'mobile', 'cellphone'],
            'typos'     => ['fone', 'phne', 'smartfone', 'smartphne', 'mobeel', 'mobil', 'mobiles', 'cellfone']
        ],
        'tablet' => [
            'canonical' => 'tablet',
            'display'   => 'tablet',
            'category_id' => 1,
            'keywords'  => ['tablet', 'ipad'],
            'typos'     => ['tablat', 'tablt', 'tabelt', 'tablett']
        ],
        'power bank' => [
            'canonical' => 'power bank',
            'display'   => 'power bank',
            'category_id' => 1,
            'keywords'  => ['power bank', 'powerbank', 'portable charger'],
            'typos'     => ['powerbank', 'pwerbank', 'pwr bank', 'power bnk']
        ],
        'charger' => [
            'canonical' => 'charger',
            'display'   => 'charger',
            'category_id' => 1,
            'keywords'  => ['charger', 'wall charger', 'charging pad', 'car charger'],
            'typos'     => ['chargr', 'charget', 'chargre']
        ],
        'headphones' => [
            'canonical' => 'headphones',
            'display'   => 'headphones',
            'category_id' => 17,
            'keywords'  => ['headphone', 'headphones', 'headset', 'over-ear'],
            'typos'     => ['headfones', 'hedphones', 'headphon', 'hedfones', 'headfone', 'head phnes', 'head phones']
        ],
        'earbuds' => [
            'canonical' => 'earbuds',
            'display'   => 'earbuds',
            'category_id' => 17,
            'keywords'  => ['earbud', 'earbuds', 'in-ear', 'airpod', 'tws'],
            'typos'     => ['earfones', 'earfone', 'erbuds', 'ear pods', 'earpods']
        ],
        'speaker' => [
            'canonical' => 'speaker',
            'display'   => 'speaker',
            'category_id' => 17,
            'keywords'  => ['speaker', 'bluetooth speaker', 'soundbar', 'bookshelf speakers'],
            'typos'     => ['speeker', 'spekar', 'spekers', 'sound bar']
        ],
        'shoes' => [
            'canonical' => 'shoes',
            'display'   => 'shoes',
            'category_id' => 4,
            'keywords'  => ['shoes', 'sneakers', 'sandals', 'slippers', 'loafers', 'boots', 'footwear'],
            'typos'     => ['shues', 'shoos', 'sheos', 'sneeker', 'sneekers', 'sneker', 'sandal']
        ],
        't-shirt' => [
            'canonical' => 't-shirt',
            'display'   => 'T-shirt',
            'category_id' => 3,
            'keywords'  => ['t-shirt', 'shirt', 'tee', 'tshirt'],
            'typos'     => ['tshrt', 't-shrt', 'tshirt', 't shrt', 'teeshirt']
        ],
        'jeans' => [
            'canonical' => 'jeans',
            'display'   => 'jeans',
            'category_id' => 3,
            'keywords'  => ['jeans', 'denim', 'pants', 'trousers'],
            'typos'     => ['jens', 'jean', 'jeens']
        ],
        'jacket' => [
            'canonical' => 'jacket',
            'display'   => 'jacket',
            'category_id' => 3,
            'keywords'  => ['jacket', 'coat', 'hoodie'],
            'typos'     => ['jaket', 'jackt', 'jacet']
        ],
        'perfume' => [
            'canonical' => 'perfume',
            'display'   => 'perfume',
            'category_id' => 6,
            'keywords'  => ['perfume', 'fragrance', 'cologne'],
            'typos'     => ['purfume', 'perfum', 'perfoom']
        ],
        'furniture' => [
            'canonical' => 'furniture',
            'display'   => 'furniture',
            'category_id' => 8,
            'keywords'  => ['sofa', 'table', 'chair', 'bed', 'wardrobe', 'desk'],
            'typos'     => ['furnitur', 'forniture']
        ],
        'gaming' => [
            'canonical' => 'gaming',
            'display'   => 'gaming',
            'category_id' => 16,
            'keywords'  => ['gaming console', 'controller', 'headset', 'gamepad'],
            'typos'     => ['gamng', 'gameing']
        ]
    ];

    /**
     * Known brand industry profiles for detecting brand-category mismatches
     */
    public static array $brandCategories = [
        'nike'    => ['name' => 'Nike', 'categories' => [4, 3, 11], 'type' => 'athletic footwear, sportswear, and fitness gear', 'does_not_make' => ['laptop', 'desktop', 'monitor', 'computer', 'phone', 'tablet']],
        'adidas'  => ['name' => 'Adidas', 'categories' => [4, 3, 11], 'type' => 'athletic footwear, sportswear, and fitness gear', 'does_not_make' => ['laptop', 'desktop', 'monitor', 'computer', 'phone', 'tablet']],
        'puma'    => ['name' => 'Puma', 'categories' => [4, 3, 11], 'type' => 'athletic footwear and sportswear', 'does_not_make' => ['laptop', 'desktop', 'monitor', 'computer', 'phone', 'tablet']],
        'samsung' => ['name' => 'Samsung', 'categories' => [1, 2, 17], 'type' => 'smartphones, electronics, and computing displays', 'does_not_make' => ['shoes', 'clothing', 'jewellery']],
        'apple'   => ['name' => 'Apple', 'categories' => [1, 2, 5, 17], 'type' => 'smartphones, laptops, smartwatches, and audio', 'does_not_make' => ['shoes', 'clothing', 'furniture']],
        'dell'    => ['name' => 'Dell', 'categories' => [2], 'type' => 'laptops, desktop computers, and monitors', 'does_not_make' => ['shoes', 'clothing', 'watch', 'phone']],
        'lenovo'  => ['name' => 'Lenovo', 'categories' => [1, 2], 'type' => 'laptops, computers, and tablets', 'does_not_make' => ['shoes', 'clothing', 'watch']],
        'hp'      => ['name' => 'HP', 'categories' => [2], 'type' => 'laptops, computers, and monitors', 'does_not_make' => ['shoes', 'clothing']],
        'asus'    => ['name' => 'ASUS', 'categories' => [2, 16], 'type' => 'laptops, gaming PCs, and hardware', 'does_not_make' => ['shoes', 'clothing']],
        'sony'    => ['name' => 'Sony', 'categories' => [1, 16, 17], 'type' => 'audio equipment, gaming consoles, and electronics', 'does_not_make' => ['shoes', 'clothing', 'furniture']]
    ];

    public static array $brands = [
        'nike'    => ['nike', 'nikes'],
        'adidas'  => ['adidas'],
        'puma'    => ['puma'],
        'samsung' => ['samsung', 'samsng', 'samung', 'samsong', 'samusng', 'galaxy'],
        'apple'   => ['iphone', 'ipad', 'macbook', 'apple watch', 'apple phone', 'apple laptop'],
        'dell'    => ['dell'],
        'lenovo'  => ['lenovo', 'thinkpad'],
        'hp'      => ['hp', 'hewlett packard', 'pavilion'],
        'asus'    => ['asus', 'rog'],
        'sony'    => ['sony', 'playstation', 'bravia'],
        'intel'   => ['intel', 'core i7', 'i7'],
        'amd'     => ['amd', 'ryzen'],
        'nvidia'  => ['nvidia', 'rtx', 'geforce'],
        'fedex'   => ['fedex'],
        'dhl'     => ['dhl'],
        'ups'     => ['ups']
    ];

    /**
     * Common English words and conversational stopwords that must NEVER be modified or fuzzy-matched
     */
    public static array $stopWords = [
        'i', 'me', 'my', 'we', 'our', 'you', 'your', 'he', 'she', 'it', 'they', 'them',
        'want', 'wants', 'wanted', 'need', 'needs', 'needed', 'wish', 'like', 'likes',
        'show', 'shows', 'showing', 'give', 'gives', 'tell', 'tells', 'help', 'find', 'finds',
        'look', 'looking', 'search', 'searching', 'buy', 'buying', 'get', 'getting', 'have', 'has',
        'can', 'could', 'would', 'should', 'will', 'shall', 'may', 'might', 'must',
        'a', 'an', 'the', 'this', 'that', 'these', 'those', 'is', 'are', 'was', 'were', 'be', 'been',
        'in', 'on', 'at', 'to', 'for', 'from', 'by', 'with', 'without', 'about', 'under', 'over', 'above', 'below',
        'and', 'or', 'not', 'but', 'if', 'then', 'else', 'so', 'as', 'than',
        'good', 'best', 'better', 'top', 'cheap', 'budget', 'affordable', 'expensive', 'premium',
        'popular', 'trending', 'latest', 'new', 'recommend', 'suggest', 'suggestion',
        'what', 'which', 'who', 'whom', 'where', 'when', 'why', 'how', 'any', 'some', 'all',
        'please', 'thanks', 'thank', 'hello', 'hi', 'hey', 'greetings', 'okay', 'ok',
        'price', 'cost', 'rate', 'rupees', 'rs', 'inr', 'usd', 'dollars', 'bucks',
        'friend', 'birthday', 'gift', 'present', 'item', 'items', 'product', 'products',
        'dance', 'music', 'cricket', 'gaming', 'makeup', 'skincare', 'wireless', 'wired'
    ];

    /**
     * Perform Intelligent Typo Correction and Term Normalization
     */
    public static function correctTypos(string $input): array {
        $raw = trim($input);
        if ($raw === '') {
            return ['text' => '', 'corrections' => [], 'tokens' => []];
        }

        $words = preg_split('/\s+/', strtolower($raw));
        $correctedWords = [];
        $corrections = [];

        foreach ($words as $w) {
            $cleanWord = trim($w, ".,!?;:\"'()[]{}");
            if ($cleanWord === '') {
                continue;
            }

            // 1. Known conversational stopword
            if (in_array($cleanWord, self::$stopWords, true)) {
                $correctedWords[] = $cleanWord;
                continue;
            }

            // 2. Purely numeric or budget code
            if (is_numeric($cleanWord) || preg_match('/^[0-9]+k?$/i', $cleanWord)) {
                $correctedWords[] = $cleanWord;
                continue;
            }

            // 3. Known brand or brand typo
            $brandFound = false;
            foreach (self::$brands as $canonicalBrand => $variations) {
                if ($cleanWord === $canonicalBrand) {
                    $correctedWords[] = $canonicalBrand;
                    $brandFound = true;
                    break;
                }
                if (in_array($cleanWord, $variations, true)) {
                    $brandFound = true;
                    $corrections[$cleanWord] = ucfirst($canonicalBrand);
                    $correctedWords[] = $canonicalBrand;
                    break;
                }
            }
            if ($brandFound) continue;

            // 4. Exact product type or known typo
            $typeFound = false;
            foreach (self::$productTypes as $typeKey => $info) {
                if ($cleanWord === $typeKey || in_array($cleanWord, $info['keywords'], true)) {
                    $correctedWords[] = $cleanWord;
                    $typeFound = true;
                    break;
                }
                if (in_array($cleanWord, $info['typos'], true)) {
                    $corrections[$cleanWord] = $info['canonical'];
                    $correctedWords[] = $info['canonical'];
                    $typeFound = true;
                    break;
                }
            }
            if ($typeFound) continue;

            // 5. Fuzzy matching using Levenshtein distance for words >= 4 chars
            $bestMatch = null;
            $bestDistance = 999;
            $wordLen = strlen($cleanWord);

            if ($wordLen >= 4) {
                $wordMeta = metaphone($cleanWord);

                foreach (self::$productTypes as $typeKey => $info) {
                    $candidates = array_merge([$typeKey], $info['keywords']);
                    foreach ($candidates as $cand) {
                        $candSingle = explode(' ', $cand)[0];
                        $candLen = strlen($candSingle);
                        $dist = levenshtein($cleanWord, $candSingle);
                        $metaMatch = ($wordMeta === metaphone($candSingle));
                        $allowedDist = ($wordLen <= 4 || $candLen <= 4) ? 1 : 2;

                        if ($dist <= $allowedDist && ($dist < $bestDistance || ($dist === $bestDistance && $metaMatch))) {
                            $bestDistance = $dist;
                            $bestMatch = $info['canonical'];
                        }
                    }
                }

                foreach (self::$brands as $bName => $bVars) {
                    $dist = levenshtein($cleanWord, $bName);
                    $allowedDist = strlen($bName) <= 4 ? 1 : 2;
                    if ($dist <= $allowedDist && $dist < $bestDistance) {
                        $bestDistance = $dist;
                        $bestMatch = $bName;
                    }
                }
            }

            if ($bestMatch) {
                $corrections[$cleanWord] = $bestMatch;
                $correctedWords[] = $bestMatch;
            } else {
                $correctedWords[] = $cleanWord;
            }
        }

        $correctedText = implode(' ', $correctedWords);
        return [
            'original'    => $raw,
            'text'        => $correctedText,
            'corrections' => $corrections,
            'tokens'      => $correctedWords
        ];
    }

    /**
     * Detect full user intent, product category, budget, and brand
     */
    public static function detectIntent(string $userInput, array $chatHistory = []): array {
        $typoRes = self::correctTypos($userInput);
        $normalizedText = $typoRes['text'];
        $lower = strtolower($normalizedText);

        $intent = [
            'intent'            => 'product_search',
            'product_type'      => null,
            'display_name'      => null,
            'category_id'       => null,
            'brand'             => null,
            'min_price'         => null,
            'max_price'         => null,
            'max_price_usd'     => null,
            'currency'          => 'USD',
            'preferences'       => [],
            'requirements'      => [],
            'is_popular_request'=> false,
            'is_follow_up'      => false,
            'mismatch'          => null,
            'strict_budget'     => false,
            'raw_query'         => $userInput,
            'normalized_query'  => $normalizedText,
            'corrections'       => $typoRes['corrections']
        ];

        // 1. General Greeting / Small Talk
        if (preg_match('/^(hi|hello|hey|good\s*(morning|afternoon|evening)|greetings|hola)\b/i', $normalizedText) && strlen($normalizedText) <= 18) {
            $intent['intent'] = 'general_conversation';
            return $intent;
        }

        // 2. Order Tracking Intent
        if (preg_match('/(trk-[a-z0-9\-]+|ord-[0-9]{4}-[a-z0-9\-]+)/i', $userInput, $m) || str_contains($lower, 'track') || str_contains($lower, 'where is my order') || str_contains($lower, 'delivery status')) {
            $intent['intent'] = 'track_order';
            $intent['tracking_code'] = $m[1] ?? '';
            return $intent;
        }

        // 3. Payment / Transaction Intent
        if (preg_match('/(txn-[a-z0-9\-]+)/i', $userInput, $m) || str_contains($lower, 'transaction') || str_contains($lower, 'payment status') || str_contains($lower, 'receipt')) {
            $intent['intent'] = 'get_transactions';
            $intent['transaction_ref'] = $m[1] ?? '';
            return $intent;
        }

        // 4. Add to Cart Intent
        if (str_contains($lower, 'add to cart') || str_contains($lower, 'buy product') || str_contains($lower, 'put in cart')) {
            $intent['intent'] = 'add_to_cart';
            if (preg_match('/\b(10[1-9]|1[1-9][0-9]|2[0-9]{2}|1[0-9]{3})\b/', $userInput, $pm)) {
                $intent['product_id'] = (int)$pm[1];
            }
            return $intent;
        }

        // 5. Store Policy FAQs
        if (str_contains($lower, 'paypal') || str_contains($lower, 'how to pay') || str_contains($lower, 'payment method')) {
            $intent['intent'] = 'support_faq';
            $intent['faq_topic'] = 'paypal';
            return $intent;
        }
        if (str_contains($lower, 'return') || str_contains($lower, 'refund') || str_contains($lower, 'guarantee')) {
            $intent['intent'] = 'support_faq';
            $intent['faq_topic'] = 'returns';
            return $intent;
        }

        // 6. Gift Recommendation Intent
        if (str_contains($lower, 'gift') || str_contains($lower, 'present') || str_contains($lower, 'birthday')) {
            $intent['intent'] = 'gift_advice';
        }

        // 7. Product Comparison Intent
        if (str_contains($lower, 'compare') || str_contains($lower, 'difference between') || str_contains($lower, 'which is better')) {
            $intent['intent'] = 'product_comparison';
        }

        // 8. Extract Budget (Max / Min Price)
        if (preg_match('/(?:under|below|less than|within|upto|budget(?:\s*is|\s*of|\s*:)?|max(?:imum)?)\s*(?:rs\.?|inr|\$|₹)?\s*([0-9]+(?:,[0-9]+)*(?:\.[0-9]+)?)/i', $userInput, $bm) ||
            preg_match('/(?:rs\.?|inr|\$|₹)\s*([0-9]+(?:,[0-9]+)*(?:\.[0-9]+)?)/i', $userInput, $bm) ||
            preg_match('/(?:<|<=)\s*(?:rs\.?|inr|\$|₹)?\s*([0-9]+(?:,[0-9]+)*(?:\.[0-9]+)?)/i', $userInput, $bm)) {
            $intent['max_price'] = (float)str_replace(',', '', $bm[1]);
        }

        // Strict budget directive
        if (preg_match('/\b(do not show anything above|strictly under|strict maximum|strict budget|nothing above)\b/i', $userInput)) {
            $intent['strict_budget'] = true;
        }

        // Currency detection and USD normalization
        $isINR = str_contains($userInput, '₹') || preg_match('/\b(inr|rupees?|rs\.?)\b/i', $userInput);
        $intent['currency'] = $isINR ? 'INR' : 'USD';
        if (isset($intent['max_price'])) {
            $rawMax = $intent['max_price'];
            $intent['max_price_inr'] = $isINR ? $rawMax : ($rawMax * INR_TO_USD_RATE);
            $intent['max_price_usd'] = $isINR ? round($rawMax / INR_TO_USD_RATE, 2) : $rawMax;
        }

        if (preg_match('/(?:above|more than|over|min(?:imum)?)\s*(?:rs\.?|inr|\$|₹)?\s*([0-9]+(?:,[0-9]+)*(?:\.[0-9]+)?)/i', $userInput, $bm)) {
            $intent['min_price'] = (float)str_replace(',', '', $bm[1]);
        }

        // Preferences (e.g. wireless, bluetooth, anc, 16gb)
        if (preg_match('/\b(wireless|bluetooth|true wireless|tws|anc|noise[- ]cancelling)\b/i', $userInput, $pm)) {
            $intent['preferences'][] = strtolower($pm[1]);
        }

        // 9. Popular / Bestsellers explicit flag
        if (str_contains($lower, 'popular') || str_contains($lower, 'bestseller') || str_contains($lower, 'trending') || str_contains($lower, 'top rated')) {
            $intent['is_popular_request'] = true;
        }

        // 10. Extract Product Type & Category
        foreach (self::$productTypes as $typeKey => $info) {
            $patterns = array_merge([$typeKey], $info['keywords'], $info['typos']);
            foreach ($patterns as $pat) {
                if (preg_match('/\b' . preg_quote($pat, '/') . '\b/i', $normalizedText)) {
                    $intent['product_type'] = $info['canonical'];
                    $intent['display_name'] = $info['display'];
                    $intent['category_id']  = $info['category_id'];
                    if ($intent['intent'] === 'general_conversation') {
                        $intent['intent'] = 'product_search';
                    }
                    break 2;
                }
            }
        }

        // 11. Extract Brand
        foreach (self::$brands as $bCanonical => $bList) {
            foreach ($bList as $bWord) {
                if (preg_match('/\b' . preg_quote($bWord, '/') . '\b/i', $normalizedText)) {
                    $intent['brand'] = ucfirst($bCanonical);
                    if ($intent['intent'] === 'general_conversation') {
                        $intent['intent'] = 'product_search';
                    }
                    break 2;
                }
            }
        }

        // Brand-Product Mismatch Detection (e.g. Nike laptops)
        if (!empty($intent['brand']) && !empty($intent['product_type'])) {
            $bKey = strtolower($intent['brand']);
            if (isset(self::$brandCategories[$bKey])) {
                $bInfo = self::$brandCategories[$bKey];
                if (in_array($intent['product_type'], $bInfo['does_not_make'] ?? [], true)) {
                    $intent['mismatch'] = [
                        'brand'        => $bInfo['name'],
                        'product_type' => $intent['product_type'],
                        'type'         => $bInfo['type']
                    ];
                }
            }
        }

        // 12. Context Continuity (Multi-Turn Follow-up)
        // Only inherit product type if current turn is a pure follow-up refinement (e.g. "under 40000", "prefer wireless")
        $isPureFollowUp = preg_match('/^(?:actually|now|instead)?\s*(?:keep it|make it|under|below|budget is|budget:|within)\s*(?:rs\.?|inr|\$|₹)?\s*[0-9]+/i', $normalizedText) ||
            preg_match('/^(?:rs\.?|inr|\$|₹)?\s*[0-9]+(?:\s*(?:inr|usd|rupees?|dollars?))?\s*(?:and\b.*)?$/i', $normalizedText) ||
            preg_match('/^(?:she|he|they)?\s*(?:also|too|as well)\s*(?:likes|prefers)/i', $normalizedText) ||
            preg_match('/^(?:i\s+)?prefer\s+(wireless|wired)/i', $normalizedText) ||
            preg_match('/^(?:with|only)\s+(16gb|8gb|32gb|wireless|wired)/i', $normalizedText);

        if (empty($intent['product_type']) && ($isPureFollowUp || !empty($chatHistory))) {
            // First inspect explicit chatHistory passed to function
            if (!empty($chatHistory)) {
                $revHistory = array_reverse($chatHistory);
                foreach ($revHistory as $prevMsg) {
                    $prevContent = strtolower($prevMsg['content'] ?? '');
                    foreach (self::$productTypes as $typeKey => $info) {
                        $patterns = array_merge([$typeKey], $info['keywords']);
                        foreach ($patterns as $pat) {
                            if (preg_match('/\b' . preg_quote($pat, '/') . '\b/i', $prevContent)) {
                                $intent['product_type'] = $info['canonical'];
                                $intent['display_name'] = $info['display'];
                                $intent['category_id']  = $info['category_id'];
                                $intent['is_follow_up'] = true;
                                $intent['intent']       = 'product_search';
                                break 3;
                            }
                        }
                    }
                }
            }

            // Fallback to active state engine request only if pure follow-up
            if (empty($intent['product_type']) && $isPureFollowUp) {
                $state = ShoppingStateEngine::getState();
                $activePType = $state['active_request']['product_type'] ?? null;
                if (!empty($activePType)) {
                    $typeInfo = self::$productTypes[$activePType] ?? null;
                    if ($typeInfo) {
                        $intent['product_type'] = $typeInfo['canonical'];
                        $intent['display_name'] = $typeInfo['display'];
                        $intent['category_id']  = $typeInfo['category_id'];
                        $intent['is_follow_up'] = true;
                        $intent['intent']       = 'product_search';
                        if (!empty($state['active_request']['preferences'])) {
                            $intent['preferences'] = array_unique(array_merge($intent['preferences'], $state['active_request']['preferences']));
                        }
                    }
                }
            }
        }

        // Technical specifications
        if (preg_match('/\b(16gb|8gb|32gb|ram|rtx|4k|128gb|256gb|512gb|1tb|i7|i5|ryzen)\b/i', $normalizedText, $reqM)) {
            $intent['requirements'][] = strtoupper($reqM[1]);
        }

        // Inherit previous budget if follow-up turn and max_price is not specified
        if ($isPureFollowUp && empty($intent['max_price'])) {
            $state = ShoppingStateEngine::getState();
            if (!empty($state['active_request']['budget'])) {
                $intent['currency']      = $state['active_request']['budget']['currency'];
                $intent['max_price']     = $state['active_request']['budget']['amount'];
                $intent['max_price_usd'] = $state['active_request']['budget']['maximum_usd'];
            } elseif (!empty($chatHistory)) {
                $revHistory = array_reverse($chatHistory);
                foreach ($revHistory as $prevMsg) {
                    $prevContent = $prevMsg['content'] ?? '';
                    if (preg_match('/(?:under|below|less than|within|upto|budget(?:\s*is|\s*of|\s*:)?)\s*(?:rs\.?|inr|\$|₹)?\s*([0-9]+(?:,[0-9]+)*(?:\.[0-9]+)?)/i', $prevContent, $pbm) ||
                        preg_match('/(?:rs\.?|inr|\$|₹)\s*([0-9]+(?:,[0-9]+)*(?:\.[0-9]+)?)/i', $prevContent, $pbm)) {
                        $rawNum = (float)str_replace(',', '', $pbm[1]);
                        $curr = (str_contains($prevContent, '₹') || preg_match('/\b(inr|rupees?|rs\.?)\b/i', $prevContent)) ? 'INR' : 'USD';
                        $intent['currency'] = $curr;
                        $intent['max_price'] = $rawNum;
                        $intent['max_price_usd'] = ($curr === 'INR') ? round($rawNum / INR_TO_USD_RATE, 2) : $rawNum;
                        break;
                    }
                }
            }
        }

        return $intent;
    }

    /**
     * Search Database Using Intent with Category Validation
     */
    public static function searchByIntent(array $intent): array {
        // If brand mismatch detected (e.g. Nike Laptops)
        if (!empty($intent['mismatch'])) {
            return [
                'status'   => 'mismatch',
                'products' => [],
                'intent'   => $intent,
                'message'  => "{$intent['mismatch']['brand']} does not manufacture {$intent['mismatch']['product_type']}s — {$intent['mismatch']['brand']} specializes in {$intent['mismatch']['type']}. Nexora offers laptops from computing brands such as ASUS, Lenovo, Dell, and HP. Would you like me to show budget-friendly laptops under ₹30,000, or explore {$intent['mismatch']['brand']} athletic footwear and apparel?"
            ];
        }

        $pdo = DB::getConnection();
        $results = [];

        $categoryId   = $intent['category_id'] ?? null;
        $productType  = $intent['product_type'] ?? null;
        $brand        = $intent['brand'] ?? null;
        $maxPrice     = (($intent['currency'] ?? 'USD') === 'INR' && !empty($intent['max_price_usd'])) 
                        ? $intent['max_price_usd'] 
                        : ($intent['max_price'] ?? null);
        $minPrice     = $intent['min_price'] ?? null;
        $isPopular    = $intent['is_popular_request'] ?? false;
        $requirements = $intent['requirements'] ?? [];
        $preferences  = $intent['preferences'] ?? [];

        // If no product type, category, or brand was identified, return empty results
        if (!$categoryId && !$productType && !$brand) {
            return [
                'status'   => 'not_found',
                'products' => [],
                'message'  => "This category isn't currently available in our store. Could I assist you with another department, such as electronics, laptops, watches, footwear, or audio gear?"
            ];
        }

        if ($pdo) {
            try {
                $sql = "SELECT p.*, c.name as category_name 
                        FROM products p 
                        JOIN categories c ON p.category_id = c.id 
                        WHERE 1=1";
                $params = [];

                if ($categoryId) {
                    $sql .= " AND p.category_id = ?";
                    $params[] = $categoryId;
                }

                // Strict product type validation:
                if ($productType === 'laptop') {
                    // Exclude accessories (cooling stand, pad, mouse, case)
                    $sql .= " AND p.name NOT ILIKE '%stand%' AND p.name NOT ILIKE '%cooling%' AND p.name NOT ILIKE '%pad%' AND p.name NOT ILIKE '%mouse%' AND p.name NOT ILIKE '%keyboard%' AND p.name NOT ILIKE '%monitor%'";
                    $sql .= " AND (p.name ILIKE '%laptop%' OR p.name ILIKE '%ultrabook%' OR p.name ILIKE '%notebook%')";
                } elseif ($productType === 'headphones') {
                    // Exclude accessories (stands, cases, arms, receivers, speakers)
                    $sql .= " AND p.name NOT ILIKE '%stand%' AND p.name NOT ILIKE '%case%' AND p.name NOT ILIKE '%receiver%' AND p.name NOT ILIKE '%boom arm%' AND p.name NOT ILIKE '%speaker%' AND p.name NOT ILIKE '%soundbar%'";
                    $sql .= " AND (p.name ILIKE '%headphone%' OR p.name ILIKE '%earbud%')";
                    if (in_array('wireless', $preferences, true)) {
                        $sql .= " AND (p.name ILIKE '%wireless%' OR p.name ILIKE '%bluetooth%' OR p.name ILIKE '%tws%' OR p.name ILIKE '%anc%')";
                    }
                } elseif ($productType === 'shoes') {
                    // If dance shoes requested specifically
                    if (str_contains(strtolower($intent['raw_query'] ?? ''), 'dance')) {
                        $sql .= " AND (p.name ILIKE '%dance%' OR p.name ILIKE '%cross-training%' OR p.name ILIKE '%gym shoe%' OR p.name ILIKE '%sneaker%')";
                    }
                } elseif ($productType) {
                    $typeConfig = self::$productTypes[$productType] ?? null;
                    if ($typeConfig && !empty($typeConfig['keywords'])) {
                        $kwClauses = [];
                        foreach ($typeConfig['keywords'] as $kw) {
                            $kwClauses[] = "p.name ILIKE ?";
                            $params[] = '%' . $kw . '%';
                        }
                        $sql .= " AND (" . implode(" OR ", $kwClauses) . ")";
                    }
                }

                if ($brand) {
                    $sql .= " AND (p.name ILIKE ? OR p.description ILIKE ?)";
                    $params[] = '%' . $brand . '%';
                    $params[] = '%' . $brand . '%';
                }

                if ($maxPrice !== null && $maxPrice > 0) {
                    $sql .= " AND p.price <= ?";
                    $params[] = $maxPrice;
                }

                if ($minPrice !== null && $minPrice > 0) {
                    $sql .= " AND p.price >= ?";
                    $params[] = $minPrice;
                }

                foreach ($requirements as $req) {
                    $sql .= " AND (p.name ILIKE ? OR p.description ILIKE ?)";
                    $params[] = '%' . $req . '%';
                    $params[] = '%' . $req . '%';
                }

                if ($isPopular) {
                    $sql .= " ORDER BY p.rating DESC, p.reviews_count DESC LIMIT 6";
                } else {
                    $sql .= " ORDER BY p.rating DESC, p.price ASC LIMIT 6";
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($rows as $row) {
                    $results[] = [
                        'id'          => (int)$row['id'],
                        'name'        => $row['name'],
                        'price'       => (float)$row['price'],
                        'description' => $row['description'],
                        'category'    => $row['category_name'],
                        'rating'      => (float)$row['rating'],
                        'emoji'       => $row['emoji'] ?? '📦',
                        'image'       => $row['image_url'] ?? 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600&auto=format&fit=crop&q=80',
                        'stock'       => (int)$row['stock']
                    ];
                }
            } catch (Exception $e) {
                error_log("Database searchByIntent error: " . $e->getMessage());
            }
        }

        // Fallback to in-memory catalog
        if (empty($results) && function_exists('get_all_products')) {
            $all = get_all_products();
            foreach ($all as $p) {
                if ($categoryId && (int)$p['category_id'] !== $categoryId) {
                    continue;
                }
                $nameLower = strtolower($p['name']);

                if ($productType === 'laptop') {
                    if (str_contains($nameLower, 'stand') || str_contains($nameLower, 'cooling') ||
                        str_contains($nameLower, 'pad') || str_contains($nameLower, 'mouse') ||
                        str_contains($nameLower, 'keyboard') || str_contains($nameLower, 'monitor')) {
                        continue;
                    }
                    if (!str_contains($nameLower, 'laptop') && !str_contains($nameLower, 'ultrabook') && !str_contains($nameLower, 'notebook')) {
                        continue;
                    }
                } elseif ($productType === 'headphones') {
                    if (str_contains($nameLower, 'stand') || str_contains($nameLower, 'case') ||
                        str_contains($nameLower, 'receiver') || str_contains($nameLower, 'boom arm') ||
                        str_contains($nameLower, 'speaker') || str_contains($nameLower, 'soundbar')) {
                        continue;
                    }
                    if (!str_contains($nameLower, 'headphone') && !str_contains($nameLower, 'earbud')) {
                        continue;
                    }
                    if (in_array('wireless', $preferences, true)) {
                        if (!str_contains($nameLower, 'wireless') && !str_contains($nameLower, 'bluetooth') && !str_contains($nameLower, 'tws') && !str_contains($nameLower, 'anc')) {
                            continue;
                        }
                    }
                } elseif ($productType === 'shoes') {
                    if (str_contains(strtolower($intent['raw_query'] ?? ''), 'dance')) {
                        if (!str_contains($nameLower, 'dance') && !str_contains($nameLower, 'cross-training') && !str_contains($nameLower, 'gym shoe') && !str_contains($nameLower, 'sneaker')) {
                            continue;
                        }
                    }
                } elseif ($productType) {
                    $typeConfig = self::$productTypes[$productType] ?? null;
                    $matched = false;
                    if ($typeConfig) {
                        foreach ($typeConfig['keywords'] as $kw) {
                            if (str_contains($nameLower, strtolower($kw))) {
                                $matched = true;
                                break;
                            }
                        }
                    }
                    if (!$matched) continue;
                }

                if ($brand && !str_contains($nameLower, strtolower($brand))) {
                    continue;
                }
                if ($maxPrice !== null && (float)$p['price'] > $maxPrice) {
                    continue;
                }

                $results[] = [
                    'id'       => (int)$p['id'],
                    'name'     => $p['name'],
                    'price'    => (float)$p['price'],
                    'category' => $p['category'] ?? 'General',
                    'rating'   => (float)$p['rating'],
                    'emoji'    => $p['emoji'] ?? '📦',
                    'image'    => $p['image'] ?? 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600&auto=format&fit=crop&q=80'
                ];
            }

            if (!empty($results)) {
                usort($results, function($a, $b) use ($isPopular) {
                    if ($b['rating'] != $a['rating']) {
                        return ($b['rating'] <=> $a['rating']);
                    }
                    return $isPopular ? 0 : ($a['price'] <=> $b['price']);
                });
                $results = array_slice($results, 0, 6);
            }
        }

        // Save last displayed products into state for reference resolution
        $state = ShoppingStateEngine::getState();
        $state['last_displayed_products'] = $results;
        if (isset($state['active_request'])) {
            $state['active_request']['last_displayed_products'] = $results;
        }
        ShoppingStateEngine::saveState($state);

        if (empty($results)) {
            return [
                'status'   => 'not_found',
                'products' => [],
                'intent'   => $intent
            ];
        }

        return [
            'status'   => 'success',
            'count'    => count($results),
            'products' => $results,
            'intent'   => $intent
        ];
    }

    /**
     * Generate Natural Response Message with Currency & Category Clarity
     */
    public static function buildNaturalResponse(array $intent, array $searchResult): string {
        $pType = $intent['display_name'] ?? $intent['product_type'] ?? 'product';
        $maxP = $intent['max_price'];
        $count = count($searchResult['products'] ?? []);

        // Mismatch response
        if (!empty($searchResult['message'])) {
            return $searchResult['message'];
        }

        if ($count > 0) {
            if ($maxP !== null) {
                if ($intent['currency'] === 'INR') {
                    $formattedInr = number_format($maxP);
                    $formattedUsd = number_format($intent['max_price_usd'] ?? ($maxP / INR_TO_USD_RATE), 2);
                    return "Here are the available {$pType} options within your ₹{$formattedInr} budget (approx. \${$formattedUsd} USD at 1 USD ≈ 83 INR):";
                } else {
                    $formattedPrice = number_format($maxP, 2);
                    return "Here are the available {$pType} options within your \${$formattedPrice} USD budget:";
                }
            }
            if ($intent['is_follow_up']) {
                return "Here are the updated {$pType} options matching your preferences:";
            }
            return "Sure! Here are our recommended {$pType}s from our store catalog:";
        }

        // When no products match:
        if ($intent['product_type'] === 'shoes' && str_contains(strtolower($intent['raw_query'] ?? ''), 'dance')) {
            $budgetInfo = $maxP !== null ? " under ₹" . number_format($maxP) : "";
            return "I searched our catalog, but we currently don't have matching dance shoes in stock{$budgetInfo}. Our cross-training gym shoes and athletic footwear start at \$59.99 (approx. ₹4,979). Would you like to check alternative footwear or explore other dance-related items such as audio and fitness gear?";
        }

        if ($intent['product_type'] === 'headphones' && in_array('wireless', $intent['preferences'] ?? [], true)) {
            $budgetInfo = $maxP !== null ? " within your ₹" . number_format($maxP) . " budget (approx. \$" . number_format($intent['max_price_usd'], 2) . " USD at 1 USD ≈ 83 INR)" : "";
            return "I searched our catalog, but we currently don't have wireless headphones in stock{$budgetInfo}. Our wireless audio models start at \$69.99 (approx. ₹5,809) for True Wireless Earbuds with ANC. Would you prefer to check our wired in-ear monitors (such as the Dual Driver IEM at \$39.99) or consider adjusting your budget for a wireless pair?";
        }

        if (!empty($intent['brand']) && strtolower($intent['brand']) === 'samsung' && $intent['product_type'] === 'phone') {
            $budgetInfo = $maxP !== null ? " under ₹" . number_format($maxP) : "";
            return "Our catalog features the Samsung Galaxy Flagship 5G Smartphone at \$899.99 (approx. ₹74,699), which exceeds your budget{$budgetInfo}. We currently do not have Samsung smartphones under ₹20,000 in stock. Would you like to check other smartphone brands or explore our Samsung flagship?";
        }

        if ($maxP !== null) {
            if ($intent['currency'] === 'INR') {
                $formattedInr = number_format($maxP);
                $formattedUsd = number_format($intent['max_price_usd'] ?? ($maxP / INR_TO_USD_RATE), 2);
                return "I couldn't find any {$pType}s in our catalog under ₹{$formattedInr} (approx. \${$formattedUsd} USD). Would you like to check our available {$pType}s at standard pricing?";
            } else {
                $formattedPrice = number_format($maxP, 2);
                return "I couldn't find any {$pType}s in our catalog under \${$formattedPrice} USD. Would you like to check our available {$pType}s at standard pricing?";
            }
        }

        if ($intent['brand']) {
            return "We currently don't carry {$intent['brand']} {$pType}s in stock. Would you like to explore our other top-rated {$pType} brands?";
        }

        return "This category isn't currently available in our store. Could I assist you with another department, such as electronics, laptops, watches, footwear, or audio gear?";
    }
}
