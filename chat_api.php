<?php
/**
 * Nexora AI Assistant - Chat API Endpoint
 * Handles conversational queries, Gemini LLM function calling (tools),
 * database product discovery, order tracking, transactions, and cart actions.
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/orders_service.php';
require_once __DIR__ . '/chatbot_intent.php';
require_once __DIR__ . '/chatbot_state.php';

// Initialize session for conversation state isolation
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// If required by a test script, do not execute the HTTP handler immediately
if (php_sapi_name() === 'cli' && basename(__FILE__) !== basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    return;
}

// Only accept POST requests
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. POST required.']);
    exit;
}

// Read and decode incoming JSON payload
$rawInput = file_get_contents('php://input');
$inputData = json_decode($rawInput, true);

if (!is_array($inputData) || empty($inputData['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request. "messages" array is required.']);
    exit;
}

// Sanitize and limit conversation history (last 10 messages)
$messages = array_slice($inputData['messages'], -10);
$sanitizedMessages = [];
foreach ($messages as $m) {
    if (!isset($m['role'], $m['content'])) continue;
    $role = ($m['role'] === 'user') ? 'user' : 'model';
    // Limit message length to 1000 characters
    $content = substr(trim((string)$m['content']), 0, 1000);
    if ($content !== '') {
        $sanitizedMessages[] = [
            'role' => $role,
            'content' => $content
        ];
    }
}

if (empty($sanitizedMessages)) {
    echo json_encode(['reply' => 'How can I assist your shopping on Nexora today?', 'products' => []]);
    exit;
}

// Global products collector for cards rendered in chat
$discoveredProducts = [];

// Initialize and update cumulative shopping state
$shoppingState = ShoppingStateEngine::processTurn($sanitizedMessages);

// ========================================================
// ASSISTANT TOOL FUNCTIONS (PDO Prepared Statements Only)
// ========================================================

/**
 * Tool 1: Search products in the store catalog
 */
