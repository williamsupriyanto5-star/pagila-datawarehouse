<?php

include("../config/db.php");

$sql = "

SELECT

    COALESCE((SELECT SUM(amount) FROM fact_sales),0) revenue,

    COALESCE((SELECT COUNT(*) FROM fact_rental),0) rental,

    COALESCE((SELECT COUNT(DISTINCT customer_key) FROM fact_sales),0) customer,

    COALESCE((SELECT ROUND(AVG(amount),2) FROM fact_sales),0) avg_transaction

";

$result = pg_query($conn,$sql);

$data = pg_fetch_assoc($result);

header("Content-Type: application/json");

echo json_encode($data);

?>