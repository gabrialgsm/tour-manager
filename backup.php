<?php
require __DIR__.'/bootstrap.php';require __DIR__.'/bootstrap_auth.php'; require_login(); require_permission('backup');
if(($_GET['download']??'')!=='1') {
?><!doctype html><html><head><?php include __DIR__.'/partials/head.php';?></head><body><?php include __DIR__.'/partials/nav.php';?><main class="wrap"><section class="card">
<h2>Database Backup</h2><p class="muted">Creates a complete SQL dump of the database. No application data is changed.</p>
<a class="btn primary" href="backup.php?download=1">⬇ Download SQL Backup</a>
</section></main></body></html><?php exit; }

global $config; $d=$config['db']; $pdo=db();
$tables=$pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$out="-- GMJS database backup\n-- Created: ".date('Y-m-d H:i:s')."\nSET FOREIGN_KEY_CHECKS=0;\n\n";
foreach($tables as $table){
    $out.="DROP TABLE IF EXISTS `".str_replace('`','``',$table)."`;\n";
    $create=$pdo->query("SHOW CREATE TABLE `".str_replace('`','``',$table)."`")->fetch();
    $out.=$create['Create Table'].";\n\n";
    $rows=$pdo->query("SELECT * FROM `".str_replace('`','``',$table)."`")->fetchAll(PDO::FETCH_ASSOC);
    foreach($rows as $row){
        $cols=array_map(fn($c)=>"`".str_replace('`','``',$c)."`",array_keys($row));
        $vals=[];
        foreach($row as $v){
            $vals[]=$v===null?'NULL':$pdo->quote((string)$v);
        }
        $out.="INSERT INTO `".str_replace('`','``',$table)."` (".implode(',',$cols).") VALUES (".implode(',',$vals).");\n";
    }
    $out.="\n";
}
$out.="SET FOREIGN_KEY_CHECKS=1;\n";
audit('DOWNLOAD_DB_BACKUP');
header('Content-Type: application/sql; charset=utf-8');
header('Content-Disposition: attachment; filename="gmjs_backup_'.date('Y-m-d_H-i-s').'.sql"');
header('Content-Length: '.strlen($out));
echo $out;