function search_products(?string $keyword = null, ?string $category = null, ?float $max_price = null, ?int $limit = 6): array {
    global $discoveredProducts;

    $state = ShoppingStateEngine::getState();
    if (($max_price === null || $max_price <= 0) && !empty($state['budget']['maximum_usd'])) {
        $max_price = (float)$state['budget']['maximum_usd'];
    }
    if ($limit === null || $limit <= 0) {
        $limit = $state['requested_count'] ?? 6;
    }

    $queryToAnalyze = trim(($keyword ?? '') . ' ' . ($category ?? ''));

    // Fallback to active request product type if query is empty
    if (empty($queryToAnalyze) && !empty($state['active_request']['product_type'])) {
        $queryToAnalyze = $state['active_request']['product_type'];
        if (!empty($state['active_request']['preferences'])) {
            $queryToAnalyze .= ' ' . implode(' ', $state['active_request']['preferences']);
        }
    }

    // 1. If active state or query is for gifts, route to intelligent gift recommendation engine
    if (($state['intent'] ?? '') === 'gift_recommendation' || preg_match('/\b(gift|gifts|present|presents)\b/i', $queryToAnalyze)) {
        $giftRes = ShoppingStateEngine::recommendGifts($state);
        if (!empty($giftRes['products'])) {
            foreach ($giftRes['products'] as $p) {
                $discoveredProducts[$p['id']] = $p;
            }
            return $giftRes;
        }
        return ['status' => 'not_found', 'products' => [], 'message' => 'No matching gift items found in catalog within the specified criteria.'];
    }

    // 2. First analyze with ChatbotIntent for typo correction & category matching
    if (!empty($queryToAnalyze)) {
        $intent = ChatbotIntent::detectIntent($queryToAnalyze);
        if ($max_price !== null && $max_price > 0 && empty($intent['max_price'])) {
            $intent['max_price'] = $max_price;
        }
        if (!empty($intent['product_type']) || !empty($intent['brand']) || !empty($intent['category_id'])) {
            $intentSearch = ChatbotIntent::searchByIntent($intent);
            if (!empty($intentSearch['products'])) {
                foreach ($intentSearch['products'] as $p) {
                    $discoveredProducts[$p['id']] = $p;
                }
                return $intentSearch;
            }
            return $intentSearch;
        }
    }

    if (empty($keyword) && empty($category)) {
        return ['status' => 'not_found', 'products' => [], 'message' => 'No products found matching those criteria in our store.'];
    }

    $pdo = DB::getConnection();
    $keyword = trim((string)$keyword);
    $category = trim((string)$category);

    $results = [];

    if ($pdo) {
        try {
            $sql = "SELECT p.id, p.name, p.price, p.description, p.emoji, p.rating, p.reviews_count, p.stock, p.image_url, c.name as category_name
                    FROM products p
                    JOIN categories c ON p.category_id = c.id
                    WHERE 1=1";
            $params = [];

            if (!empty($keyword)) {
                $sql .= " AND (p.name ILIKE ? OR p.description ILIKE ? OR c.name ILIKE ?)";
                $kw = '%' . $keyword . '%';
                $params[] = $kw;
                $params[] = $kw;
                $params[] = $kw;
            }

            if (!empty($category)) {
                $sql .= " AND (c.name ILIKE ? OR c.slug ILIKE ?)";
                $cat = '%' . $category . '%';
                $params[] = $cat;
                $params[] = $cat;
            }

            if ($max_price !== null && $max_price > 0) {
                $sql .= " AND p.price <= ?";
                $params[] = $max_price;
            }

            $sql .= " ORDER BY p.rating DESC, p.id ASC LIMIT " . (int)$limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $row) {
                $item = [
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
                $results[] = $item;
                $discoveredProducts[$item['id']] = $item;
            }
        } catch (Exception $e) {
            error_log("Chatbot search_products error: " . $e->getMessage());
        }
    }

    // Fallback if DB empty or offline
    if (empty($results) && function_exists('get_all_products')) {
        $all = get_all_products();
        foreach ($all as $p) {
            $match = true;
            if (!empty($keyword)) {
                $q = strtolower($keyword);
                if (!str_contains(strtolower($p['name']), $q) && !str_contains(strtolower($p['description']), $q)) {
                    $match = false;
                }
            }
            if ($match && !empty($category)) {
                $q = strtolower($category);
                if (!str_contains(strtolower($p['category'] ?? ''), $q)) {
                    $match = false;
                }
            }
            if ($match && $max_price !== null && $max_price > 0) {
                if ((float)$p['price'] > $max_price) {
                    $match = false;
                }
            }
            if ($match) {
                $results[] = [
                    'id'       => (int)$p['id'],
                    'name'     => $p['name'],
                    'price'    => (float)$p['price'],
                    'category' => $p['category'] ?? 'General',
                    'rating'   => (float)$p['rating'],
                    'emoji'    => $p['emoji'] ?? '📦',
                    'image'    => $p['image'] ?? 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600&auto=format&fit=crop&q=80'
                ];
                $discoveredProducts[$p['id']] = $results[count($results) - 1];
                if (count($results) >= 6) break;
            }
        }
    }

    if (empty($results)) {
        return ['status' => 'not_found', 'message' => 'No products found matching those criteria in our store.'];
    }

    return ['status' => 'success', 'count' => count($results), 'products' => $results];
}

/**
 * Tool 2: Track order and delivery status by tracking code or order number
 */
function track_order(string $tracking_number_or_order_number): array {
    $code = trim($tracking_number_or_order_number);
    if (empty($code)) {
        return ['error' => 'Please provide a valid tracking number or order number.'];
    }

    // Reuse existing delivery service
    $delivery = OrdersService::getDeliveryByTracking($code);

    if (!$delivery) {
        return [
            'status' => 'not_found',
            'message' => 'No shipment record was found for "' . htmlspecialchars($code) . '". Please check your tracking number (e.g. TRK-FEDEX-...) or order number (e.g. ORD-2026-...).'
        ];
    }

    // Return safe public status details only
    $latestEvent = !empty($delivery['timeline']) ? end($delivery['timeline']) : null;

    return [
        'status'             => 'found',
        'order_number'       => $delivery['order_number'] ?? 'N/A',
        'tracking_number'    => $delivery['tracking_number'],
        'courier'            => $delivery['courier_name'],
        'delivery_status'    => $delivery['delivery_status'],
        'current_location'   => $delivery['current_location'] ?? 'In Transit',
        'estimated_delivery' => $delivery['estimated_delivery'] ?? '3 business days',
        'latest_activity'    => $latestEvent ? ($latestEvent['activity_description'] ?? $latestEvent['status']) : ($delivery['notes'] ?? 'Package processed'),
        'items_count'        => !empty($delivery['items']) ? count($delivery['items']) : 1
    ];
}

/**
 * Tool 3: Get payment and transaction status
 */
