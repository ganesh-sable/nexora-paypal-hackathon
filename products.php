<?php
/**
 * Product & Category Repository
 * Supports PostgreSQL with automatic fallback to high-quality local catalog
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/products_data.php';

// Global arrays populated dynamically
$categories = [];
$products   = [];

/**
 * Fetch all categories
 */
function get_all_categories(): array {
    global $categories_data;

    if (DB::isConnected()) {
        try {
            $rows = DB::fetchAll("SELECT * FROM categories ORDER BY id ASC");
            if (!empty($rows)) {
                $cats = [];
                foreach ($rows as $row) {
                    $cats[$row['id']] = $row;
                }
                return $cats;
            }
        } catch (Exception $e) {
            // fallback to array
        }
    }

    return $categories_data;
}

/**
 * Fetch products with optional filtering & sorting
 */
function get_all_products(?int $categoryId = null, ?string $search = null, ?string $sort = null): array {
    global $catalog_products;

    if (DB::isConnected()) {
        try {
            $sql = "SELECT p.*, c.name as category, c.emoji as category_emoji 
                    FROM products p 
                    JOIN categories c ON p.category_id = c.id 
                    WHERE 1=1";
            $params = [];

            if ($categoryId && $categoryId > 0) {
                $sql .= " AND p.category_id = ?";
                $params[] = $categoryId;
            }

            if (!empty($search)) {
                $sql .= " AND (p.name ILIKE ? OR p.description ILIKE ?)";
                $keyword = '%' . trim($search) . '%';
                $params[] = $keyword;
                $params[] = $keyword;
            }

            switch ($sort) {
                case 'price_asc':
                    $sql .= " ORDER BY p.price ASC";
                    break;
                case 'price_desc':
                    $sql .= " ORDER BY p.price DESC";
                    break;
                case 'rating':
                    $sql .= " ORDER BY p.rating DESC, p.reviews_count DESC";
                    break;
                case 'name':
                    $sql .= " ORDER BY p.name ASC";
                    break;
                default:
                    $sql .= " ORDER BY p.category_id ASC, p.id ASC";
            }

            $rows = DB::fetchAll($sql, $params);
            if (!empty($rows)) {
                $prods = [];
                foreach ($rows as $row) {
                    $row['price'] = (float)$row['price'];
                    $row['rating'] = (float)$row['rating'];
                    $row['class'] = 'product-' . $row['category_id'];
                    $row['image'] = $row['image_url'] ?? $row['image'] ?? 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600&auto=format&fit=crop&q=80';
                    $prods[$row['id']] = $row;
                }
                return $prods;
            }
        } catch (Exception $e) {
            // fallback to array
        }
    }

    // Fallback: Filter from memory catalog
    $result = $catalog_products;

    if ($categoryId && $categoryId > 0) {
        $result = array_filter($result, fn($p) => $p['category_id'] === $categoryId);
    }

    if (!empty($search)) {
        $q = strtolower(trim($search));
        $result = array_filter($result, function($p) use ($q) {
            return str_contains(strtolower($p['name']), $q) || str_contains(strtolower($p['description']), $q);
        });
    }

    // Sort
    if ($sort === 'price_asc') {
        uasort($result, fn($a, $b) => $a['price'] <=> $b['price']);
    } elseif ($sort === 'price_desc') {
        uasort($result, fn($a, $b) => $b['price'] <=> $a['price']);
    } elseif ($sort === 'rating') {
        uasort($result, fn($a, $b) => $b['rating'] <=> $a['rating']);
    } elseif ($sort === 'name') {
        uasort($result, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    }

    return $result;
}

/**
 * Get single product by ID
 */
function get_product_by_id(int $id): ?array {
    global $catalog_products;

    if (DB::isConnected()) {
        try {
            $row = DB::fetchOne("SELECT p.*, c.name as category, c.emoji as category_emoji 
                                 FROM products p 
                                 JOIN categories c ON p.category_id = c.id 
                                 WHERE p.id = ?", [$id]);
            if ($row) {
                $row['price'] = (float)$row['price'];
                $row['rating'] = (float)$row['rating'];
                $row['class'] = 'product-' . $row['category_id'];
                $row['image'] = $row['image_url'] ?? $row['image'] ?? 'https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600&auto=format&fit=crop&q=80';
                return $row;
            }
        } catch (Exception $e) {
            // fallback
        }
    }

    return $catalog_products[$id] ?? null;
}

// Populate default arrays for backward compatibility
$categories = get_all_categories();
$products   = get_all_products();
