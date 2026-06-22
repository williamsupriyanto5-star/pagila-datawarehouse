<?php

include("../config/db.php");

$sql="

SELECT

s.store_key,

SUM(f.amount) revenue

FROM fact_sales f

JOIN dim_store s

ON f.store_key=s.store_key

GROUP BY s.store_key

ORDER BY revenue DESC

";

$result=pg_query($conn,$sql);

$data=[];

while($row=pg_fetch_assoc($result)){

$data[]=$row;

}

header('Content-Type: application/json');

echo json_encode($data);

?>