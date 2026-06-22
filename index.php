<?php
require_once(__DIR__ . "/config/db.php");

$conn = koneksiDB();

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function validDateParam($value) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) {
        return '';
    }

    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function fetchRows(PDO $conn, $sql, array $params = []) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function scalarValue(PDO $conn, $sql, array $params = [], $default = 0) {
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? $default : $value;
    } catch (PDOException $e) {
        return $default;
    }
}

function tableExists(PDO $conn, $table) {
    $exists = scalarValue(
        $conn,
        "SELECT EXISTS (
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = 'public' AND table_name = :table
        )",
        [':table' => $table],
        false
    );

    return $exists === true || $exists === 1 || $exists === '1' || $exists === 't';
}

function rupiah($value, $decimals = 0) {
    return 'Rp ' . number_format((float)$value, $decimals, ',', '.');
}

function percent($value, $decimals = 1) {
    return number_format((float)$value, $decimals, ',', '.') . '%';
}

$allowedPages = ['overview', 'insights', 'catalog', 'orders', 'customers', 'marketing', 'films', 'stores', 'rental'];
$page = isset($_GET['page']) && in_array($_GET['page'], $allowedPages, true) ? $_GET['page'] : 'overview';
$dateFrom = validDateParam($_GET['date_from'] ?? '');
$dateTo = validDateParam($_GET['date_to'] ?? '');
$statusFilter = isset($_GET['status']) && preg_match('/^[a-z_]+$/', (string)$_GET['status']) ? $_GET['status'] : '';
$categoryFilter = isset($_GET['category']) && preg_match('/^[A-Za-z0-9 &-]+$/', (string)$_GET['category']) ? $_GET['category'] : '';

$commerceReady = tableExists($conn, 'commerce_orders')
    && tableExists($conn, 'commerce_products')
    && tableExists($conn, 'commerce_order_items')
    && tableExists($conn, 'commerce_customers');

$stagingCustomerTable = tableExists($conn, 'staging_customers') ? 'staging_customers' : (tableExists($conn, 'staging_customer') ? 'staging_customer' : '');
$customerSourceLabel = $stagingCustomerTable !== '' ? $stagingCustomerTable : 'commerce_customers';
$customerSourceSql = $stagingCustomerTable !== ''
    ? "
        SELECT customer_id,
               COALESCE(NULLIF(INITCAP(TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, ''))), ''), email, 'Customer #' || customer_id::text) AS full_name,
               COALESCE(email, '-') AS email,
               COALESCE(phone, '-') AS phone,
               COALESCE(city, '-') AS city,
               COALESCE(country, '-') AS country,
               COALESCE(active, false) AS is_active,
               create_date::date AS registered_at
        FROM public." . $stagingCustomerTable . "
    "
    : "
        SELECT customer_id,
               full_name,
               email,
               COALESCE(phone, '-') AS phone,
               city,
               '-' AS country,
               true AS is_active,
               registered_at
        FROM public.commerce_customers
    ";

