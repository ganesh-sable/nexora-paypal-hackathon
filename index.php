<?php
$page_title = 'Products Catalog';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/products.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/header.php';

$selectedCategory = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$search = trim($_GET['search'] ?? '');
$sort = trim($_GET['sort'] ?? '');

$allCategories = get_all_categories();
$filteredProducts = get_all_products($selectedCategory, $search, $sort);
$activeCategoryInfo = $selectedCategory > 0 ? ($allCategories[$selectedCategory] ?? null) : null;
$dbStatus = DB::getStatus();
?>

<!-- Store Hero / Banner -->
<div class="store-hero">
    <div class="hero-inner">
        <h1 class="hero-title">Discover Our Premium Catalog</h1>
        <p class="hero-subtitle">Browse products across electronics, fashion, computers, footwear, sports, beauty, and more.</p>

        <!-- Search & Sort Controls -->
        <form action="index.php" method="GET" class="catalog-filter-bar">
            <?php if ($selectedCategory > 0): ?>
                <input type="hidden" name="cat" value="<?php echo $selectedCategory; ?>">
            <?php endif; ?>

            <div class="search-input-wrapper">
                <span class="search-icon">🔍</span>
                <input type="text" name="search" placeholder="Search across 240+ products, specs, brands..." value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <div class="sort-select-wrapper">
                <select name="sort" onchange="this.form.submit()">
                    <option value="" <?php echo $sort === '' ? 'selected' : ''; ?>>Featured / Default</option>
                    <option value="price_asc" <?php echo $sort === 'price_asc' ? 'selected' : ''; ?>>Price: Low to High</option>
                    <option value="price_desc" <?php echo $sort === 'price_desc' ? 'selected' : ''; ?>>Price: High to Low</option>
                    <option value="rating" <?php echo $sort === 'rating' ? 'selected' : ''; ?>>Highest Customer Rating</option>
                    <option value="name" <?php echo $sort === 'name' ? 'selected' : ''; ?>>Name: A to Z</option>
                </select>
            </div>

            <button type="submit" class="btn btn-primary">Filter</button>
            <?php if (!empty($search) || !empty($sort) || $selectedCategory > 0): ?>
                <a href="index.php" class="btn btn-outline" style="text-decoration:none;">Reset</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- 20 Category Filter Bar -->
<div class="category-nav-section">
    <div class="category-scroll-container">
        <a href="index.php<?php echo $search ? '?search=' . urlencode($search) : ''; ?>" class="cat-pill <?php echo $selectedCategory === 0 ? 'active' : ''; ?>">
            <span class="cat-pill-icon">✨</span>
            <span class="cat-pill-name">All Products</span>
            <span class="cat-pill-count">240</span>
        </a>
        <?php foreach ($allCategories as $c): ?>
            <?php $isActive = ($selectedCategory === (int)$c['id']); ?>
            <a href="index.php?cat=<?php echo $c['id']; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $sort ? '&sort=' . urlencode($sort) : ''; ?>" class="cat-pill <?php echo $isActive ? 'active' : ''; ?>">
                <span class="cat-pill-icon"><?php echo $c['emoji']; ?></span>
                <span class="cat-pill-name"><?php echo htmlspecialchars($c['name']); ?></span>
                <span class="cat-pill-count">12</span>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Category Banner or Results Header -->
<div class="catalog-results-header">
    <div>
        <?php if ($activeCategoryInfo): ?>
            <h2><?php echo $activeCategoryInfo['emoji']; ?> <?php echo htmlspecialchars($activeCategoryInfo['name']); ?></h2>
            <p class="category-desc"><?php echo htmlspecialchars($activeCategoryInfo['description'] ?? $activeCategoryInfo['desc'] ?? ''); ?></p>
        <?php elseif (!empty($search)): ?>
            <h2>Search Results for "<?php echo htmlspecialchars($search); ?>"</h2>
            <p class="category-desc">Showing products matching your search term.</p>
        <?php else: ?>
            <h2>All Catalog Products</h2>
            <p class="category-desc">Showing <?php echo count($filteredProducts); ?> available items across all 20 categories.</p>
        <?php endif; ?>
    </div>
    <div class="results-count-pill">
        <strong><?php echo count($filteredProducts); ?></strong> products found
    </div>
</div>

<!-- Products Grid -->
<?php if (empty($filteredProducts)): ?>
    <div class="empty-products-box">
        <div class="empty-icon">🔍</div>
        <h3>No products found</h3>
        <p>Try clearing your search keyword or switching category filters.</p>
        <a href="index.php" class="btn btn-primary" style="margin-top:1rem;">View All Products</a>
    </div>
<?php else: ?>
    <div class="products-grid">
        <?php foreach ($filteredProducts as $product): ?>
            <div class="product-card">
                <div class="product-image-container">
                    <img src="<?php echo htmlspecialchars($product['image']); ?>" 
                         alt="<?php echo htmlspecialchars($product['name']); ?>" 
                         class="product-real-img" 
                         loading="lazy" 
                         onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=600&auto=format&fit=crop&q=80';">
                    <span class="category-badge-chip">
                        <?php echo $product['emoji']; ?> <?php echo htmlspecialchars($product['category'] ?? 'General'); ?>
                    </span>
                    <?php if (!empty($product['badge'])): ?>
                        <span class="product-badge"><?php echo htmlspecialchars($product['badge']); ?></span>
                    <?php endif; ?>
                </div>
                <div class="product-body">
                    <div class="product-rating">
                        <span class="rating-stars">★ <?php echo number_format($product['rating'] ?? 4.5, 1); ?></span>
                        <span class="rating-count">(<?php echo $product['reviews'] ?? $product['reviews_count'] ?? 120; ?> reviews)</span>
                    </div>

                    <h3 class="product-name" title="<?php echo htmlspecialchars($product['name']); ?>">
                        <?php echo htmlspecialchars($product['name']); ?>
                    </h3>

                    <p class="product-desc">
                        <?php echo htmlspecialchars($product['description']); ?>
                    </p>

                    <div class="stock-status">
                        <span class="stock-indicator in-stock"></span>
                        <span>In Stock (<?php echo $product['stock'] ?? 50; ?> units available)</span>
                    </div>

                    <div class="product-footer">
                        <div class="product-price">
                            $<?php echo number_format($product['price'], 2); ?>
                        </div>
                        <form action="cart_action.php" method="POST">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>">
                            <button type="submit" class="btn btn-primary btn-add-cart">
                                🛒 Add to Cart
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
