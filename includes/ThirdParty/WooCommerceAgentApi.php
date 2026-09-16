<?php

declare(strict_types=1);

namespace ProjectFlash\Agent\ThirdParty;

use WP_Error;

/**
 * Lean WooCommerce adapter (v1). Self-contained + PFA-owned: talks directly to
 * WooCommerce's own public API (wc_get_orders / wc_get_order / wc_get_products
 * / $order->add_order_note), never through PFW's WooCommerceHelper — so it
 * works whether or not the Setyenv suite is installed. Only present when
 * WooCommerce is active (ThirdPartyPresence gates the tools).
 *
 * Scope (lean): READ orders & products, and ADD a note to an order. Nothing
 * destructive — no refunds / cancels / order creation in v1.
 */
final class WooCommerceAgentApi
{
    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wc_read(array $args)
    {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_orders')) {
            return new WP_Error('wc_absent', __('WooCommerce is not installed on this site.', 'wp-pfagent'), ['status' => 400]);
        }
        if (!current_user_can('edit_shop_orders') && !current_user_can('manage_woocommerce')) {
            return new WP_Error('wc_forbidden', __('You cannot read WooCommerce data.', 'wp-pfagent'), ['status' => 403]);
        }

        $kind = in_array(($args['kind'] ?? 'orders'), ['orders', 'products', 'customers', 'coupons'], true) ? (string) $args['kind'] : 'orders';
        $per_page = max(1, min(50, (int) ($args['per_page'] ?? 10)));
        $page = max(1, (int) ($args['page'] ?? 1));
        $id = isset($args['id']) && is_numeric($args['id']) ? (int) $args['id'] : 0;

        if ($kind === 'customers') {
            $users = get_users(['role' => 'customer', 'number' => $per_page, 'paged' => $page, 'fields' => ['ID', 'user_email', 'display_name', 'user_registered']]);
            $items = [];
            foreach ($users as $u) {
                $items[] = ['id' => (int) $u->ID, 'email' => (string) $u->user_email, 'name' => (string) $u->display_name, 'registered' => (string) $u->user_registered];
            }
            return ['kind' => 'customers', 'items' => $items];
        }

        if ($kind === 'coupons') {
            $posts = get_posts(['post_type' => 'shop_coupon', 'posts_per_page' => $per_page, 'paged' => $page, 'post_status' => 'publish']);
            $items = [];
            foreach ($posts as $p) {
                $c = new \WC_Coupon($p->ID);
                $items[] = ['id' => (int) $p->ID, 'code' => $c->get_code(), 'amount' => $c->get_amount(), 'discountType' => $c->get_discount_type(), 'usageCount' => $c->get_usage_count()];
            }
            return ['kind' => 'coupons', 'items' => $items];
        }

        if ($kind === 'products') {
            if ($id > 0) {
                $p = function_exists('wc_get_product') ? wc_get_product($id) : null;
                if (!$p) {
                    return new WP_Error('wc_not_found', __('That product does not exist.', 'wp-pfagent'), ['status' => 404]);
                }
                return ['kind' => 'product', 'item' => $this->product_summary($p)];
            }
            $products = wc_get_products([
                'limit' => $per_page,
                'page' => $page,
                'paginate' => false,
                's' => isset($args['search']) ? sanitize_text_field((string) $args['search']) : '',
            ]);
            return ['kind' => 'products', 'items' => array_map([$this, 'product_summary'], is_array($products) ? $products : [])];
        }