function get_transactions(string $order_number_or_transaction_id): array {
    $code = trim($order_number_or_transaction_id);
    if (empty($code)) {
        return ['error' => 'Please provide an order number or transaction reference.'];
    }

    $pdo = DB::getConnection();
    if ($pdo) {
        try {
            $stmt = $pdo->prepare("
                SELECT t.transaction_ref, t.amount, t.currency, t.payment_method, t.payment_status, t.created_at, o.order_number
                FROM transactions t
                JOIN orders o ON t.order_id = o.id
                WHERE t.transaction_ref ILIKE ? OR o.order_number ILIKE ?
                LIMIT 1
            ");
            $stmt->execute([$code, $code]);
            $txn = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($txn) {
                return [
                    'status'          => 'found',
                    'transaction_ref' => $txn['transaction_ref'],
                    'order_number'    => $txn['order_number'],
                    'amount'          => '$' . number_format((float)$txn['amount'], 2),
                    'payment_method'  => $txn['payment_method'],
                    'payment_status'  => $txn['payment_status'],
                    'date'            => date('M d, Y H:i', strtotime($txn['created_at']))
                ];
            }
        } catch (Exception $e) {
            error_log("Chatbot get_transactions error: " . $e->getMessage());
        }
    }

    // Check fallback session transactions
    $allTxns = OrdersService::getTransactions();
    foreach ($allTxns as $t) {
        if (strcasecmp($t['transaction_ref'] ?? '', $code) === 0 || strcasecmp($t['order_number'] ?? '', $code) === 0) {
            return [
                'status'          => 'found',
                'transaction_ref' => $t['transaction_ref'],
                'order_number'    => $t['order_number'] ?? 'N/A',
                'amount'          => '$' . number_format((float)$t['amount'], 2),
                'payment_method'  => $t['payment_method'],
                'payment_status'  => $t['payment_status'],
                'date'            => $t['created_at'] ?? 'Recent'
            ];
        }
    }

    return [
        'status' => 'not_found',
        'message' => 'No transaction record was found for "' . htmlspecialchars($code) . '". Please check your transaction reference (e.g. TXN-...) or order number.'
    ];
}

/**
 * Tool 4: Add product to shopping cart
 */
function add_to_cart(int $product_id, int $quantity = 1): array {
    global $discoveredProducts;
    if ($quantity < 1) $quantity = 1;
    if ($quantity > 10) $quantity = 10;

    $product = get_product_by_id($product_id);
    if (!$product) {
        return ['status' => 'error', 'message' => "Product ID #{$product_id} is not available in our store."];
    }

    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }

    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    $_SESSION['cart'][$product_id] = ($_SESSION['cart'][$product_id] ?? 0) + $quantity;

    // Calculate updated cart total
    $totalCount = 0;
    foreach ($_SESSION['cart'] as $qty) {
        $totalCount += $qty;
    }

    $discoveredProducts[$product['id']] = $product;

    return [
        'status'          => 'success',
        'product_id'      => $product_id,
        'product_name'    => $product['name'],
        'price'           => '$' . number_format($product['price'], 2),
        'quantity_added'  => $quantity,
        'cart_total_count'=> $totalCount,
        'message'         => "Added {$quantity}x {$product['name']} to your cart! You now have {$totalCount} item(s) in your cart."
    ];
}

// Execute requested tool safely by name
function executeToolCall(string $toolName, array $args): array {
    switch ($toolName) {
        case 'search_products':
            return search_products(
                $args['keyword'] ?? null,
                $args['category'] ?? null,
                isset($args['max_price']) ? (float)$args['max_price'] : null
            );
        case 'track_order':
            return track_order($args['tracking_number_or_order_number'] ?? '');
        case 'get_transactions':
            return get_transactions($args['order_number_or_transaction_id'] ?? '');
        case 'add_to_cart':
            return add_to_cart(
                (int)($args['product_id'] ?? 0),
                (int)($args['quantity'] ?? 1)
            );
        default:
            return ['error' => 'Unknown tool requested.'];
    }
}

// ========================================================
// LLM INTEGRATION (Google Gemini API via cURL)
// ========================================================

