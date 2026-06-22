<?php

include("../config/db.php");

$sql="

SELECT

f.title,

SUM(fp.rental_revenue) revenue

FROM fact_film_performance fp

JOIN dim_film f

ON fp.film_key=f.film_key

GROUP BY f.title

ORDER BY revenue DESC

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