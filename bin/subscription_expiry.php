<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
$db=saas_db();
$q=$db->query("UPDATE organization_subscriptions SET status='PAST_DUE' WHERE status IN ('ACTIVE','TRIALING') AND current_period_end IS NOT NULL AND current_period_end < NOW()");
echo "Expired subscriptions marked PAST_DUE: ".$q->rowCount()."\n";
