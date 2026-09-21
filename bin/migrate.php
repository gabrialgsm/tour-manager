#!/usr/bin/env php
<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require __DIR__.'/../bootstrap.php';

$db=saas_db();
$dir=realpath(__DIR__.'/../database/migrations');
if($dir===false)throw new RuntimeException('Migration directory not found.');
$files=glob($dir.'/*.sql')?:[];
natcasesort($files);

$db->exec("CREATE TABLE IF NOT EXISTS schema_migrations (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, version VARCHAR(120) NOT NULL, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, execution_ms INT UNSIGNED NULL, PRIMARY KEY(id), UNIQUE KEY uq_schema_migrations_version(version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$applied=[];
foreach($db->query('SELECT version,checksum FROM schema_migrations ORDER BY id') as $row){$applied[(string)$row['version']]=(string)$row['checksum'];}

$baselineVersion=null;
foreach($argv as $arg){if(str_starts_with($arg,'--baseline-until='))$baselineVersion=substr($arg,17);}
if($baselineVersion!==null&&$baselineVersion==='')throw new RuntimeException('Invalid --baseline-until value.');
if($baselineVersion!==null){
    $found=false;
    foreach($files as $file){
        $version=basename($file,'.sql');
        $checksum=hash_file('sha256',$file);
        if($version===$baselineVersion)$found=true;
        if(isset($applied[$version])){
            if(!hash_equals($applied[$version],$checksum))throw new RuntimeException("Migration checksum mismatch: {$version}");
            continue;
        }
        if($found&&$version!==$baselineVersion)continue;
        $q=$db->prepare('INSERT INTO schema_migrations(version,checksum,execution_ms) VALUES(?,?,NULL)');
        $q->execute([$version,$checksum]);
        $applied[$version]=$checksum;
        echo "Baselined {$version}.\n";
        if($version===$baselineVersion)break;
    }
    if(!$found)throw new RuntimeException("Baseline migration not found: {$baselineVersion}");
    echo "Baseline completed through {$baselineVersion}. Run without --baseline-until for pending migrations.\n";
    exit(0);
}

$pending=[];
foreach($files as $file){
    $version=basename($file,'.sql');$checksum=hash_file('sha256',$file);
    if(isset($applied[$version])){if(!hash_equals($applied[$version],$checksum))throw new RuntimeException("Migration checksum mismatch: {$version}");continue;}
    $pending[]=['version'=>$version,'checksum'=>$checksum,'file'=>$file];
}
if(!$pending){echo "No pending migrations.\n";exit(0);}

echo 'Pending migrations: '.count($pending)."\n";
foreach($pending as $migration){
    $sql=(string)file_get_contents($migration['file']);
    if(trim($sql)==='')continue;
    $started=microtime(true);echo "Applying {$migration['version']}...\n";
    try{
        $db->exec($sql);
        $ms=(int)round((microtime(true)-$started)*1000);
        $q=$db->prepare('INSERT INTO schema_migrations(version,checksum,execution_ms) VALUES(?,?,?)');
        $q->execute([$migration['version'],$migration['checksum'],$ms]);
        echo "Applied {$migration['version']} ({$ms} ms).\n";
    }catch(Throwable $e){throw new RuntimeException("Migration failed: {$migration['version']}: ".$e->getMessage(),0,$e);}
}
echo "Migration run completed.\n";