$baseSystemPrompt = "You are Nexora AI Assistant, an intelligent shopping advisor for the Nexora e-commerce store. Answer shopping and product questions with clear, short, practical advice. When useful, call the provided functions to find real products from our store, track orders, check payments, or add items to the cart. Only recommend products returned by the functions; never invent products, prices, or stock.
For gift recommendations, carefully check the CURRENT STRUCTURED SHOPPING STATE provided below. If the recipient, occasion, budget, or interests are ALREADY KNOWN, DO NOT re-ask those questions; immediately search and recommend matching items. If the user specifies an item count (e.g., 10 gift ideas), recommend up to that requested count. Only ask questions when critical information (like currency or interests) is genuinely missing.
For medical or financial questions, give only brief, safe, general information. Politely decline topics unrelated to shopping. Keep replies concise.
Support details:
- Payment methods: Credit/Debit Cards, PayPal (with instant PayPal Express Checkout), UPI / Net Banking, and Apple Pay.
- Return/Refund policy: 30-day money-back guarantee with hassle-free returns on all products.
- PayPal checkout: Select PayPal at checkout to pay securely with your PayPal balance, linked bank account, or PayPal credit card.";

$systemPrompt = $baseSystemPrompt;

// Gemini Tools Declaration
$geminiTools = [
    [
        'functionDeclarations' => [
            [
                'name' => 'search_products',
                'description' => 'Search the Nexora catalog for products matching a keyword, category, or maximum price. Always call this to find real store products to recommend.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'keyword' => ['type' => 'STRING', 'description' => 'Search query, product name, or keyword (e.g. "smartphone", "laptop", "shoes", "table")'],
                        'category' => ['type' => 'STRING', 'description' => 'Optional category name (e.g. "Electronics", "Furniture", "Footwear")'],
                        'max_price' => ['type' => 'NUMBER', 'description' => 'Maximum price in USD']
                    ]
                ]
            ],
            [
                'name' => 'track_order',
                'description' => 'Look up live parcel delivery and courier tracking info using an order number (e.g. ORD-2026-XXXXX) or tracking number (e.g. TRK-FEDEX-XXXXXX).',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'tracking_number_or_order_number' => ['type' => 'STRING', 'description' => 'The order or tracking number']
                    ],
                    'required' => ['tracking_number_or_order_number']
                ]
            ],
            [
                'name' => 'get_transactions',
                'description' => 'Check payment transaction and receipt status using a transaction reference (e.g. TXN-XXXXXX) or order number.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'order_number_or_transaction_id' => ['type' => 'STRING', 'description' => 'The transaction reference or order number']
                    ],
                    'required' => ['order_number_or_transaction_id']
                ]
            ],
            [
                'name' => 'add_to_cart',
                'description' => 'Add an item to the shopping cart by its numeric product ID.',
                'parameters' => [
                    'type' => 'OBJECT',
                    'properties' => [
                        'product_id' => ['type' => 'INTEGER', 'description' => 'The product ID from our store'],
                        'quantity'   => ['type' => 'INTEGER', 'description' => 'Quantity to add (default 1)']
                    ],
                    'required' => ['product_id']
                ]
            ]
        ]
    ]
];

// OpenRouter / OpenAI-Compatible Tools Declaration
$openAiTools = [
    [
        'type' => 'function',
        'function' => [
            'name' => 'search_products',
            'description' => 'Search the Nexora catalog for products matching a keyword, category, or maximum price. Always call this to find real store products to recommend.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'keyword' => ['type' => 'string', 'description' => 'Search query, product name, or keyword (e.g. "smartphone", "laptop", "shoes", "table")'],
                    'category' => ['type' => 'string', 'description' => 'Optional category name (e.g. "Electronics", "Furniture", "Footwear")'],
                    'max_price' => ['type' => 'number', 'description' => 'Maximum price in USD']
                ]
            ]
        ]
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'track_order',
            'description' => 'Look up live parcel delivery and courier tracking info using an order number (e.g. ORD-2026-XXXXX) or tracking number (e.g. TRK-FEDEX-XXXXXX).',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'tracking_number_or_order_number' => ['type' => 'string', 'description' => 'The order or tracking number']
                ],
                'required' => ['tracking_number_or_order_number']
            ]
        ]
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'get_transactions',
            'description' => 'Check payment transaction and receipt status using a transaction reference (e.g. TXN-XXXXXX) or order number.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'order_number_or_transaction_id' => ['type' => 'string', 'description' => 'The transaction reference or order number']
                ],
                'required' => ['order_number_or_transaction_id']
            ]
        ]
    ],
    [
        'type' => 'function',
        'function' => [
            'name' => 'add_to_cart',
            'description' => 'Add an item to the shopping cart by its numeric product ID.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'product_id' => ['type' => 'integer', 'description' => 'The product ID from our store'],
                    'quantity'   => ['type' => 'integer', 'description' => 'Quantity to add (default 1)']
                ],
                'required' => ['product_id']
            ]
        ]
    ]
];

