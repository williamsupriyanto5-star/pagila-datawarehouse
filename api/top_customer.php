<?php

include("../config/db.php");

$sql="

SELECT

fa.customer_key,

MAX(fa.customer_lifetime_value) AS customer_lifetime_value,

MAX(ct.city) AS city

FROM fact_customer_activity fa

LEFT JOIN stg_city ct
ON ct.city_id = fa.geography_key

GROUP BY fa.customer_key

ORDER BY customer_lifetime_value DESC

LIMIT 10

";

$result=pg_query($conn,$sql);

$data=[];

while($row=pg_fetch_assoc($result)){

$data[]=$row;

}

header("Content-Type:application/json");

echo json_encode($data);

?>