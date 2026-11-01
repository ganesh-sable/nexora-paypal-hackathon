<?php
$page_title = 'Shopping Cart';
require_once 'products.php';
require_once 'header.php';

$cart = $_SESSION['cart'] ?? [];
$subtotal = 0;
$cart_items = [];

foreach ($cart as $id => $qty) {
    $p = get_product_by_id((int)$id);
    if ($p) {
        $item = $p;
        $item['qty'] = $qty;
        $item['line_total'] = $item['price'] * $qty;
        $subtotal += $item['line_total'];
        $cart_items[] = $item;
    }
}
?>

<h1 class="page-title">Shopping Cart</h1>

<?php if (empty($cart_items)): ?>
    <div class="empty-cart">
        <div class="emoji">🛒</div>
        <h2>Your cart is empty</h2>
        <p>Looks like you haven’t added anything yet.</p>
        <a href="index.php" class="btn btn-primary">Browse Products</a>
    </div>
<?php else: ?>

    <table class="cart-table">
        <thead>
            <tr>
                <th>Product</th>
                <th>Price</th>
                <th>Quantity</th>
                <th>Total</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($cart_items as $item): ?>
                <tr>
                    <td>
                        <div class="cart-item-info">
                            <img src="<?php echo htmlspecialchars($item['image']); ?>" 
                                 alt="<?php echo htmlspecialchars($item['name']); ?>" 
                                 class="cart-item-photo"
                                 onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1523275335684-37898b6baf30?w=120&auto=format&fit=crop&q=80';">
                            <div>
                                <span class="badge-pill bg-light" style="font-size:0.75rem;"><?php echo $item['emoji']; ?> <?php echo htmlspecialchars($item['category'] ?? ''); ?></span>
                                <div style="margin-top:0.25rem;">
                                    <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                </div>
                            </div>
                        </div>
                    </td>
                    <td>$<?php echo number_format($item['price'], 2); ?></td>
                    <td>
                        <div class="qty-controls">
                            <form action="cart_action.php" method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="decrease">
                                <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                <button type="submit" class="qty-btn">−</button>
                            </form>
                            <span><?php echo $item['qty']; ?></span>
                            <form action="cart_action.php" method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="increase">
                                <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                                <button type="submit" class="qty-btn">+</button>
                            </form>
                        </div>
                    </td>
                    <td><strong>$<?php echo number_format($item['line_total'], 2); ?></strong></td>
                    <td>
                        <form action="cart_action.php" method="POST">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="product_id" value="<?php echo $item['id']; ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="cart-summary">
        <h3>Order Summary</h3>
        <div class="summary-row">
            <span>Subtotal</span>
            <span>$<?php echo number_format($subtotal, 2); ?></span>
        </div>
        <div class="summary-row">
            <span>Shipping</span>
            <span>Free</span>
        </div>
        <div class="summary-row total">
            <span>Total</span>
            <span>$<?php echo number_format($subtotal, 2); ?></span>
        </div>

        <?php if (!Auth::isLoggedIn()): ?>
            <div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:0.85rem;margin:1rem 0;font-size:0.88rem;color:#166534;">
                🔒 <strong>Guest Shopper:</strong> You will be prompted to sign in or create an account to finalize your order. Your items are securely saved.
            </div>
        <?php endif; ?>

        <div class="actions" style="margin-top:1.25rem;">
            <a href="checkout.php" class="btn btn-success" style="flex:1;">Proceed to Checkout →</a>
        </div>
        <div class="actions">
            <a href="index.php" class="btn btn-outline" style="flex:1;">Continue Shopping</a>
            <form action="cart_action.php" method="POST" style="flex:1;">
                <input type="hidden" name="action" value="clear">
                <button type="submit" class="btn btn-outline" style="width:100%;">Clear Cart</button>
            </form>
        </div>
    </div>

<?php endif; ?>

<?php require_once 'footer.php'; ?>
