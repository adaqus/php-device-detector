<?php
require __DIR__ . '/vendor/autoload.php';

use DeviceDetector\DeviceDetector;
use DeviceDetector\Cache\DoctrineBridge;
use Doctrine\Common\Cache\PhpFileCache;
use OpenSwoole\Http\Server;
use OpenSwoole\Table;

// ————— File-based regex cache setup —————
$cacheDir = __DIR__ . '/cache/regex';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0777, true);
}
// Doctrine’s PhpFileCache will persist compiled regexes to PHP files
$doctrineCache = new PhpFileCache($cacheDir);
// Wrap in the DeviceDetector bridge
$regexCache = new DoctrineBridge($doctrineCache);

// ————— Instantiate once, before any requests —————
$dd = new DeviceDetector();
$dd->setCache($regexCache);

// ————— Swoole response cache (unchanged) —————
$cacheTable = new Table(1024);
$cacheTable->column('response', Table::TYPE_STRING, 2048);
$cacheTable->column('timestamp',  Table::TYPE_INT,    4);
$cacheTable->create();
$ttl = 300; // seconds

$server = new Server("0.0.0.0", 9501);
$server->on("request", function ($req, $res) use ($dd, $cacheTable, $ttl) {
    $ua = $req->get['ua'] ?? null;
    if (!$ua) {
        $res->status(400);
        return $res->end('Missing "ua" query parameter');
    }

    //error_log("UA: $ua", 0);

    $key = md5($ua);
    $entry = $cacheTable->get($key);
    if ($entry !== false && (time() - $entry['timestamp']) < $ttl) {
        $json = $entry['response'];
    } else {
        $dd->setUserAgent($ua);
        $dd->parse();
        $result = [
            'client'      => $dd->getClient() ?: null,
            'os'          => $dd->getOs() ?: null,
            'device_type' => $dd->getDeviceName() ?: null,
            'brand'       => $dd->getBrandName() ?: null,
            'model'       => $dd->getModel() ?: null,
        ];
        $json = json_encode($result);
        $cacheTable->set($key, [
            'response'  => $json,
            'timestamp' => time(),
        ]);
    }

    $res->header('Content-Type', 'application/json');
    $res->end($json);
});

$server->start();
