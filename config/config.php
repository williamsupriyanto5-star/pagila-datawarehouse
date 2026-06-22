<?php

require_once(__DIR__ . "/config/db.php");

include("../includes/header.php");

include("../includes/sidebar.php");

$totalRevenue = pg_fetch_result(

pg_query($conn,

"SELECT COALESCE(SUM(amount),0)

FROM fact_sales"),

0,0);

$totalRental = pg_fetch_result(

pg_query($conn,

"SELECT COUNT(*) FROM fact_rental"),

0,0);

$totalCustomer = pg_fetch_result(

pg_query($conn,

"SELECT COUNT(DISTINCT customer_key)

FROM fact_sales"),

0,0);

$avgTransaction = pg_fetch_result(

pg_query($conn,

"SELECT ROUND(AVG(amount),2)

FROM fact_sales"),

0,0);

?>

<div class="content">

<h2>

Movie Rental Dashboard

</h2>

<div class="row">

<div class="col-md-3">

<div class="card-dashboard">

Revenue

<h2>

Rp <?=number_format($totalRevenue)?>

</h2>

</div>

</div>

<div class="col-md-3">

<div class="card-dashboard">

Rental

<h2>

<?=$totalRental?>

</h2>

</div>

</div>

<div class="col-md-3">

<div class="card-dashboard">

Customer

<h2>

<?=$totalCustomer?>

</h2>

</div>

</div>

<div class="col-md-3">

<div class="card-dashboard">

Avg Transaction

<h2>

<?=$avgTransaction?>

</h2>

</div>

</div>

</div>

<div class="row">

<div class="col-md-8">

<div class="chart-box">

<canvas id="revenueChart">

</canvas>

</div>

</div>

<div class="col-md-4">

<div class="chart-box">

<canvas id="pieChart">

</canvas>

</div>

</div>

</div>

</div>

<script src="../assets/js/dashboard.js">

</script>

</body>

</html>