        // orders
        if ($id > 0) {
            $o = wc_get_order($id);
            if (!$o) {
                return new WP_Error('wc_not_found', __('That order does not exist.', 'wp-pfagent'), ['status' => 404]);
            }
            return ['kind' => 'order', 'item' => $this->order_summary($o, true)];
        }
        $orders = wc_get_orders([
            'limit' => $per_page,
            'paged' => $page,
            'orderby' => 'date',
            'order' => 'DESC',
            // A refund is its own object type, not an order. Without this the
            // store hands back WC_Order_Refund rows too, and those do not carry
            // the order interface: the listing FATALED on the first refund in
            // the shop (get_order_number() is undefined on a refund), so the
            // agent's read of a perfectly healthy store died with a 500.
            'type' => 'shop_order',
        ]);
        return ['kind' => 'orders', 'items' => array_map(fn($o) => $this->order_summary($o, false), is_array($orders) ? $orders : [])];
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wc_order_note(array $args)
    {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_order')) {
            return new WP_Error('wc_absent', __('WooCommerce is not installed on this site.', 'wp-pfagent'), ['status' => 400]);
        }
        if (!current_user_can('edit_shop_orders') && !current_user_can('manage_woocommerce')) {
            return new WP_Error('wc_forbidden', __('You cannot edit WooCommerce orders.', 'wp-pfagent'), ['status' => 403]);
        }
        $id = isset($args['order_id']) && is_numeric($args['order_id']) ? (int) $args['order_id'] : 0;
        $note = trim((string) ($args['note'] ?? ''));
        if ($id <= 0 || $note === '') {
            return new WP_Error('wc_invalid_args', __('An order id and a note are required.', 'wp-pfagent'), ['status' => 400]);
        }
        $order = wc_get_order($id);
        if (!$order) {
            return new WP_Error('wc_not_found', __('That order does not exist.', 'wp-pfagent'), ['status' => 404]);
        }
        $customer_note = (bool) ($args['customer_note'] ?? false);
        $note_id = $order->add_order_note(wp_kses_post($note), $customer_note ? 1 : 0, false);