/**
 * Single isolated function to call the LLM provider (Google Gemini or OpenRouter via cURL)
 */
function callLLM(array $chatMessages, array $tools): array {
    $apiKey = LLM_API_KEY;
    $model = LLM_MODEL;
    $provider = strtolower((string)LLM_PROVIDER);

    if (empty($apiKey)) {
        return fallbackAdvisor($chatMessages);
    }

    // Auto-detect OpenRouter by key prefix or explicit configuration
    $isOpenRouter = ($provider === 'openrouter') 
        || str_starts_with($apiKey, 'sk-or-') 
        || !empty(getenv('OPENROUTER_API_KEY'));

    if ($isOpenRouter) {
        return callOpenRouterLLM($chatMessages, $apiKey, $model);
    }

    return callGeminiLLM($chatMessages, $tools, $apiKey, $model ?: 'gemini-1.5-flash');
}

/**
 * OpenRouter LLM Provider Call
 */
function callOpenRouterLLM(array $chatMessages, string $apiKey, ?string $model): array {
    global $baseSystemPrompt, $openAiTools, $discoveredProducts, $shoppingState;

    // Use OpenRouter auto model if unspecified or set to auto
    if (empty($model) || $model === 'auto' || $model === 'gemini-1.5-flash') {
        $model = 'openrouter/auto';
    }

    $stateSummary = !empty($shoppingState) ? ShoppingStateEngine::getStatePromptSummary($shoppingState) : '';
    $effectiveSystemPrompt = trim($baseSystemPrompt . "\n\n" . $stateSummary);

    $messages = [
        ['role' => 'system', 'content' => $effectiveSystemPrompt]
    ];
    foreach ($chatMessages as $msg) {
        $messages[] = [
            'role' => ($msg['role'] === 'user') ? 'user' : 'assistant',
            'content' => $msg['content']
        ];
    }

    $payload = [
        'model' => $model,
        'messages' => $messages,
        'tools' => $openAiTools,
        'temperature' => 0.3,
        'max_tokens' => 600
    ];

    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
        'HTTP-Referer: http://localhost:8000',
        'X-Title: Nexora E-Commerce'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }

    $rawResponse = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($curlErr || $httpCode !== 200) {
        error_log("OpenRouter API call failed (HTTP $httpCode): " . ($curlErr ?: $rawResponse));
        return fallbackAdvisor($chatMessages);
    }

    $json = json_decode($rawResponse, true);
    $choice = $json['choices'][0] ?? null;
    $msg = $choice['message'] ?? [];
    $textReply = $msg['content'] ?? '';
    $toolCalls = $msg['tool_calls'] ?? [];

    if (!empty($toolCalls)) {
        $messages[] = $msg;
        foreach ($toolCalls as $tc) {
            $fnName = $tc['function']['name'] ?? '';
            $fnArgs = json_decode($tc['function']['arguments'] ?? '{}', true) ?: [];
            $toolResult = executeToolCall($fnName, $fnArgs);
            $messages[] = [
                'role' => 'tool',
                'tool_call_id' => $tc['id'] ?? ('call_' . uniqid()),
                'content' => json_encode($toolResult)
            ];
        }

        $payload['messages'] = $messages;
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $secondRaw = curl_exec($ch);
        $secondHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($secondHttpCode === 200) {
            $secondJson = json_decode($secondRaw, true);
            $secondChoice = $secondJson['choices'][0] ?? null;
            $secondContent = $secondChoice['message']['content'] ?? '';
            if (!empty($secondContent)) {
                $textReply = $secondContent;
            }
        }
    }

    if (empty(trim($textReply))) {
        return fallbackAdvisor($chatMessages);
    }

    return [
        'reply' => trim($textReply),
        'products' => array_values($discoveredProducts)
    ];
}

/**
 * Google Gemini LLM Provider Call
 */
