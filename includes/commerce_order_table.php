<div class="table-responsive">
    <table class="table commerce-table table-hover">
        <thead>
            <tr>
                <th>Order</th>
                <th>Customer</th>
                <th>Channel</th>
                <th>Payment</th>
                <th>Items</th>
                <th>Total</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($orderRows as $order): ?>
                <?php
                $statusClass = $order['status'] === 'cancelled'
                    ? 'status-risk'
                    : (in_array($order['status'], ['paid', 'processing', 'shipped'], true) ? 'status-watch' : 'status-good');
                ?>
                <tr>
                    <td><strong><?= h($order['order_number']); ?></strong><br><span class="text-muted"><?= h(date('d M Y', strtotime($order['order_date']))); ?></span></td>
                    <td><?= h($order['full_name']); ?></td>
                    <td><?= h($order['channel']); ?></td>
                    <td><?= h($order['payment_method']); ?></td>
                    <td><?= number_format($order['item_count']); ?></td>
                    <td class="fw-bold"><?= rupiah($order['total_amount']); ?></td>
                    <td><span class="status-pill <?= h($statusClass); ?>"><?= h(ucfirst($order['status'])); ?></span></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
