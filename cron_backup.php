<?php
// CLI-only automatic backup. Add a daily cron job:
// 0 3 * * * /usr/bin/php /path/to/gmjs/cron_backup.php
if(PHP_SAPI!=='cli'){http_response_code(403);exit("CLI only\n");}
require __DIR__.'/bootstrap.php';
global $config; $pdo=db();
$dir=__DIR__.'/backups'; if(!is_dir($dir)) mkdir($dir,0750,true);
$tables=$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$out="-- GMJS automatic backup\n-- Created: ".date('Y-m-d H:i:s')."\nSET FOREIGN_KEY_CHECKS=0;\n\n";
foreach($tables as $table){
    $safe=str_replace('`','``',$table);
    $out.="DROP TABLE IF EXISTS `{$safe}`;\n";
    $create=$pdo->query("SHOW CREATE TABLE `{$safe}`")->fetch();
    $out.=$create['Create Table'].";\n\n";
    $rows=$pdo->query("SELECT * FROM `{$safe}`")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $row){
        $cols=array_map(fn($c)=>"`".str_replace('`','``',$c)."`",array_keys($row));
        $vals=[]; foreach($row as $v)$vals[]=$v===null?'NULL':$pdo->quote((string)$v);
        $out.="INSERT INTO `{$safe}` (".implode(',',$cols).") VALUES (".implode(',',$vals).");\n";
    }
    $out.="\n";
}
$out.="SET FOREIGN_KEY_CHECKS=1;\n";
$file=$dir.'/gmjs_'.date('Y-m-d_H-i-s').'.sql';
file_put_contents($file,$out,LOCK_EX);
foreach(glob($dir.'/gmjs_*.sql') as $f) if(filemtime($f)<time()-14*86400) @unlink($f);
audit('AUTO_DB_BACKUP',null,null,basename($file));
echo "Backup created: {$file}\n";