function callGeminiLLM(array $chatMessages, array $tools, string $apiKey, string $model): array {
    global $baseSystemPrompt, $discoveredProducts, $shoppingState;

    $stateSummary = !empty($shoppingState) ? ShoppingStateEngine::getStatePromptSummary($shoppingState) : '';
    $effectiveSystemPrompt = trim($baseSystemPrompt . "\n\n" . $stateSummary);

    // Build Gemini contents array
    $contents = [];
    foreach ($chatMessages as $msg) {
        $contents[] = [
            'role'  => ($msg['role'] === 'user') ? 'user' : 'model',
            'parts' => [['text' => $msg['content']]]
        ];
    }

    $payload = [
        'systemInstruction' => [
            'parts' => [['text' => $effectiveSystemPrompt]]
        ],
        'contents' => $contents,
        'tools' => $tools,
        'generationConfig' => [
            'temperature' => 0.3,
            'maxOutputTokens' => 600
        ]
    ];

    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 12);
    if (defined('CURLSSLOPT_NATIVE_CA')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }

    $rawResponse = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($curlErr || $httpCode !== 200) {
        error_log("Gemini API call failed (HTTP $httpCode): " . ($curlErr ?: $rawResponse));
        return fallbackAdvisor($chatMessages);
    }

    $json = json_decode($rawResponse, true);
    $candidate = $json['candidates'][0] ?? null;
    $parts = $candidate['content']['parts'] ?? [];

    $functionCallPart = null;
    $textReply = '';

    foreach ($parts as $p) {
        if (!empty($p['functionCall'])) {
            $functionCallPart = $p['functionCall'];
        }
        if (!empty($p['text'])) {
            $textReply .= $p['text'];
        }
    }

    // Handle tool execution loop if Gemini requested a function call
    if ($functionCallPart) {
        $fnName = $functionCallPart['name'] ?? '';
        $fnArgs = $functionCallPart['args'] ?? [];

        $toolResult = executeToolCall($fnName, $fnArgs);

        // Append assistant tool call and tool result to conversation for final resolution
        $contents[] = [
            'role' => 'model',
            'parts' => [['functionCall' => $functionCallPart]]
        ];
        $contents[] = [
            'role' => 'user',
            'parts' => [
                [
                    'functionResponse' => [
                        'name' => $fnName,
                        'response' => ['result' => $toolResult]
                    ]
                ]
            ]
        ];

        // Second round-trip to generate final answer incorporating tool result
        $payload['contents'] = $contents;
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        $secondResponse = curl_exec($ch);
        $secondHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($secondHttpCode === 200) {
            $secondJson = json_decode($secondResponse, true);
            $secondParts = $secondJson['candidates'][0]['content']['parts'] ?? [];
            $finalText = '';
            foreach ($secondParts as $sp) {
                if (!empty($sp['text'])) $finalText .= $sp['text'];
            }
            if (!empty($finalText)) {
                $textReply = $finalText;
            }
        }
    }

    if (empty(trim($textReply))) {
        return fallbackAdvisor($chatMessages);
    }

    return [
        'reply' => trim($textReply),
        'products' => array_values($discoveredProducts)
    ];
}

/**
 * Intelligent local fallback advisor
 * Uses deterministic typo correction, intent detection & category matching
 * when no LLM API key is present or when network is offline.
 */
