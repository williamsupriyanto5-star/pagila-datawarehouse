<?php

include("../config/db.php");

$sql = "

SELECT

COALESCE(d.month_name,'Unknown') AS bulan,

SUM(f.amount) AS revenue

FROM fact_sales f

JOIN dim_date d

ON f.date_key = d.date_key

GROUP BY d.month,d.month_name

ORDER BY d.month

";

$result = pg_query($conn,$sql);

$data = [];

while($row=pg_fetch_assoc($result)){

    $data[]=$row;

}

header('Content-Type: application/json');

echo json_encode($data);

?>