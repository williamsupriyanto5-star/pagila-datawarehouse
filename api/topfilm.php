<?php

include("../config/db.php");

$sql = "

SELECT

    df.title,

    SUM(fp.rental_revenue) revenue

FROM fact_film_performance fp

JOIN dim_film df

ON fp.film_key=df.film_key

GROUP BY df.title

ORDER BY revenue DESC

LIMIT 10

";

$result=pg_query($conn,$sql);

$data=[];

while($row=pg_fetch_assoc($result)){

    $data[]=$row;

}

header("Content-Type: application/json");

echo json_encode($data);

?>