function fallbackAdvisor(array $chatMessages): array {
    global $discoveredProducts, $shoppingState;
    $discoveredProducts = []; // Reset discovered products for a clean turn

    $lastMsg = end($chatMessages);
    $text = trim($lastMsg['content'] ?? '');

    // Analyze intent using ChatbotIntent engine with multi-turn conversation history
    $intent = ChatbotIntent::detectIntent($text, $chatMessages);

    // Cumulative state tracking
    $state = $shoppingState ?? ShoppingStateEngine::processTurn($chatMessages);

    // 0. Reference Resolution & Specifications Comparison
    if (!empty($state['active_request']['reference_result'])) {
        $ref = $state['active_request']['reference_result'];
        return [
            'reply' => $ref['reply'],
            'products' => $ref['products'] ?? []
        ];
    }

    // 1. General Conversation / Greeting
    if ($intent['intent'] === 'general_conversation') {
        return [
            'reply' => "Hello! Welcome to Nexora. How can I help you with your shopping today? Feel free to ask about our laptops, watches, smartphones, headphones, or track an existing order.",
            'products' => []
        ];
    }

    // 2. Order Tracking Intent (No cross-user leaks)
    if ($intent['intent'] === 'track_order') {
        $queryCode = $intent['tracking_code'] ?? '';
        if (empty($queryCode)) {
            if (!empty($_SESSION['user_id'])) {
                $userOrders = OrdersService::getUserOrders((int)$_SESSION['user_id']);
                if (!empty($userOrders)) {
                    $firstOrder = reset($userOrders);
                    $queryCode = $firstOrder['tracking_number'] ?? $firstOrder['order_number'] ?? '';
                }
            } elseif (!empty($_SESSION['last_order']['tracking_number'])) {
                $queryCode = $_SESSION['last_order']['tracking_number'];
            }
        }

        if (empty($queryCode)) {
            return [
                'reply' => "I'd be glad to track your shipment! Please provide your **tracking number** (e.g. `TRK-FEDEX-882910`) or your **order number** (e.g. `ORD-2026-XXXXX`).",
                'products' => []
            ];
        }

        $info = track_order($queryCode);
        if ($info['status'] === 'found') {
            $reply = "📦 **Order Status:** {$info['delivery_status']}\n"
                   . "• **Order #:** `{$info['order_number']}`\n"
                   . "• **Tracking #:** `{$info['tracking_number']}`\n"
                   . "• **Courier:** {$info['courier']}\n"
                   . "• **Location:** {$info['current_location']}\n"
                   . "• **Est. Delivery:** {$info['estimated_delivery']}\n"
                   . "• **Latest Checkpoint:** {$info['latest_activity']}";
            return ['reply' => $reply, 'products' => []];
        } else {
            return ['reply' => $info['message'], 'products' => []];
        }
    }

    // 3. Payment / Transaction Intent (No cross-user leaks)
    if ($intent['intent'] === 'get_transactions') {
        $txCode = $intent['transaction_ref'] ?? '';
        if (empty($txCode)) {
            if (!empty($_SESSION['user_id'])) {
                $userOrders = OrdersService::getUserOrders((int)$_SESSION['user_id']);
                if (!empty($userOrders)) {
                    $firstOrder = reset($userOrders);
                    $txCode = $firstOrder['order_number'] ?? '';
                }
            } elseif (!empty($_SESSION['last_order']['order_number'])) {
                $txCode = $_SESSION['last_order']['order_number'];
            }
        }

        if (empty($txCode)) {
            return [
                'reply' => "I can check your payment details! Please provide your **transaction reference** (e.g. `TXN-XXXXXX`) or your **order number**.",
                'products' => []
            ];
        }

        $info = get_transactions($txCode);
        if ($info['status'] === 'found') {
            $reply = "💳 **Payment Transaction Details:**\n"
                   . "• **Reference:** `{$info['transaction_ref']}`\n"
                   . "• **Order #:** `{$info['order_number']}`\n"
                   . "• **Amount:** {$info['amount']}\n"
                   . "• **Payment Method:** {$info['payment_method']}\n"
                   . "• **Status:** {$info['payment_status']} (Paid)\n"
                   . "• **Date:** {$info['date']}";
            return ['reply' => $reply, 'products' => []];
        } else {
            return ['reply' => $info['message'], 'products' => []];
        }
    }

    // 4. Add to Cart Intent
    if ($intent['intent'] === 'add_to_cart') {
        $prodId = $intent['product_id'] ?? 101;
        $cartRes = add_to_cart($prodId, 1);
        return [
            'reply' => $cartRes['message'] . " You can proceed to checkout whenever you're ready!",
            'products' => array_values($discoveredProducts)
        ];
    }

    // 5. Store Policy FAQs
    if ($intent['intent'] === 'support_faq') {
        if (($intent['faq_topic'] ?? '') === 'paypal') {
            return [
                'reply' => "Nexora supports **PayPal Express Checkout**, Credit/Debit cards (Visa, MasterCard, Amex), UPI / Net Banking, and Apple Pay.\n\nTo pay with PayPal, simply add your items to the cart, proceed to checkout, and select **PayPal**. You will be securely directed to authorize payment with your PayPal account or linked cards.",
                'products' => []
            ];
        }
        return [
            'reply' => "Nexora offers a **30-day money-back guarantee** on all items! If you're not 100% satisfied with your purchase, you can initiate a return for a full refund within 30 days of delivery.",
            'products' => []
        ];
    }

    // 6. Conversational State Engine Gift Recommendation Routing
    if ($intent['intent'] === 'gift_advice' || $state['intent'] === 'gift_recommendation') {
        if (!$state['ready_to_recommend']) {
            $reply = ShoppingStateEngine::buildNaturalResponse($state, ['products' => []]);
            return [
                'reply' => $reply,
                'products' => []
            ];
        }

        $giftRes = ShoppingStateEngine::recommendGifts($state);
        if (!empty($giftRes['products'])) {
            foreach ($giftRes['products'] as $p) {
                $discoveredProducts[$p['id']] = $p;
            }
        }
        $reply = ShoppingStateEngine::buildNaturalResponse($state, $giftRes);
        return [
            'reply' => $reply,
            'products' => array_values($discoveredProducts)
        ];
    }

    // 7. Product Search & Comparison using Intent
    $searchRes = ChatbotIntent::searchByIntent($intent);
    if (!empty($searchRes['products'])) {
        foreach ($searchRes['products'] as $p) {
            $discoveredProducts[$p['id']] = $p;
        }
    }

    $reply = ChatbotIntent::buildNaturalResponse($intent, $searchRes);

    return [
        'reply' => $reply,
        'products' => array_values($discoveredProducts)
    ];
}

