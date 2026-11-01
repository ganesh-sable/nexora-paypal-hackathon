-- ========================================================
-- Nexora PayPal Integration & Order Cancellation Migration
-- Adds PayPal Order ID, Capture ID, cancellation details,
-- and refund tracking to the orders and transactions tables.
-- ========================================================

DO $$
BEGIN
    -- 1. PayPal Order & Capture tracking
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'orders' AND column_name = 'paypal_order_id'
    ) THEN
        ALTER TABLE orders ADD COLUMN paypal_order_id VARCHAR(100) NULL;
        CREATE INDEX idx_orders_paypal_order_id ON orders(paypal_order_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'orders' AND column_name = 'paypal_capture_id'
    ) THEN
        ALTER TABLE orders ADD COLUMN paypal_capture_id VARCHAR(100) NULL;
        CREATE INDEX idx_orders_paypal_capture_id ON orders(paypal_capture_id);
    END IF;

    -- 2. Order Cancellation tracking
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'orders' AND column_name = 'cancelled_at'
    ) THEN
        ALTER TABLE orders ADD COLUMN cancelled_at TIMESTAMP WITH TIME ZONE NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'orders' AND column_name = 'cancel_reason'
    ) THEN
        ALTER TABLE orders ADD COLUMN cancel_reason TEXT NULL;
    END IF;

    -- 3. Refund tracking
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'orders' AND column_name = 'refund_id'
    ) THEN
        ALTER TABLE orders ADD COLUMN refund_id VARCHAR(100) NULL;
        CREATE INDEX idx_orders_refund_id ON orders(refund_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'orders' AND column_name = 'refund_status'
    ) THEN
        ALTER TABLE orders ADD COLUMN refund_status VARCHAR(50) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'orders' AND column_name = 'refunded_amount'
    ) THEN
        ALTER TABLE orders ADD COLUMN refunded_amount NUMERIC(10, 2) NULL;
    END IF;

    -- 4. State column for US/international addresses
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'orders' AND column_name = 'state'
    ) THEN
        ALTER TABLE orders ADD COLUMN state VARCHAR(100) NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns 
        WHERE table_name = 'customers' AND column_name = 'state'
    ) THEN
        ALTER TABLE customers ADD COLUMN state VARCHAR(100) NULL;
    END IF;
END $$;
