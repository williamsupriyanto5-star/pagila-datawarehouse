<?php

include("../config/db.php");

$sql="

SELECT

CASE

WHEN churn_risk_score < 30 THEN 'Low'

WHEN churn_risk_score < 70 THEN 'Medium'

ELSE 'High'

END risk,

COUNT(*) total

FROM fact_customer_activity

GROUP BY risk

ORDER BY risk

";

$result=pg_query($conn,$sql);

$data=[];

while($row=pg_fetch_assoc($result)){

$data[]=$row;

}

header("Content-Type:application/json");

echo json_encode($data);

?>