$orderFilters = [];
$orderParams = [];
if ($dateFrom !== '') {
    $orderFilters[] = "o.order_date::date >= :date_from";
    $orderParams[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $orderFilters[] = "o.order_date::date <= :date_to";
    $orderParams[':date_to'] = $dateTo;
}
if ($statusFilter !== '') {
    $orderFilters[] = "o.status = :status";
    $orderParams[':status'] = $statusFilter;
}
$orderWhere = $orderFilters ? ' WHERE ' . implode(' AND ', $orderFilters) : '';

$productFilters = [];
$productParams = [];
if ($categoryFilter !== '') {
    $productFilters[] = "p.category = :category";
    $productParams[':category'] = $categoryFilter;
}
$productWhere = $productFilters ? ' WHERE ' . implode(' AND ', $productFilters) : '';

$statusOptions = $commerceReady ? fetchRows($conn, "SELECT DISTINCT status FROM public.commerce_orders ORDER BY status") : [];
$categoryOptions = $commerceReady ? fetchRows($conn, "SELECT DISTINCT category FROM public.commerce_products ORDER BY category") : [];

$gmv = $commerceReady ? scalarValue($conn, "SELECT COALESCE(SUM(o.total_amount), 0) FROM public.commerce_orders o" . $orderWhere, $orderParams) : 0;
$paidRevenue = $commerceReady ? scalarValue($conn, "SELECT COALESCE(SUM(o.total_amount), 0) FROM public.commerce_orders o" . ($orderWhere ? $orderWhere . " AND " : " WHERE ") . "o.status <> 'cancelled'", $orderParams) : 0;
$totalOrders = $commerceReady ? scalarValue($conn, "SELECT COUNT(*) FROM public.commerce_orders o" . $orderWhere, $orderParams) : 0;
$activeCustomers = $commerceReady ? scalarValue($conn, "SELECT COUNT(DISTINCT o.customer_id) FROM public.commerce_orders o" . $orderWhere, $orderParams) : 0;
$avgOrder = $totalOrders > 0 ? $gmv / $totalOrders : 0;
$cancelledOrders = $commerceReady ? scalarValue($conn, "SELECT COUNT(*) FROM public.commerce_orders o" . ($orderWhere ? $orderWhere . " AND " : " WHERE ") . "o.status = 'cancelled'", $orderParams) : 0;
$cancelRate = $totalOrders > 0 ? ($cancelledOrders / $totalOrders) * 100 : 0;
$lowStock = $commerceReady ? scalarValue($conn, "SELECT COUNT(*) FROM public.commerce_products WHERE stock_qty <= reorder_level AND is_active = true") : 0;
$activeProducts = $commerceReady ? scalarValue($conn, "SELECT COUNT(*) FROM public.commerce_products WHERE is_active = true") : 0;
$lowStockRate = $activeProducts > 0 ? ($lowStock / $activeProducts) * 100 : 0;

$pagilaRevenue = scalarValue($conn, "SELECT COALESCE(SUM(amount), 0) FROM public.fact_sales", [], 0);
$pagilaRentals = scalarValue($conn, "SELECT COALESCE(SUM(payment_count), 0) FROM public.fact_sales", [], 0);
$pagilaFilmCount = tableExists($conn, 'dim_film') ? scalarValue($conn, "SELECT COUNT(*) FROM public.dim_film", [], 0) : 0;

$filmBiRows = tableExists($conn, 'fact_film_performance') && tableExists($conn, 'dim_film') ? fetchRows($conn, "
    SELECT f.title,
           fp.film_key,
           COALESCE(fp.inventory_count, 0) AS inventory_count,
           COALESCE(fp.rented_copies, 0) AS rented_copies,
           COALESCE(fp.utilization_rate, 0) AS utilization_rate,
           COALESCE(fp.rental_revenue, 0) AS rental_revenue,
           COALESCE(fp.roi_percent, 0) AS roi_percent
    FROM public.fact_film_performance fp
    INNER JOIN public.dim_film f ON f.film_key = fp.film_key
    ORDER BY fp.rental_revenue DESC
    LIMIT 12
") : [];

$storeBiRows = tableExists($conn, 'fact_store_performance') ? fetchRows($conn, "
    SELECT store_key,
           COALESCE(SUM(total_revenue), 0) AS total_revenue,
           COALESCE(SUM(total_transactions), 0) AS total_transactions,
           COALESCE(SUM(unique_customers), 0) AS unique_customers,
           COALESCE(SUM(net_profit), 0) AS net_profit,
           COALESCE(AVG(profit_margin_percent), 0) AS profit_margin_percent,
           COALESCE(AVG(customer_satisfaction_score), 0) AS customer_satisfaction_score,
           COALESCE(SUM(low_stock_alerts), 0) AS low_stock_alerts
    FROM public.fact_store_performance
    GROUP BY store_key
    ORDER BY total_revenue DESC
") : [];

$storeTotalRevenue = array_sum(array_map(fn($row) => (float)$row['total_revenue'], $storeBiRows));
$storeTotalProfit = array_sum(array_map(fn($row) => (float)$row['net_profit'], $storeBiRows));
$storeTotalTransactions = array_sum(array_map(fn($row) => (int)$row['total_transactions'], $storeBiRows));
$storeAvgMargin = count($storeBiRows) > 0 ? array_sum(array_map(fn($row) => (float)$row['profit_margin_percent'], $storeBiRows)) / count($storeBiRows) : 0;

$salesTrend = $commerceReady ? fetchRows($conn, "
    SELECT TO_CHAR(o.order_date, 'YYYY-MM') AS period_label,
           COALESCE(SUM(o.total_amount), 0) AS revenue,
           COUNT(*) AS orders
    FROM public.commerce_orders o
    " . $orderWhere . "
    GROUP BY period_label
    ORDER BY period_label
", $orderParams) : [];

$categoryRows = $commerceReady ? fetchRows($conn, "
    SELECT p.category,
           COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS revenue,
           COALESCE(SUM(oi.quantity), 0) AS units
    FROM public.commerce_order_items oi
    INNER JOIN public.commerce_orders o ON o.order_id = oi.order_id
    INNER JOIN public.commerce_products p ON p.product_id = oi.product_id
    " . $orderWhere . "
    GROUP BY p.category
    ORDER BY revenue DESC
", $orderParams) : [];

$productRows = $commerceReady ? fetchRows($conn, "
    SELECT p.product_id,
           p.product_name,
           p.category,
           p.format_type,
           p.price,
           p.stock_qty,
           p.reorder_level,
           COALESCE(SUM(oi.quantity), 0) AS units_sold,
           COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS revenue
    FROM public.commerce_products p
    LEFT JOIN public.commerce_order_items oi ON oi.product_id = p.product_id
    LEFT JOIN public.commerce_orders o ON o.order_id = oi.order_id
    " . $productWhere . "
    GROUP BY p.product_id, p.product_name, p.category, p.format_type, p.price, p.stock_qty, p.reorder_level
    ORDER BY revenue DESC, p.product_name ASC
    LIMIT 14
", $productParams) : [];

$orderRows = $commerceReady ? fetchRows($conn, "
    SELECT o.order_number,
           o.order_date,
           o.status,
           o.channel,
           o.payment_method,
           o.total_amount,
           c.full_name,
           COUNT(oi.order_item_id) AS item_count
    FROM public.commerce_orders o
    INNER JOIN (" . $customerSourceSql . ") c ON c.customer_id = o.customer_id
    LEFT JOIN public.commerce_order_items oi ON oi.order_id = o.order_id
    " . $orderWhere . "
    GROUP BY o.order_id, c.full_name
    ORDER BY o.order_date DESC
    LIMIT 12
", $orderParams) : [];

$customerRows = $commerceReady ? fetchRows($conn, "
    SELECT c.full_name,
           c.email,
           c.phone,
           c.city,
           c.country,
           c.is_active,
           CASE
               WHEN c.is_active = false THEN 'At Risk'
               WHEN COALESCE(SUM(o.total_amount), 0) >= 500000 THEN 'VIP'
               WHEN COUNT(o.order_id) > 0 THEN 'Loyal'
               ELSE 'New'
           END AS segment,
           COUNT(o.order_id) AS orders_count,
           COALESCE(SUM(o.total_amount), 0) AS lifetime_value,
           MAX(o.order_date)::date AS last_order,
           c.registered_at
    FROM (" . $customerSourceSql . ") c
    LEFT JOIN public.commerce_orders o ON o.customer_id = c.customer_id
    GROUP BY c.customer_id, c.full_name, c.email, c.phone, c.city, c.country, c.is_active, c.registered_at
    ORDER BY lifetime_value DESC, c.customer_id ASC
    LIMIT 12
") : [];

$campaignRows = tableExists($conn, 'commerce_campaigns') ? fetchRows($conn, "
    SELECT campaign_name,
           channel,
           spend,
           revenue_attributed,
           conversion_rate,
           return_rate,
           CASE WHEN spend > 0 THEN revenue_attributed / spend ELSE 0 END AS roas
    FROM public.commerce_campaigns
    ORDER BY campaign_date DESC
    LIMIT 10
") : [];

$campaignSpend = 0;
$campaignRevenue = 0;
$campaignConversionTotal = 0;
foreach ($campaignRows as $campaign) {
    $campaignSpend += (float)$campaign['spend'];
    $campaignRevenue += (float)$campaign['revenue_attributed'];
    $campaignConversionTotal += (float)$campaign['conversion_rate'];
}
$averageRoas = $campaignSpend > 0 ? $campaignRevenue / $campaignSpend : 0;
$averageConversion = count($campaignRows) > 0 ? $campaignConversionTotal / count($campaignRows) : 0;
$commerceShare = $pagilaRevenue > 0 ? ($paidRevenue / $pagilaRevenue) * 100 : 0;
$aovTarget = 180000;
$cancelRateTarget = 8;
$lowStockTarget = 20;
$roasTarget = 4;
$conversionTarget = 4;

$benchmarkRows = [
    [
        'label' => 'AOV Commerce',
        'actual' => rupiah($avgOrder),
        'target' => rupiah($aovTarget),
        'status' => $avgOrder >= $aovTarget ? 'Di atas target' : 'Perlu bundle upsell',
        'class' => $avgOrder >= $aovTarget ? 'status-good' : 'status-watch',
        'delta' => $avgOrder >= $aovTarget ? '+' . rupiah($avgOrder - $aovTarget) : '-' . rupiah($aovTarget - $avgOrder),
    ],
    [
        'label' => 'Cancel Rate',
        'actual' => percent($cancelRate),
        'target' => '< ' . percent($cancelRateTarget),
        'status' => $cancelRate <= $cancelRateTarget ? 'Sehat' : 'Perlu follow-up checkout',
        'class' => $cancelRate <= $cancelRateTarget ? 'status-good' : 'status-risk',
        'delta' => number_format($cancelRate - $cancelRateTarget, 1, ',', '.') . ' poin',
    ],
    [
        'label' => 'Low Stock Ratio',
        'actual' => percent($lowStockRate),
        'target' => '< ' . percent($lowStockTarget),
        'status' => $lowStockRate <= $lowStockTarget ? 'Stok aman' : 'Restock prioritas',
        'class' => $lowStockRate <= $lowStockTarget ? 'status-good' : 'status-risk',
        'delta' => number_format($lowStockRate - $lowStockTarget, 1, ',', '.') . ' poin',
    ],
    [
        'label' => 'Marketing ROAS',
        'actual' => number_format($averageRoas, 2, ',', '.') . 'x',
        'target' => number_format($roasTarget, 2, ',', '.') . 'x',
        'status' => $averageRoas >= $roasTarget ? 'Efisien' : 'Optimasi campaign',
        'class' => $averageRoas >= $roasTarget ? 'status-good' : 'status-watch',
        'delta' => number_format($averageRoas - $roasTarget, 2, ',', '.') . 'x',
    ],
];

$benchmarkChartLabels = ['AOV', 'Cancel Rate', 'Low Stock', 'ROAS'];
$benchmarkActual = [
    $aovTarget > 0 ? round(($avgOrder / $aovTarget) * 100, 1) : 0,
    $cancelRateTarget > 0 ? round(($cancelRate / $cancelRateTarget) * 100, 1) : 0,
    $lowStockTarget > 0 ? round(($lowStockRate / $lowStockTarget) * 100, 1) : 0,
    $roasTarget > 0 ? round(($averageRoas / $roasTarget) * 100, 1) : 0,
];
$benchmarkTarget = [100, 100, 100, 100];
$timeCompareLabels = ['OLTP transaksi', 'ETL cepat', 'ETL harian', 'OLAP agregasi'];
$timeCompareValues = [1, 300, 86400, 5];

$trendLabels = array_column($salesTrend, 'period_label') ?: ['Belum ada data'];
$trendRevenue = array_map('floatval', array_column($salesTrend, 'revenue') ?: [0]);
$trendOrders = array_map('intval', array_column($salesTrend, 'orders') ?: [0]);
$categoryLabels = array_column($categoryRows, 'category') ?: ['Belum ada data'];
$categoryRevenue = array_map('floatval', array_column($categoryRows, 'revenue') ?: [0]);
$productLabels = array_column($productRows, 'product_name') ?: ['Belum ada data'];
$productRevenue = array_map('floatval', array_column($productRows, 'revenue') ?: [0]);
$filmBiLabels = array_column($filmBiRows, 'title') ?: ['Belum ada data'];
$filmBiRevenue = array_map('floatval', array_column($filmBiRows, 'rental_revenue') ?: [0]);
$storeBiLabels = array_map(fn($row) => 'Toko #' . $row['store_key'], $storeBiRows) ?: ['Belum ada data'];
$storeBiRevenue = array_map('floatval', array_column($storeBiRows, 'total_revenue') ?: [0]);

$bestProduct = $productRows[0] ?? null;
$pageTitles = [
    'overview' => 'Commerce Command Center',
    'insights' => 'Business Insights',
    'catalog' => 'Katalog Produk Rental Film',
    'orders' => 'Order Management',
    'customers' => 'Customer Commerce',
    'marketing' => 'Marketing Performance',
    'films' => 'Film Rental Analytics',
    'stores' => 'Store Operations',
    'rental' => 'Pagila Rental BI',
];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagila Commerce</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --ink: #172026;
            --muted: #65717c;
            --line: #d9e1e8;
            --surface: #ffffff;
            --soft: #f4f7f9;
            --dark: #101719;
            --brand: #0f766e;
            --blue: #2563eb;
            --amber: #b7791f;
            --red: #be123c;
        }
        * { box-sizing: border-box; }
        body {
            min-height: 100vh;
            margin: 0;
            background: var(--soft);
            color: var(--ink);
            font-family: "Segoe UI", Tahoma, sans-serif;
            overflow-x: hidden;
        }
        .app-shell { display: flex; min-height: 100vh; width: 100%; }
        .sidebar {
            width: 272px;
            flex: 0 0 272px;
            background: var(--dark);
            color: #edf5f2;
            display: flex;
            flex-direction: column;
        }
        .brand {
            padding: 22px 20px;
            border-bottom: 1px solid rgba(255,255,255,.09);
        }
        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 8px;
            background: #10b981;
            color: #06201a;
            display: grid;
            place-items: center;
            font-size: 1.15rem;
        }
        .nav-link-commerce {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #a8b5b1;
            text-decoration: none;
            padding: 13px 20px;
            font-size: .92rem;
            font-weight: 700;
            border-left: 4px solid transparent;
        }
        .nav-link-commerce:hover,
        .nav-link-commerce.active {
            color: #fff;
            background: rgba(255,255,255,.065);
            border-left-color: #10b981;
        }
        .main {
            flex: 1;
            height: 100vh;
            overflow-y: auto;
            padding: 24px;
        }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 16px;
        }
        .title-block h1 {
            font-size: 1.48rem;
            line-height: 1.2;
            font-weight: 900;
            margin: 0;
            letter-spacing: 0;
        }
        .title-block p {
            margin: 5px 0 0;
            color: var(--muted);
            font-size: .9rem;
        }
        .filter-bar,
        .panel,
        .metric-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 8px;
            box-shadow: 0 10px 24px rgba(23,32,38,.045);
        }
        .filter-bar { padding: 14px; margin-bottom: 16px; }
        .commerce-hero {
            background: linear-gradient(135deg, #101719 0%, #0f766e 54%, #b7791f 100%);
            color: #fff;
            border-radius: 8px;
            padding: 22px;
            margin-bottom: 16px;
        }
        .commerce-hero .eyebrow,
        .metric-card .label {
            font-size: .72rem;
            text-transform: uppercase;
            font-weight: 900;
            letter-spacing: 0;
        }
        .commerce-hero .eyebrow { color: rgba(255,255,255,.78); }
        .commerce-hero h2 {
            font-size: 1.72rem;
            line-height: 1.2;
            font-weight: 900;
            margin: 6px 0 8px;
        }
        .metric-card { padding: 16px; min-height: 126px; }
        .metric-card .label { color: var(--muted); }
        .metric-card .value {
            font-size: 1.48rem;
            font-weight: 900;
            margin-top: 6px;
            line-height: 1.12;
        }
        .metric-card .note { color: var(--muted); font-size: .82rem; margin-top: 8px; line-height: 1.35; }
        .panel { padding: 16px; margin-bottom: 16px; }
        .panel-title {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 12px;
        }
        .panel-title h2 { font-size: .98rem; margin: 0; font-weight: 900; }
        .chart-box { position: relative; height: 280px; }
        .chart-box-sm { position: relative; height: 230px; }
        .commerce-table { font-size: .88rem; margin: 0; }
        .commerce-table th {
            color: var(--muted);
            font-size: .72rem;
            text-transform: uppercase;
            letter-spacing: 0;
            white-space: nowrap;
        }
        .commerce-table td { vertical-align: middle; }
        .status-pill {
            display: inline-flex;
            padding: 5px 9px;
            border-radius: 999px;
            font-size: .75rem;
            font-weight: 900;
            white-space: nowrap;
        }
        .status-good { background: #dcfce7; color: #166534; }
        .status-watch { background: #fef3c7; color: #92400e; }
        .status-risk { background: #ffe4e6; color: #be123c; }
        .empty-state {
            border: 1px dashed #b8c4cf;
            border-radius: 8px;
            color: var(--muted);
            padding: 18px;
            background: #f8fafc;
        }
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }
        .info-box {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 16px;
            background: #fff;
            min-height: 100%;
        }
        .info-box h3 {
            font-size: .95rem;
            font-weight: 900;
            margin: 0 0 8px;
        }
        .info-box p {
            color: var(--muted);
            font-size: .88rem;
            line-height: 1.45;
            margin: 0 0 10px;
        }
        .info-list {
            margin: 0;
            padding-left: 18px;
            color: var(--ink);
            font-size: .86rem;
            line-height: 1.55;
        }
        .architecture-flow {
            display: grid;
            grid-template-columns: 1fr 64px 1fr 64px 1fr;
            gap: 12px;
            align-items: stretch;
            margin: 14px 0;
        }
        .flow-node {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            padding: 14px;
            min-height: 132px;
        }
        .flow-node .node-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: grid;
            place-items: center;
            margin-bottom: 10px;
            font-size: .95rem;
        }
        .node-oltp .node-icon { background: #dcfce7; color: #166534; }
        .node-etl .node-icon { background: #e0f2fe; color: #075985; }
        .node-olap .node-icon { background: #fef3c7; color: #92400e; }
        .flow-node h3 {
            font-size: .92rem;
            font-weight: 900;
            margin: 0 0 6px;
        }
        .flow-node p {
            color: var(--muted);
            font-size: .82rem;
            line-height: 1.42;
            margin: 0;
        }
        .flow-arrow {
            display: grid;
            place-items: center;
            color: var(--muted);
            font-size: 1.25rem;
        }
        .compare-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            overflow: hidden;
            border: 1px solid var(--line);
            border-radius: 8px;
            font-size: .86rem;
        }
        .compare-table th,
        .compare-table td {
            padding: 11px 12px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }
        .compare-table tr:last-child td { border-bottom: 0; }
        .compare-table th {
            background: #f8fafc;
            color: var(--muted);
            font-size: .72rem;
            text-transform: uppercase;
            font-weight: 900;
            white-space: nowrap;
        }
        .compare-table td:first-child {
            width: 170px;
            font-weight: 900;
            color: var(--ink);
            background: #fbfcfd;
        }
        .decision-strip {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-top: 14px;
        }
        .decision-item {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            padding: 13px;
        }
        .decision-item .label {
            color: var(--muted);
            font-size: .7rem;
            text-transform: uppercase;
            font-weight: 900;
            margin-bottom: 5px;
        }
        .decision-item .value {
            font-size: .9rem;
            font-weight: 900;
            line-height: 1.25;
        }
        .time-compare {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            padding: 16px;
            margin-top: 14px;
        }
        .time-compare h3 {
            font-size: .95rem;
            font-weight: 900;
            margin: 0 0 12px;
        }
        .time-flow {
            display: grid;
            grid-template-columns: 1fr 46px 1fr 46px 1fr;
            gap: 10px;
            align-items: stretch;
            margin-bottom: 14px;
        }
        .time-node {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #f8fafc;
            padding: 13px;
        }
        .time-node .time-label {
            color: var(--muted);
            font-size: .68rem;
            text-transform: uppercase;
            font-weight: 900;
            margin-bottom: 5px;
        }
        .time-node .time-value {
            font-size: .95rem;
            font-weight: 900;
            line-height: 1.25;
        }
        .time-node p {
            color: var(--muted);
            font-size: .8rem;
            line-height: 1.38;
            margin: 7px 0 0;
        }
        .time-arrow {
            display: grid;
            place-items: center;
            color: var(--muted);
        }
        .time-matrix {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }
        .time-metric {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px;
            background: #fff;
        }
        .time-metric .label {
            color: var(--muted);
            font-size: .68rem;
            text-transform: uppercase;
            font-weight: 900;
            margin-bottom: 5px;
        }
        .time-metric .value {
            font-size: .88rem;
            font-weight: 900;
            line-height: 1.3;
        }
        .benchmark-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin-bottom: 14px;
        }
        .benchmark-card {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            padding: 14px;
        }
        .benchmark-card .label {
            color: var(--muted);
            font-size: .7rem;
            text-transform: uppercase;
            font-weight: 900;
            margin-bottom: 6px;
        }
        .benchmark-card .actual {
            font-size: 1.25rem;
            font-weight: 900;
            line-height: 1.1;
        }
        .benchmark-card .target {
            color: var(--muted);
            font-size: .82rem;
            margin: 8px 0 10px;
        }
        .insight-board {
            display: grid;
            grid-template-columns: 1.1fr .9fr;
            gap: 14px;
        }
        .insight-list {
            display: grid;
            gap: 10px;
        }
        .insight-item {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #fff;
            padding: 13px;
            display: grid;
            grid-template-columns: 34px 1fr;
            gap: 10px;
        }
        .insight-icon {
            width: 34px;
            height: 34px;
            border-radius: 8px;
            display: grid;
            place-items: center;
            background: #e0f2fe;
            color: #075985;
        }
        .insight-item h3 {
            font-size: .9rem;
            font-weight: 900;
            margin: 0 0 4px;
        }
        .insight-item p {
            color: var(--muted);
            font-size: .84rem;
            line-height: 1.42;
            margin: 0;
        }
        .benchmark-summary {
            border: 1px solid var(--line);
            border-radius: 8px;
            background: #f8fafc;
            padding: 16px;
        }
        .benchmark-summary .big-number {
            font-size: 1.65rem;
            font-weight: 900;
            line-height: 1.1;
        }
        .benchmark-summary p {
            color: var(--muted);
            font-size: .86rem;
            line-height: 1.45;
            margin: 8px 0 0;
        }
        @media (max-width: 960px) {
            .app-shell { display: block; }
            .sidebar { width: 100%; min-height: auto; display: block; }
            .nav-list { display: flex; overflow-x: auto; padding-bottom: 8px; }
            .nav-link-commerce { white-space: nowrap; border-left: none; border-bottom: 3px solid transparent; }
            .nav-link-commerce.active { border-bottom-color: #10b981; }
            .main { height: auto; padding: 16px; }
            .topbar { display: block; }
            .info-grid { grid-template-columns: 1fr; }
            .architecture-flow { grid-template-columns: 1fr; }
            .flow-arrow { min-height: 24px; transform: rotate(90deg); }
            .decision-strip { grid-template-columns: 1fr; }
            .time-flow { grid-template-columns: 1fr; }
            .time-arrow { min-height: 22px; transform: rotate(90deg); }
            .time-matrix { grid-template-columns: 1fr; }
            .benchmark-grid { grid-template-columns: 1fr; }
            .insight-board { grid-template-columns: 1fr; }
            .compare-table { min-width: 720px; }
        }
    </style>
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <div class="brand d-flex align-items-center gap-3">
            <div class="brand-mark"><i class="fa-solid fa-bag-shopping"></i></div>
            <div>
                <div class="fw-bold">Pagila Commerce</div>
                <div style="font-size:.78rem;color:#a8b5b1;">Film rental storefront</div>
            </div>
        </div>
        <nav class="nav-list pt-3">
            <a class="nav-link-commerce <?= $page === 'overview' ? 'active' : ''; ?>" href="index.php?page=overview"><i class="fa-solid fa-gauge-high"></i> Overview</a>
            <a class="nav-link-commerce <?= $page === 'insights' ? 'active' : ''; ?>" href="index.php?page=insights"><i class="fa-solid fa-chart-simple"></i> Insights</a>
            <a class="nav-link-commerce <?= $page === 'catalog' ? 'active' : ''; ?>" href="index.php?page=catalog"><i class="fa-solid fa-clapperboard"></i> Catalog</a>
            <a class="nav-link-commerce <?= $page === 'orders' ? 'active' : ''; ?>" href="index.php?page=orders"><i class="fa-solid fa-receipt"></i> Orders</a>
            <a class="nav-link-commerce <?= $page === 'customers' ? 'active' : ''; ?>" href="index.php?page=customers"><i class="fa-solid fa-users"></i> Customers</a>
            <a class="nav-link-commerce <?= $page === 'marketing' ? 'active' : ''; ?>" href="index.php?page=marketing"><i class="fa-solid fa-bullhorn"></i> Marketing</a>
            <a class="nav-link-commerce <?= $page === 'films' ? 'active' : ''; ?>" href="index.php?page=films"><i class="fa-solid fa-film"></i> Films BI</a>
            <a class="nav-link-commerce <?= $page === 'stores' ? 'active' : ''; ?>" href="index.php?page=stores"><i class="fa-solid fa-store"></i> Stores</a>
            <a class="nav-link-commerce <?= $page === 'rental' ? 'active' : ''; ?>" href="index.php?page=rental"><i class="fa-solid fa-database"></i> Rental BI</a>
        </nav>
        <div class="mt-auto p-3">
            <div class="p-3 rounded" style="background:rgba(16,185,129,.1);color:#a7f3d0;font-size:.84rem;">
                <i class="fa-solid fa-server me-1"></i> Database: Pagila rental film + commerce layer
            </div>
        </div>
    </aside>

    <main class="main">
        <div class="topbar">
            <div class="title-block">
                <h1><?= h($pageTitles[$page]); ?></h1>
                <p>Web commerce profesional untuk menjual paket rental, membership, dan katalog film dari ekosistem Pagila.</p>
            </div>
            <div class="badge text-bg-light border px-3 py-2">
                <i class="fa-solid fa-circle <?= $commerceReady ? 'text-success' : 'text-warning'; ?> me-1"></i>
                <?= $commerceReady ? 'Commerce data aktif' : 'Jalankan SQL seed'; ?>
            </div>
        </div>

        <?php if (!$commerceReady): ?>
            <div class="empty-state mb-3">
                <strong>Data commerce belum ditemukan.</strong>
                Jalankan file <code>sql/commerce_seed.sql</code> di pgAdmin pada database Pagila/DWH Anda, lalu refresh halaman ini.
            </div>
        <?php endif; ?>

        <form class="filter-bar" method="get">
            <input type="hidden" name="page" value="<?= h($page); ?>">
            <div class="row g-2 align-items-end">
                <div class="col-xl-2 col-md-6">
                    <label class="form-label small fw-bold text-muted" for="date_from">Tanggal mulai</label>
                    <input class="form-control form-control-sm" id="date_from" name="date_from" type="date" value="<?= h($dateFrom); ?>">
                </div>
                <div class="col-xl-2 col-md-6">
                    <label class="form-label small fw-bold text-muted" for="date_to">Tanggal akhir</label>
                    <input class="form-control form-control-sm" id="date_to" name="date_to" type="date" value="<?= h($dateTo); ?>">
                </div>
                <div class="col-xl-2 col-md-6">
                    <label class="form-label small fw-bold text-muted" for="status">Status order</label>
                    <select class="form-select form-select-sm" id="status" name="status">
                        <option value="">Semua status</option>
                        <?php foreach ($statusOptions as $option): ?>
                            <option value="<?= h($option['status']); ?>" <?= $option['status'] === $statusFilter ? 'selected' : ''; ?>><?= h(ucfirst($option['status'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-xl-3 col-md-6">
                    <label class="form-label small fw-bold text-muted" for="category">Kategori</label>
                    <select class="form-select form-select-sm" id="category" name="category">
                        <option value="">Semua kategori</option>
                        <?php foreach ($categoryOptions as $option): ?>
                            <option value="<?= h($option['category']); ?>" <?= $option['category'] === $categoryFilter ? 'selected' : ''; ?>><?= h($option['category']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-xl-3 col-md-6 d-flex gap-2">
                    <button class="btn btn-sm btn-dark flex-fill" type="submit"><i class="fa-solid fa-filter me-1"></i> Terapkan</button>
                    <a class="btn btn-sm btn-outline-secondary" href="index.php?page=<?= h($page); ?>" title="Reset filter"><i class="fa-solid fa-rotate-left"></i></a>
                </div>
            </div>
        </form>

        <?php if ($page === 'overview'): ?>
            <section class="commerce-hero">
                <div class="row g-3 align-items-center">
                    <div class="col-lg-7">
                        <div class="eyebrow">Commerce Snapshot</div>
                        <h2>Pagila film rental commerce command center</h2>
                        <div style="color:rgba(255,255,255,.84);max-width:680px;">Pantau order online, paket rental, stok film fisik, revenue campaign, dan kontribusi data rental Pagila dalam satu layar kerja.</div>
                    </div>
                    <div class="col-lg-5">
                        <div class="row g-2">
                            <div class="col-4"><div class="eyebrow">Top item</div><div class="fw-bold"><?= h($bestProduct['product_name'] ?? 'Belum ada'); ?></div></div>
                            <div class="col-4"><div class="eyebrow">Paid sales</div><div class="fw-bold"><?= rupiah($paidRevenue); ?></div></div>
                            <div class="col-4"><div class="eyebrow">Low stock</div><div class="fw-bold"><?= number_format($lowStock); ?></div></div>
                        </div>
                    </div>
                </div>
            </section>

            <div class="row g-3 mb-3">
                <div class="col-xl-3 col-md-6"><div class="metric-card"><div class="label">Gross Merchandise Value</div><div class="value text-primary"><?= rupiah($gmv); ?></div><div class="note">Total nilai order commerce.</div></div></div>
                <div class="col-xl-3 col-md-6"><div class="metric-card"><div class="label">Total Orders</div><div class="value text-success"><?= number_format($totalOrders); ?></div><div class="note">Order dari website, mobile app, dan partner.</div></div></div>
                <div class="col-xl-3 col-md-6"><div class="metric-card"><div class="label">Active Buyers</div><div class="value" style="color:var(--amber);"><?= number_format($activeCustomers); ?></div><div class="note">Customer unik pada filter aktif.</div></div></div>
                <div class="col-xl-3 col-md-6"><div class="metric-card"><div class="label">Average Order Value</div><div class="value" style="color:var(--red);"><?= rupiah($avgOrder); ?></div><div class="note">Cancel rate <?= percent($cancelRate); ?>.</div></div></div>
            </div>

            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="panel">
                        <div class="panel-title"><h2><i class="fa-solid fa-chart-line me-1"></i> Tren Sales & Order</h2><span class="badge text-bg-light border">Bulanan</span></div>
                        <div class="chart-box"><canvas id="trendChart"></canvas></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="panel">
                        <div class="panel-title"><h2><i class="fa-solid fa-layer-group me-1"></i> Revenue per Kategori</h2><span class="badge text-bg-light border">Catalog</span></div>
                        <div class="chart-box"><canvas id="categoryChart"></canvas></div>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-7">
                    <div class="panel">
                        <div class="panel-title"><h2><i class="fa-solid fa-ranking-star me-1"></i> Produk Rental Terlaris</h2><a class="btn btn-sm btn-outline-dark" href="index.php?page=catalog">Detail</a></div>
                        <?php include __DIR__ . '/includes/commerce_product_table.php'; ?>
                    </div>
                </div>
                <div class="col-lg-5">
                    <div class="panel">
                        <div class="panel-title"><h2><i class="fa-solid fa-clock-rotate-left me-1"></i> Order Terbaru</h2><a class="btn btn-sm btn-outline-dark" href="index.php?page=orders">Kelola</a></div>
                        <?php include __DIR__ . '/includes/commerce_order_table.php'; ?>
                    </div>
                </div>
            </div>

        <?php elseif ($page === 'insights'): ?>
            <div class="panel">
                <div class="panel-title"><h2><i class="fa-solid fa-chart-simple me-1"></i> Benchmark & Insight Bisnis</h2><span class="badge text-bg-light border">Bagian benchmark dan insight bisnis</span></div>
                <div class="row g-3 mb-3">
                    <div class="col-lg-7">
                        <div class="panel m-0">
                            <div class="panel-title"><h2><i class="fa-solid fa-scale-balanced me-1"></i> Grafik Benchmark Aktual vs Target</h2><span class="badge text-bg-light border">Target = 100%</span></div>
                            <div class="chart-box-sm"><canvas id="benchmarkChart"></canvas></div>
                        </div>
                    </div>
                    <div class="col-lg-5">
                        <div class="panel m-0">
                            <div class="panel-title"><h2><i class="fa-solid fa-stopwatch me-1"></i> Grafik Perbandingan Waktu</h2><span class="badge text-bg-light border">Skala log</span></div>
                            <div class="chart-box-sm"><canvas id="timeCompareChart"></canvas></div>
                        </div>
                    </div>
                </div>
                <div class="benchmark-grid">
                    <?php foreach ($benchmarkRows as $benchmark): ?>
                        <div class="benchmark-card">
                            <div class="label"><?= h($benchmark['label']); ?></div>
                            <div class="actual"><?= h($benchmark['actual']); ?></div>
                            <div class="target">Benchmark: <?= h($benchmark['target']); ?> | Selisih: <?= h($benchmark['delta']); ?></div>
                            <span class="status-pill <?= h($benchmark['class']); ?>"><?= h($benchmark['status']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="insight-board">
                    <div class="insight-list">
                        <div class="insight-item">
                            <div class="insight-icon"><i class="fa-solid fa-boxes-stacked"></i></div>
                            <div>
                                <h3>Prioritaskan restock produk rental populer</h3>
                                <p>Low stock ratio berada di <?= percent($lowStockRate); ?> dari <?= number_format($activeProducts); ?> produk aktif. Produk dengan stok di bawah reorder level perlu masuk daftar pembelian ulang.</p>
                            </div>
                        </div>
                        <div class="insight-item">
                            <div class="insight-icon"><i class="fa-solid fa-basket-shopping"></i></div>
                            <div>
                                <h3>Dorong AOV lewat bundle dan membership</h3>
                                <p>AOV saat ini <?= rupiah($avgOrder); ?> dibanding benchmark <?= rupiah($aovTarget); ?>. Bundle weekend dan membership Gold bisa dipakai sebagai upsell utama.</p>
                            </div>
                        </div>
                        <div class="insight-item">
                            <div class="insight-icon"><i class="fa-solid fa-bullseye"></i></div>
                            <div>
                                <h3>Optimalkan channel marketing dengan ROAS tertinggi</h3>
                                <p>Rata-rata ROAS campaign <?= number_format($averageRoas, 2, ',', '.'); ?>x dan conversion <?= percent($averageConversion); ?>. Budget sebaiknya digeser ke campaign yang konsisten di atas benchmark.</p>
                            </div>
                        </div>
                    </div>
                    <div class="benchmark-summary">
                        <div class="text-muted small fw-bold text-uppercase">Executive Insight</div>
                        <div class="big-number mt-2"><?= percent($commerceShare); ?></div>
                        <p>Kontribusi paid commerce terhadap revenue rental Pagila historis. Angka ini membantu melihat apakah channel digital mulai menjadi mesin pendapatan tambahan atau masih perlu akselerasi.</p>
                        <hr>
                        <div class="text-muted small fw-bold text-uppercase">Rekomendasi cepat</div>
                        <p>Fokus minggu ini: restock item low-stock, promosi bundle pada produk top revenue, dan audit order cancelled agar conversion checkout tidak bocor.</p>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="panel-title"><h2><i class="fa-solid fa-code-compare me-1"></i> Perbandingan OLTP dan OLAP</h2><span class="badge text-bg-light border">Arsitektur data</span></div>
                <div class="architecture-flow">
                    <div class="flow-node node-oltp">
                        <div class="node-icon"><i class="fa-solid fa-cash-register"></i></div>
                        <h3>OLTP Transaction Layer</h3>
                        <p>Order commerce, pelanggan, item order, pembayaran, dan stok dicatat sebagai transaksi detail yang cepat berubah.</p>
                    </div>
                    <div class="flow-arrow"><i class="fa-solid fa-arrow-right-long"></i></div>
                    <div class="flow-node node-etl">
                        <div class="node-icon"><i class="fa-solid fa-arrows-rotate"></i></div>
                        <h3>ETL / Data Processing</h3>
                        <p>Data transaksi dibersihkan, digabung, dihitung, lalu dipindahkan ke model analitik yang lebih siap dibaca dashboard.</p>
                    </div>
                    <div class="flow-arrow"><i class="fa-solid fa-arrow-right-long"></i></div>
                    <div class="flow-node node-olap">
                        <div class="node-icon"><i class="fa-solid fa-chart-column"></i></div>
                        <h3>OLAP Analytics Layer</h3>
                        <p>Fact dan dimension dipakai untuk KPI, tren revenue, performa film, segmentasi customer, dan laporan manajemen.</p>
                    </div>
                </div>

                <div class="time-compare">
                    <h3><i class="fa-solid fa-clock me-1"></i> Compare Waktu OLTP vs OLAP</h3>
                    <div class="chart-box-sm mb-3"><canvas id="timeDetailChart"></canvas></div>
                    <div class="time-flow">
                        <div class="time-node">
                            <div class="time-label">T+0 detik</div>
                            <div class="time-value">Transaksi masuk ke OLTP</div>
                            <p>Order baru, pembayaran, dan perubahan stok harus langsung tersimpan agar operasional berjalan real-time.</p>
                        </div>
                        <div class="time-arrow"><i class="fa-solid fa-arrow-right-long"></i></div>
                        <div class="time-node">
                            <div class="time-label">T+5 menit sampai harian</div>
                            <div class="time-value">ETL / sinkronisasi data</div>
                            <p>Data operasional dibersihkan, digabung, dan dihitung menjadi data analitik yang stabil untuk laporan.</p>
                        </div>
                        <div class="time-arrow"><i class="fa-solid fa-arrow-right-long"></i></div>
                        <div class="time-node">
                            <div class="time-label">Near real-time / batch</div>
                            <div class="time-value">Analisis tersedia di OLAP</div>
                            <p>Dashboard membaca data agregat untuk tren, benchmark, insight bisnis, dan keputusan manajemen.</p>
                        </div>
                    </div>
                    <div class="time-matrix">
                        <div class="time-metric">
                            <div class="label">Kecepatan OLTP</div>
                            <div class="value">Milidetik sampai detik per transaksi</div>
                        </div>
                        <div class="time-metric">
                            <div class="label">Kecepatan OLAP</div>
                            <div class="value">Detik untuk query agregasi besar</div>
                        </div>
                        <div class="time-metric">
                            <div class="label">Data freshness</div>
                            <div class="value">OLTP paling baru, OLAP tergantung jadwal ETL</div>
                        </div>
                        <div class="time-metric">
                            <div class="label">Contoh bisnis</div>
                            <div class="value">Checkout sekarang vs laporan revenue bulanan</div>
                        </div>
                    </div>
                </div>

                <div class="info-grid">
                    <div class="info-box">
                        <h3>OLTP: Sistem Transaksi Harian</h3>
                        <p>OLTP dipakai untuk mencatat aktivitas operasional yang sering berubah, seperti order commerce, item order, customer, pembayaran, dan stok katalog rental.</p>
                        <ul class="info-list">
                            <li>Contoh tabel: <code>commerce_orders</code>, <code>commerce_order_items</code>, <code>commerce_customers</code>.</li>
                            <li>Fokus: insert, update, validasi transaksi, dan data detail per kejadian.</li>
                            <li>Cocok untuk halaman order management, checkout, dan pengelolaan katalog.</li>
                        </ul>
                    </div>
                    <div class="info-box">
                        <h3>OLAP: Analisis dan Dashboard</h3>
                        <p>OLAP dipakai untuk membaca data yang sudah diringkas atau dimodelkan agar cepat dianalisis sebagai laporan bisnis rental film Pagila.</p>
                        <ul class="info-list">
                            <li>Contoh tabel: <code>fact_sales</code>, <code>dim_film</code>, <code>fact_store_performance</code>.</li>
                            <li>Fokus: agregasi revenue, tren rental, performa film, customer, dan cabang.</li>
                            <li>Cocok untuk chart, KPI, ranking produk, dan pengambilan keputusan manajemen.</li>
                        </ul>
                    </div>
                </div>

                <div class="table-responsive mt-3">
                    <table class="compare-table">
                        <thead>
                            <tr>
                                <th>Aspek</th>
                                <th>OLTP</th>
                                <th>OLAP</th>
                                <th>Contoh di Web Ini</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Tujuan</td>
                                <td>Menjalankan proses bisnis harian secara akurat dan real-time.</td>
                                <td>Menganalisis performa bisnis dari data historis dan agregat.</td>
                                <td>Order management memakai OLTP, chart revenue memakai OLAP.</td>
                            </tr>
                            <tr>
                                <td>Bentuk Data</td>
                                <td>Detail transaksi per order, item, customer, pembayaran, dan stok.</td>
                                <td>Data fact/dimension yang sudah diringkas untuk analisis cepat.</td>
                                <td><code>commerce_orders</code> dibandingkan dengan <code>fact_sales</code>.</td>
                            </tr>
                            <tr>
                                <td>Operasi Utama</td>
                                <td>Banyak insert dan update kecil dengan validasi ketat.</td>
                                <td>Banyak query baca, agregasi, filter periode, dan grouping.</td>
                                <td>Checkout/update status order vs KPI GMV, trend, ranking produk.</td>
                            </tr>
                            <tr>
                                <td>Pengguna</td>
                                <td>Admin toko, kasir, customer service, dan sistem checkout.</td>
                                <td>Owner, manager, analis bisnis, dan tim marketing.</td>
                                <td>Halaman Orders untuk operasional, Overview untuk manajemen.</td>
                            </tr>
                            <tr>
                                <td>Keputusan Bisnis</td>
                                <td>Apakah order dibayar, dikirim, dibatalkan, atau stok perlu dikurangi.</td>
                                <td>Film mana yang paling laku, campaign mana yang profit, customer mana yang bernilai tinggi.</td>
                                <td>Low stock alert, top rental product, campaign ROAS, CLV customer.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <div class="decision-strip">
                    <div class="decision-item">
                        <div class="label">Gunakan OLTP untuk</div>
                        <div class="value">Transaksi cepat dan data operasional detail</div>
                    </div>
                    <div class="decision-item">
                        <div class="label">Gunakan OLAP untuk</div>
                        <div class="value">Dashboard, laporan, dan analisis historis</div>
                    </div>
                    <div class="decision-item">
                        <div class="label">Nilai bisnis</div>
                        <div class="value">Operasi rapi, keputusan manajemen lebih cepat</div>
                    </div>
                    <div class="decision-item">
                        <div class="label">Model ideal</div>
                        <div class="value">OLTP mencatat, ETL mengolah, OLAP menganalisis</div>
                    </div>
                </div>
            </div>
        <?php elseif ($page === 'catalog'): ?>
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="metric-card"><div class="label">Produk aktif</div><div class="value text-primary"><?= number_format(count($productRows)); ?></div><div class="note">Paket rental dan produk digital.</div></div></div>
                <div class="col-md-3"><div class="metric-card"><div class="label">Low stock</div><div class="value" style="color:var(--red);"><?= number_format($lowStock); ?></div><div class="note">Perlu replenishment inventory.</div></div></div>
                <div class="col-md-3"><div class="metric-card"><div class="label">Top product</div><div class="value text-success" style="font-size:1rem;"><?= h($bestProduct['product_name'] ?? '-'); ?></div><div class="note"><?= $bestProduct ? rupiah($bestProduct['revenue']) : 'Belum ada data'; ?></div></div></div>
                <div class="col-md-3"><div class="metric-card"><div class="label">Pagila rentals</div><div class="value" style="color:var(--amber);"><?= number_format($pagilaRentals); ?></div><div class="note">Basis demand rental film.</div></div></div>
            </div>
            <div class="panel">
                <div class="panel-title"><h2><i class="fa-solid fa-clapperboard me-1"></i> Catalog Performance</h2><span class="badge text-bg-light border">commerce_products</span></div>
                <div class="chart-box-sm mb-3"><canvas id="productChart"></canvas></div>
                <?php include __DIR__ . '/includes/commerce_product_table.php'; ?>
            </div>
        <?php elseif ($page === 'orders'): ?>
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="metric-card"><div class="label">GMV</div><div class="value text-primary"><?= rupiah($gmv); ?></div><div class="note">Nilai order bruto.</div></div></div>
                <div class="col-md-3"><div class="metric-card"><div class="label">Paid sales</div><div class="value text-success"><?= rupiah($paidRevenue); ?></div><div class="note">Order selain cancelled.</div></div></div>
                <div class="col-md-3"><div class="metric-card"><div class="label">AOV</div><div class="value" style="color:var(--amber);"><?= rupiah($avgOrder); ?></div><div class="note">Rata-rata nilai order.</div></div></div>
                <div class="col-md-3"><div class="metric-card"><div class="label">Cancel rate</div><div class="value" style="color:var(--red);"><?= percent($cancelRate); ?></div><div class="note"><?= number_format($cancelledOrders); ?> order batal.</div></div></div>
            </div>
            <div class="panel">
                <div class="panel-title"><h2><i class="fa-solid fa-receipt me-1"></i> Recent Orders</h2><span class="badge text-bg-light border">commerce_orders</span></div>
                <?php include __DIR__ . '/includes/commerce_order_table.php'; ?>
            </div>
        <?php elseif ($page === 'customers'): ?>
            <div class="panel">
                <div class="panel-title"><h2><i class="fa-solid fa-users me-1"></i> Customer Lifetime Value</h2><span class="badge text-bg-light border"><?= h($customerSourceLabel); ?></span></div>
                <div class="table-responsive">
                    <table class="table commerce-table table-hover">
                        <thead><tr><th>Customer</th><th>Email</th><th>Kota</th><th>Negara</th><th>Status</th><th>Segment</th><th>Order</th><th>Lifetime Value</th><th>Last Order</th></tr></thead>
                        <tbody>
                            <?php foreach ($customerRows as $customer): ?>
                                <tr>
                                    <td><strong><?= h($customer['full_name']); ?></strong><br><span class="text-muted"><?= h($customer['phone']); ?></span></td>
                                    <td><?= h($customer['email']); ?></td>
                                    <td><?= h($customer['city']); ?></td>
                                    <td><?= h($customer['country']); ?></td>
                                    <td><span class="status-pill <?= ($customer['is_active'] === true || $customer['is_active'] === '1' || $customer['is_active'] === 't') ? 'status-good' : 'status-risk'; ?>"><?= ($customer['is_active'] === true || $customer['is_active'] === '1' || $customer['is_active'] === 't') ? 'Active' : 'Inactive'; ?></span></td>
                                    <td><span class="status-pill <?= $customer['segment'] === 'At Risk' ? 'status-risk' : ($customer['segment'] === 'New' ? 'status-watch' : 'status-good'); ?>"><?= h($customer['segment']); ?></span></td>
                                    <td><?= number_format($customer['orders_count']); ?></td>
                                    <td class="fw-bold"><?= rupiah($customer['lifetime_value']); ?></td>
                                    <td><?= h($customer['last_order'] ?? '-'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($page === 'marketing'): ?>
            <div class="panel">
                <div class="panel-title"><h2><i class="fa-solid fa-bullhorn me-1"></i> Campaign ROAS</h2><span class="badge text-bg-light border">commerce_campaigns</span></div>
                <div class="table-responsive">
                    <table class="table commerce-table table-hover">
                        <thead><tr><th>Campaign</th><th>Channel</th><th>Spend</th><th>Attributed Revenue</th><th>ROAS</th><th>Conversion</th><th>Return</th></tr></thead>
                        <tbody>
                            <?php foreach ($campaignRows as $campaign): ?>
                                <tr>
                                    <td><strong><?= h($campaign['campaign_name']); ?></strong></td>
                                    <td><?= h($campaign['channel']); ?></td>
                                    <td><?= rupiah($campaign['spend']); ?></td>
                                    <td class="fw-bold"><?= rupiah($campaign['revenue_attributed']); ?></td>
                                    <td><span class="status-pill <?= (float)$campaign['roas'] >= 4 ? 'status-good' : 'status-watch'; ?>"><?= number_format($campaign['roas'], 2, ',', '.'); ?>x</span></td>
                                    <td><?= percent($campaign['conversion_rate']); ?></td>
                                    <td><?= percent($campaign['return_rate']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($page === 'films'): ?>
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="metric-card"><div class="label">Film master</div><div class="value text-primary"><?= number_format($pagilaFilmCount); ?></div><div class="note">Jumlah film di <code>dim_film</code>.</div></div></div>
                <div class="col-md-3"><div class="metric-card"><div class="label">Rental revenue</div><div class="value text-success"><?= rupiah($pagilaRevenue); ?></div><div class="note">Total revenue dari <code>fact_sales</code>.</div></div></div>
                <div class="col-md-3"><div class="metric-card"><div class="label">Rental volume</div><div class="value" style="color:var(--amber);"><?= number_format($pagilaRentals); ?></div><div class="note">Total transaksi rental historis.</div></div></div>
                <div class="col-md-3"><div class="metric-card"><div class="label">Top film</div><div class="value text-success" style="font-size:1rem;"><?= h($filmBiRows[0]['title'] ?? '-'); ?></div><div class="note"><?= isset($filmBiRows[0]) ? rupiah($filmBiRows[0]['rental_revenue']) : 'Belum ada data'; ?></div></div></div>
            </div>
            <div class="panel">
                <div class="panel-title"><h2><i class="fa-solid fa-film me-1"></i> Film Rental Performance</h2><span class="badge text-bg-light border">Pagila OLAP</span></div>
                <div class="chart-box-sm mb-3"><canvas id="filmBiChart"></canvas></div>
                <div class="table-responsive">
                    <table class="table commerce-table table-hover">
                        <thead><tr><th>Film</th><th>Inventory</th><th>Disewa</th><th>Utilisasi</th><th>Revenue</th><th>ROI</th></tr></thead>
                        <tbody>
                            <?php foreach ($filmBiRows as $film): ?>
                                <tr>
                                    <td><strong><?= h($film['title']); ?></strong><br><span class="text-muted">Film #<?= h($film['film_key']); ?></span></td>
                                    <td><?= number_format($film['inventory_count']); ?></td>
                                    <td><?= number_format($film['rented_copies']); ?></td>
                                    <td><?= percent((float)$film['utilization_rate'] * 100); ?></td>
                                    <td class="fw-bold"><?= rupiah($film['rental_revenue']); ?></td>
                                    <td><span class="status-pill <?= (float)$film['roi_percent'] >= 100 ? 'status-good' : 'status-watch'; ?>"><?= percent($film['roi_percent']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($page === 'stores'): ?>
            <div class="row g-3 mb-3">
                <div class="col-md-3"><div class="metric-card"><div class="label">Store revenue</div><div class="value text-primary"><?= rupiah($storeTotalRevenue); ?></div><div class="note">Akumulasi semua cabang.</div></div></div>
                <div class="col-md-3"><div class="metric-card"><div class="label">Net profit</div><div class="value text-success"><?= rupiah($storeTotalProfit); ?></div><div class="note">Profit operasional cabang.</div></div></div>
                <div class="col-md-3"><div class="metric-card"><div class="label">Transactions</div><div class="value" style="color:var(--amber);"><?= number_format($storeTotalTransactions); ?></div><div class="note">Total transaksi store.</div></div></div>
                <div class="col-md-3"><div class="metric-card"><div class="label">Avg margin</div><div class="value" style="color:var(--red);"><?= percent($storeAvgMargin); ?></div><div class="note">Rata-rata margin cabang.</div></div></div>
            </div>
            <div class="panel">
                <div class="panel-title"><h2><i class="fa-solid fa-store me-1"></i> Store Operations Scorecard</h2><span class="badge text-bg-light border">fact_store_performance</span></div>
                <div class="chart-box-sm mb-3"><canvas id="storeBiChart"></canvas></div>
                <div class="table-responsive">
                    <table class="table commerce-table table-hover">
                        <thead><tr><th>Toko</th><th>Revenue</th><th>Transaksi</th><th>Pelanggan</th><th>Profit</th><th>Margin</th><th>Kepuasan</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach ($storeBiRows as $store): ?>
                                <?php $healthy = (float)$store['profit_margin_percent'] >= 20 && (float)$store['low_stock_alerts'] <= 3; ?>
                                <tr>
                                    <td><strong>Toko #<?= h($store['store_key']); ?></strong></td>
                                    <td class="fw-bold"><?= rupiah($store['total_revenue']); ?></td>
                                    <td><?= number_format($store['total_transactions']); ?></td>
                                    <td><?= number_format($store['unique_customers']); ?></td>
                                    <td><?= rupiah($store['net_profit']); ?></td>
                                    <td><?= percent($store['profit_margin_percent']); ?></td>
                                    <td><?= number_format((float)$store['customer_satisfaction_score'], 1, ',', '.'); ?>/5</td>
                                    <td><span class="status-pill <?= $healthy ? 'status-good' : 'status-watch'; ?>"><?= $healthy ? 'Sehat' : 'Pantau'; ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif ($page === 'rental'): ?>
            <div class="row g-3 mb-3">
                <div class="col-md-4"><div class="metric-card"><div class="label">Pagila rental revenue</div><div class="value text-primary"><?= rupiah($pagilaRevenue); ?></div><div class="note">Dari <code>fact_sales</code> database rental film.</div></div></div>
                <div class="col-md-4"><div class="metric-card"><div class="label">Pagila rental transactions</div><div class="value text-success"><?= number_format($pagilaRentals); ?></div><div class="note">Volume transaksi rental historis.</div></div></div>
                <div class="col-md-4"><div class="metric-card"><div class="label">Commerce GMV</div><div class="value" style="color:var(--amber);"><?= rupiah($gmv); ?></div><div class="note">Layer commerce tambahan di database yang sama.</div></div></div>
            </div>
            <div class="panel">
                <div class="panel-title"><h2><i class="fa-solid fa-database me-1"></i> Integrasi Pagila</h2><span class="badge text-bg-light border">Tetap rental film</span></div>
                <p class="text-muted mb-0">Halaman ini mempertahankan sumber data rental film Pagila melalui tabel DWH seperti <code>fact_sales</code>, lalu menambahkan layer <code>commerce_*</code> untuk kebutuhan web commerce profesional.</p>
            </div>
        <?php endif; ?>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const trendLabels = <?= json_encode($trendLabels); ?>;
    const trendRevenue = <?= json_encode($trendRevenue); ?>;
    const trendOrders = <?= json_encode($trendOrders); ?>;
    const categoryLabels = <?= json_encode($categoryLabels); ?>;
    const categoryRevenue = <?= json_encode($categoryRevenue); ?>;
    const productLabels = <?= json_encode($productLabels); ?>;
    const productRevenue = <?= json_encode($productRevenue); ?>;
    const filmBiLabels = <?= json_encode($filmBiLabels); ?>;
    const filmBiRevenue = <?= json_encode($filmBiRevenue); ?>;
    const storeBiLabels = <?= json_encode($storeBiLabels); ?>;
    const storeBiRevenue = <?= json_encode($storeBiRevenue); ?>;
    const benchmarkChartLabels = <?= json_encode($benchmarkChartLabels); ?>;
    const benchmarkActual = <?= json_encode($benchmarkActual); ?>;
    const benchmarkTarget = <?= json_encode($benchmarkTarget); ?>;
    const timeCompareLabels = <?= json_encode($timeCompareLabels); ?>;
    const timeCompareValues = <?= json_encode($timeCompareValues); ?>;

    document.addEventListener('DOMContentLoaded', function () {
        const trendChart = document.getElementById('trendChart');
        if (trendChart) {
            new Chart(trendChart, {
                type: 'line',
                data: {
                    labels: trendLabels,
                    datasets: [
                        { label: 'Sales', data: trendRevenue, borderColor: '#0f766e', backgroundColor: 'rgba(15,118,110,.08)', fill: true, tension: .25, pointRadius: 3 },
                        { label: 'Orders', data: trendOrders, borderColor: '#b7791f', yAxisID: 'y1', tension: .25, pointRadius: 3 }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
                    scales: {
                        y: { beginAtZero: true, ticks: { callback: value => 'Rp ' + Number(value).toLocaleString('id-ID') } },
                        y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } }
                    }
                }
            });
        }

        const categoryChart = document.getElementById('categoryChart');
        if (categoryChart) {
            new Chart(categoryChart, {
                type: 'doughnut',
                data: { labels: categoryLabels, datasets: [{ data: categoryRevenue, backgroundColor: ['#0f766e', '#2563eb', '#b7791f', '#be123c', '#475569'] }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
            });
        }

        const productChart = document.getElementById('productChart');
        if (productChart) {
            new Chart(productChart, {
                type: 'bar',
                data: { labels: productLabels, datasets: [{ label: 'Revenue', data: productRevenue, backgroundColor: '#2563eb' }] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
            });
        }

        const filmBiChart = document.getElementById('filmBiChart');
        if (filmBiChart) {
            new Chart(filmBiChart, {
                type: 'bar',
                data: { labels: filmBiLabels, datasets: [{ label: 'Rental Revenue', data: filmBiRevenue, backgroundColor: '#0f766e' }] },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } }
            });
        }

        const storeBiChart = document.getElementById('storeBiChart');
        if (storeBiChart) {
            new Chart(storeBiChart, {
                type: 'bar',
                data: { labels: storeBiLabels, datasets: [{ label: 'Store Revenue', data: storeBiRevenue, backgroundColor: '#b7791f' }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
            });
        }

        const benchmarkChart = document.getElementById('benchmarkChart');
        if (benchmarkChart) {
            new Chart(benchmarkChart, {
                type: 'bar',
                data: {
                    labels: benchmarkChartLabels,
                    datasets: [
                        { label: 'Aktual (% target)', data: benchmarkActual, backgroundColor: '#0f766e' },
                        { label: 'Benchmark', data: benchmarkTarget, backgroundColor: '#d9e1e8' }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } },
                    scales: { y: { beginAtZero: true, ticks: { callback: value => value + '%' } } }
                }
            });
        }

        const timeChartOptions = {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: context => {
                            const value = Number(context.raw);
                            if (value >= 86400) return 'Waktu: 1 hari / batch harian';
                            if (value >= 60) return 'Waktu: ' + Math.round(value / 60) + ' menit';
                            return 'Waktu: ' + value + ' detik';
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: 'logarithmic',
                    min: 1,
                    ticks: {
                        callback: value => {
                            if (value === 1) return '1 dtk';
                            if (value === 10) return '10 dtk';
                            if (value === 100) return '~2 mnt';
                            if (value === 1000) return '~17 mnt';
                            if (value === 10000) return '~3 jam';
                            return '';
                        }
                    }
                }
            }
        };

        const timeCompareChart = document.getElementById('timeCompareChart');
        if (timeCompareChart) {
            new Chart(timeCompareChart, {
                type: 'bar',
                data: { labels: timeCompareLabels, datasets: [{ data: timeCompareValues, backgroundColor: ['#0f766e', '#2563eb', '#b7791f', '#be123c'] }] },
                options: timeChartOptions
            });
        }

        const timeDetailChart = document.getElementById('timeDetailChart');
        if (timeDetailChart) {
            new Chart(timeDetailChart, {
                type: 'bar',
                data: { labels: timeCompareLabels, datasets: [{ data: timeCompareValues, backgroundColor: ['#0f766e', '#2563eb', '#b7791f', '#be123c'] }] },
                options: timeChartOptions
            });
        }
    });
</script>
</body>
</html>