/**
 * Sanitize final response products to enforce category matching,
 * brand mismatch zero-cards, strict budget limits, and requested count limits.
 */
function sanitizeResponseProducts(array $response, array $state): array {
    $active = $state['active_request'] ?? $state;
    $reply = $response['reply'] ?? '';
    $products = $response['products'] ?? [];

    // 1. If brand mismatch detected
    if (!empty($active['brand_mismatch']) || !empty($active['mismatch']) ||
        preg_match('/\b(does not manufacture|does not make|doesn\'t make|doesn\'t manufacture)\b/i', $reply)) {
        $response['products'] = [];
        return $response;
    }

    // 2. If honest no-results explanation returned
    if ((preg_match('/\b(don\'t have|do not have|couldn\'t find|could not find|no .* in stock|in our catalog under|no matching)\b/i', $reply) &&
        !preg_match('/\b(here are the|matching gifts|available options|top \d+)\b/i', $reply)) ||
        preg_match('/we currently don\'t have/i', $reply)) {
        $response['products'] = [];
        return $response;
    }

    // 3. Category / Product Type Filtering
    if (!empty($active['product_type'])) {
        $pType = $active['product_type'];
        if ($pType === 'laptop') {
            $products = array_filter($products, fn($p) => ShoppingStateEngine::isActualLaptop($p));
        } elseif ($pType === 'headphones') {
            $products = array_filter($products, fn($p) => ShoppingStateEngine::isActualHeadphone($p));
            if (!empty($active['preferences']) && in_array('wireless', $active['preferences'], true)) {
                $products = array_filter($products, function($p) {
                    $nl = strtolower($p['name'] . ' ' . ($p['description'] ?? ''));
                    return str_contains($nl, 'wireless') || str_contains($nl, 'bluetooth') || str_contains($nl, 'tws') || str_contains($nl, 'anc');
                });
            }
        } elseif ($pType === 'shoes' && !empty($active['specific_product_type']) && $active['specific_product_type'] === 'dance shoes') {
            $products = [];
        }
    }

    // 4. Strict Budget Enforcement
    $maxUsd = $active['budget']['maximum_usd'] ?? null;
    if ($maxUsd !== null && $maxUsd > 0) {
        $products = array_filter($products, fn($p) => (float)$p['price'] <= ($maxUsd + 0.01));
    }

    // 5. Exclusions
    if (!empty($active['excluded_keywords'])) {
        foreach ($active['excluded_keywords'] as $exKw) {
            $products = array_filter($products, fn($p) => !str_contains(strtolower($p['name']), strtolower($exKw)));
        }
    }
    if (!empty($active['excluded_categories'])) {
        foreach ($active['excluded_categories'] as $exCat) {
            $products = array_filter($products, function($p) use ($exCat) {
                return (int)($p['category_id'] ?? 0) !== (int)$exCat;
            });
        }
    }

    // 6. Requested Count Limit
    $limit = $active['requested_count'] ?? 6;
    if ($limit > 0 && count($products) > $limit) {
        $products = array_slice($products, 0, $limit);
    }

    $response['products'] = array_values($products);

    // Save displayed products to state for reference resolution
    $state['last_displayed_products'] = $response['products'];
    if (isset($state['active_request'])) {
        $state['active_request']['last_displayed_products'] = $response['products'];
    }
    ShoppingStateEngine::saveState($state);

    return $response;
}

// Run LLM / Assistant pipeline
$response = callLLM($sanitizedMessages, $geminiTools);
$response = sanitizeResponseProducts($response, $shoppingState);

// Include current session cart count
$cartCount = 0;
if (!empty($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $qty) {
        $cartCount += $qty;
    }
}
$response['cart_count'] = $cartCount;

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