        return ['added' => (bool) $note_id, 'orderId' => $id, 'noteId' => (int) $note_id, 'customerNote' => $customer_note];
    }

    /**
     * Change an order's status (processing, completed, on-hold, cancelled…).
     * Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wc_order_update(array $args)
    {
        $order = $this->order_for_write($args);
        if ($order instanceof WP_Error) {
            return $order;
        }
        $status = sanitize_key((string) ($args['status'] ?? ''));
        $valid = array_map(static fn($s) => str_replace('wc-', '', $s), array_keys(wc_get_order_statuses()));
        if ($status === '' || !in_array($status, $valid, true)) {
            return new WP_Error('wc_invalid_args', __('A valid order status is required.', 'wp-pfagent'), ['status' => 400]);
        }
        $order->update_status($status, isset($args['note']) ? sanitize_text_field((string) $args['note']) : '', true);
        return ['updated' => true, 'orderId' => $order->get_id(), 'status' => $order->get_status()];
    }

    /**
     * Cancel an order (status → cancelled, optional restock). Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wc_order_cancel(array $args)
    {
        $order = $this->order_for_write($args);
        if ($order instanceof WP_Error) {
            return $order;
        }
        $order->update_status('cancelled', isset($args['reason']) ? sanitize_text_field((string) $args['reason']) : '', true);
        return ['cancelled' => true, 'orderId' => $order->get_id(), 'status' => $order->get_status()];
    }

    /**
     * Create a draft/pending order, optionally with line items and a customer.
     * Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wc_order_create(array $args)
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        $order = wc_create_order(['status' => 'pending']);
        if ($order instanceof WP_Error) {
            return $order;
        }
        if (isset($args['customer_id'])) {
            $order->set_customer_id($this->int($args['customer_id']));
        }
        foreach ((is_array($args['items'] ?? null) ? $args['items'] : []) as $line) {
            if (!is_array($line) || !isset($line['product_id'])) {
                continue;
            }
            $product = wc_get_product($this->int($line['product_id']));
            if ($product) {
                $order->add_product($product, max(1, (int) ($line['quantity'] ?? 1)));
            }
        }
        $order->calculate_totals();
        $order->save();
        return ['created' => true, 'orderId' => $order->get_id(), 'status' => $order->get_status(), 'total' => $order->get_total()];
    }

    /**
     * Add or remove a product line on an order. Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wc_order_line(array $args)
    {
        $order = $this->order_for_write($args);
        if ($order instanceof WP_Error) {
            return $order;
        }
        $op = sanitize_key((string) ($args['op'] ?? 'add'));
        if ($op === 'remove') {
            $item_id = $this->int($args['item_id'] ?? 0);
            if ($item_id <= 0) {
                return new WP_Error('wc_invalid_args', __('An item_id is required to remove a line.', 'wp-pfagent'), ['status' => 400]);
            }
            $order->remove_item($item_id);
            $order->calculate_totals();
            $order->save();
            return ['removed' => true, 'orderId' => $order->get_id(), 'itemId' => $item_id];
        }
        $product = wc_get_product($this->int($args['product_id'] ?? 0));
        if (!$product) {
            return new WP_Error('wc_not_found', __('That product does not exist.', 'wp-pfagent'), ['status' => 404]);
        }
        $new_item = $order->add_product($product, max(1, (int) ($args['quantity'] ?? 1)));
        $order->calculate_totals();
        $order->save();
        return ['added' => true, 'orderId' => $order->get_id(), 'itemId' => (int) $new_item, 'total' => $order->get_total()];
    }

    /**
     * Apply a coupon code to an order. Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wc_apply_coupon(array $args)
    {
        $order = $this->order_for_write($args);
        if ($order instanceof WP_Error) {
            return $order;
        }
        $code = sanitize_text_field((string) ($args['code'] ?? ''));
        if ($code === '') {
            return new WP_Error('wc_invalid_args', __('A coupon code is required.', 'wp-pfagent'), ['status' => 400]);
        }
        $applied = $order->apply_coupon($code);
        if ($applied instanceof WP_Error) {
            return $applied;
        }
        $order->calculate_totals();
        $order->save();
        return ['applied' => true, 'orderId' => $order->get_id(), 'code' => $code, 'total' => $order->get_total()];
    }

    /**
     * Set a product's stock quantity (and derived stock status). Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wc_stock_set(array $args)
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        $product = wc_get_product($this->int($args['product_id'] ?? 0));
        if (!$product) {
            return new WP_Error('wc_not_found', __('That product does not exist.', 'wp-pfagent'), ['status' => 404]);
        }
        if (!array_key_exists('quantity', $args)) {
            return new WP_Error('wc_invalid_args', __('A stock quantity is required.', 'wp-pfagent'), ['status' => 400]);
        }
        $qty = (int) $args['quantity'];
        $product->set_manage_stock(true);
        $product->set_stock_quantity($qty);
        $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
        $product->save();
        return ['updated' => true, 'productId' => $product->get_id(), 'stockQty' => $product->get_stock_quantity(), 'stockStatus' => $product->get_stock_status()];
    }

    /**
     * Create or edit a simple product (name, price, sku, description, stock).
     * Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wc_product_upsert(array $args)
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        $id = $this->int($args['id'] ?? 0);
        $product = $id > 0 ? wc_get_product($id) : new \WC_Product_Simple();
        if (!$product) {
            return new WP_Error('wc_not_found', __('That product does not exist.', 'wp-pfagent'), ['status' => 404]);
        }
        if (array_key_exists('name', $args)) {
            $product->set_name(sanitize_text_field((string) $args['name']));
        }
        if (array_key_exists('regular_price', $args)) {
            $product->set_regular_price((string) $args['regular_price']);
        }
        if (array_key_exists('sku', $args)) {
            $product->set_sku(sanitize_text_field((string) $args['sku']));
        }
        if (array_key_exists('description', $args)) {
            $product->set_description(wp_kses_post((string) $args['description']));
        }
        if (array_key_exists('status', $args)) {
            $product->set_status(in_array($args['status'], ['publish', 'draft', 'pending'], true) ? (string) $args['status'] : 'draft');
        } elseif ($id === 0) {
            $product->set_status('draft');
        }
        $new_id = $product->save();
        return ['upserted' => true, 'productId' => (int) $new_id, 'created' => $id === 0, 'name' => $product->get_name()];
    }

    /**
     * Record a REFUND REQUEST for a human to process. Per our policy refunds
     * are NEVER automatic: this does NOT call WooCommerce's refund/payout API.
     * It logs the request as an order note and flags the order so a person
     * approves and issues the refund in WooCommerce. Side-effect.
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|WP_Error
     */
    public function wc_refund_request(array $args)
    {
        $order = $this->order_for_write($args);
        if ($order instanceof WP_Error) {
            return $order;
        }
        $amount = isset($args['amount']) && is_numeric($args['amount']) ? (float) $args['amount'] : null;
        $reason = sanitize_text_field((string) ($args['reason'] ?? ''));
        $line = sprintf(
            /* translators: 1: amount, 2: reason */
            __('Refund REQUESTED via the agent%1$s%2$s — NOT yet issued. A person must review and process this refund in WooCommerce.', 'wp-pfagent'),
            $amount !== null ? ' (' . wc_price($amount) . ')' : '',
            $reason !== '' ? ': ' . $reason : ''
        );
        $order->add_order_note($line, 0, false);
        $order->update_meta_data('_pfa_refund_requested', ['amount' => $amount, 'reason' => $reason, 'at' => gmdate('c')]);
        $order->save();
        return [
            'requested' => true,
            'automatic' => false,
            'orderId' => $order->get_id(),
            'message' => __('Refund request recorded on the order. It was NOT issued — a person must approve and process it in WooCommerce.', 'wp-pfagent'),
        ];
    }

    /**
     * Shared write guard: WooCommerce present + capability. Returns null when OK.
     */
    private function guard(): ?WP_Error
    {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_order')) {
            return new WP_Error('wc_absent', __('WooCommerce is not installed on this site.', 'wp-pfagent'), ['status' => 400]);
        }
        if (!current_user_can('edit_shop_orders') && !current_user_can('manage_woocommerce')) {
            return new WP_Error('wc_forbidden', __('You cannot manage WooCommerce.', 'wp-pfagent'), ['status' => 403]);
        }
        return null;
    }

    /** @return \WC_Order|WP_Error */
    private function order_for_write(array $args)
    {
        if (($g = $this->guard()) !== null) {
            return $g;
        }
        $id = $this->int($args['order_id'] ?? ($args['id'] ?? 0));
        $order = $id > 0 ? wc_get_order($id) : null;
        if (!$order) {
            return new WP_Error('wc_not_found', __('That order does not exist.', 'wp-pfagent'), ['status' => 404]);
        }
        return $order;
    }

    private function int($v): int
    {
        return is_numeric($v) ? (int) $v : 0;
    }

    /**
     * Summarise an order without assuming which order type it is.
     *
     * WooCommerce hands back several classes through the same reading paths
     * (WC_Order, WC_Order_Refund, and whatever a store's extensions register),
     * and they do NOT share the whole order interface. Calling a getter that
     * one of them lacks is a fatal error, i.e. a 500 for the agent instead of
     * an answer — so every accessor here is asked for, never assumed.
     *
     * @param mixed $o
     * @return array<string, mixed>
     */
    private function order_summary($o, bool $full): array
    {
        $created = is_callable([$o, 'get_date_created']) ? $o->get_date_created() : null;
        $data = [
            'id' => (int) $o->get_id(),
            'number' => is_callable([$o, 'get_order_number']) ? (string) $o->get_order_number() : (string) $o->get_id(),
            'status' => is_callable([$o, 'get_status']) ? (string) $o->get_status() : '',
            'total' => is_callable([$o, 'get_total']) ? (string) $o->get_total() : '',
            'currency' => is_callable([$o, 'get_currency']) ? (string) $o->get_currency() : '',
            'date' => $created ? $created->date('c') : '',
            'customer' => is_callable([$o, 'get_billing_first_name'])
                ? trim($o->get_billing_first_name() . ' ' . $o->get_billing_last_name())
                : '',
        ];
        if ($full) {
            $items = [];
            foreach (is_callable([$o, 'get_items']) ? $o->get_items() : [] as $it) {
                $items[] = ['name' => $it->get_name(), 'qty' => (int) $it->get_quantity(), 'total' => (string) $it->get_total()];
            }
            $data['items'] = $items;
            $data['email'] = is_callable([$o, 'get_billing_email']) ? (string) $o->get_billing_email() : '';
        }
        return $data;
    }

    /** @param mixed $p @return array<string, mixed> */
    private function product_summary($p): array
    {
        return [
            'id' => (int) $p->get_id(),
            'name' => (string) $p->get_name(),
            'sku' => (string) $p->get_sku(),
            'price' => (string) $p->get_price(),
            'stockStatus' => (string) $p->get_stock_status(),
            'stockQty' => $p->get_stock_quantity(),
            'type' => (string) $p->get_type(),
        ];
    }
}
