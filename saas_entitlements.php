<?php
declare(strict_types=1);

/**
 * Central SaaS billing/entitlement service.
 * No feature should inspect plan names directly; use saas_entitlement().
 */

function saas_plan(PDO $db, int $organizationId): array
{
    static $cache = [];
    if (isset($cache[$organizationId])) return $cache[$organizationId];
    $q = $db->prepare("SELECT sp.id,sp.plan_code,sp.name,sp.description,sp.monthly_price,sp.yearly_price,sp.currency,
                              COALESCE(os.status,'ACTIVE') subscription_status,os.current_period_end,os.trial_ends_at
                       FROM saas_plans sp
                       LEFT JOIN organization_subscriptions os ON os.plan_id=sp.id AND os.organization_id=?
                       WHERE sp.plan_code=COALESCE((SELECT sp2.plan_code FROM organization_subscriptions os2
                           JOIN saas_plans sp2 ON sp2.id=os2.plan_id
                           WHERE os2.organization_id=? LIMIT 1),'FREE')
                       LIMIT 1");
    $q->execute([$organizationId,$organizationId]);
    $row = $q->fetch();
    if (!$row) {
        $q = $db->prepare("SELECT id,plan_code,name,description,monthly_price,yearly_price,currency,'ACTIVE' subscription_status,NULL current_period_end,NULL trial_ends_at FROM saas_plans WHERE plan_code='FREE' LIMIT 1");
        $q->execute();
        $row = $q->fetch() ?: ['id'=>0,'plan_code'=>'FREE','name'=>'Free','description'=>'','monthly_price'=>0,'yearly_price'=>0,'currency'=>'BDT','subscription_status'=>'ACTIVE','current_period_end'=>null,'trial_ends_at'=>null];
    }
    return $cache[$organizationId] = $row;
}

function saas_entitlement(int $organizationId, string $key, mixed $default = null): mixed
{
    static $cache = [];
    $cacheKey = $organizationId . ':' . $key;
    if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];
    $db = saas_db();
    $plan = saas_plan($db, $organizationId);
    $q = $db->prepare("SELECT entitlement_value FROM saas_plan_entitlements WHERE plan_id=? AND entitlement_key=? LIMIT 1");
    $q->execute([(int)$plan['id'],$key]);
    $value = $q->fetchColumn();
    if ($value === false) return $cache[$cacheKey] = $default;
    $value = (string)$value;
    if ($value === '0' || $value === '1') return $cache[$cacheKey] = ($value === '1');
    if (preg_match('/^-?[0-9]+$/', $value)) return $cache[$cacheKey] = (int)$value;
    if (preg_match('/^-?[0-9]+\.[0-9]+$/', $value)) return $cache[$cacheKey] = (float)$value;
    return $cache[$cacheKey] = $value;
}

function saas_has_entitlement(int $organizationId, string $key): bool
{
    return (bool)saas_entitlement($organizationId, $key, false);
}

function saas_limit_allows(int $organizationId, string $key, int $currentCount): bool
{
    $limit = saas_entitlement($organizationId, $key, -1);
    return (int)$limit < 0 || $currentCount < (int)$limit;
}

function saas_require_entitlement(int $organizationId, string $key, string $message = 'This feature is not available on your current plan.'): void
{
    if (!saas_has_entitlement($organizationId, $key)) {
        http_response_code(403);
        exit(saas_h($message));
    }
}

function saas_require_limit(int $organizationId, string $key, int $currentCount, string $message): void
{
    if (!saas_limit_allows($organizationId, $key, $currentCount)) {
        http_response_code(403);
        exit(saas_h($message));
    }
}

function saas_billing_usage(int $organizationId): array
{
    $db = saas_db();
    $usage = ['tours'=>0,'members'=>0,'passengers'=>0];
    $q = $db->prepare('SELECT COUNT(*) FROM tours WHERE organization_id=?');
    $q->execute([$organizationId]); $usage['tours']=(int)$q->fetchColumn();
    $q = $db->prepare("SELECT COUNT(*) FROM organization_members WHERE organization_id=? AND status='ACTIVE'");
    $q->execute([$organizationId]); $usage['members']=(int)$q->fetchColumn();
    $q = $db->prepare("SELECT COUNT(*) FROM tour_passengers tp JOIN tours t ON t.id=tp.tour_id WHERE t.organization_id=? AND tp.status<>'CANCELLED'");
    $q->execute([$organizationId]); $usage['passengers']=(int)$q->fetchColumn();
    return $usage;
}
