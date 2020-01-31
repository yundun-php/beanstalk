<?php
error_reporting(E_ALL);
//error_reporting(E_ALL ^ E_WARNING ^ E_USER_WARNING ^ E_USER_NOTICE);
ini_set('display_errors', 'On');
//require './src/phpbeanstalk/Client.php';
require './vendor/autoload.php';
use Jingwu\PhpBeanstalk\Client;

//网络连通及不通
//阻断网络：iptables -A OUTPUT -p tcp --sport 11300 -j DROP
//连通网络：iptables -D OUTPUT -p tcp --sport 11300 -j DROP
function test_conn($cfg, $tube, $timeout, $i = 0) {
    $cfg['timeout'] = $timeout;
    $bs = new Client($cfg);
    var_dump("已经连接服务器");
    $result = $bs->useTube($tube);
    var_dump($result);
    var_dump("lastError: ");
    var_dump($bs->lastError());
    $body = str_repeat('a', 65520);
    while(++$i) {
        $jobid = $bs->put(0, 0, 30, $body);
        var_dump("{$i}\t{$jobid}");
        sleep(1);
    }
    $bs->disconnect();
}

//连续写入文本消息
function test_put($cfg, $tube, $len, $i = 0) {
    var_dump($cfg);
    $bs = new Client($cfg);
    $bs->useTube($tube);
    $body = str_repeat('a', $len);
    while(++$i) {
        $jobid = $bs->put(0, 0, 30, $body);
        var_dump("{$i}\t{$jobid}");
    }
    $bs->disconnect();
}

function test_del($cfg, $tube, $i = 0) {
    $bs = new Client($cfg);
    $bs->watch($tube);
    while(++$i) {
        $job = $bs->reserve(0);
        var_dump($job);
        $result = $bs->delete($job['id']);
        var_dump("{$i}\t{$result}");
    }
}

function test_reserve($cfg, $tube, $i = 0) {
    $cfg['timeout'] = 0;
    $bs = new Client($cfg);
    $bs->watch($tube);
    while(++$i) {
        $job = $bs->reserve(0);
        var_dump($job);
        $result = $bs->delete($job['id']);
        var_dump("{$i}\t{$result}");
    }
    $bs->disconnect();
}

//测试间隔写入，每次间隔2秒，1000个短消息
function test_sleepPut($cfg, $tube, $msgCount, $msgLen, $i = 0) {
    while(++$i) {
        $start = microtime(1);
        $bs = new Client($cfg);
        $bs->useTube($tube);
        for($j = 0; $j < $msgCount; $j++) {
            $jobid = $bs->put(0, 0, 30, str_repeat('a', $msgLen));
        }
        $bs->disconnect();
        $end = microtime(1);
        var_dump("testput\t{$msgCount}\t{$i}\t{$start}\t{$end}\t".($end-$start));
        sleep(2);
    }
}

$cfg = ['persistent' => true, 'host' => '127.0.0.1', 'port' => 11300, 'connect_timeout' => 1, 'stream_timeout' => 1, 'force_reserve_timeout' => 1];
$act = isset($argv[1]) ? $argv[1] : '';
$acts = [
    'conn_timeout0'                     => ['test_conn',     [array_merge($cfg, ['connect_timeout' => 0])                               , 'tester', 0]         ], 
    'conn_timeout1'                     => ['test_conn',     [$cfg                                                                      , 'tester', 1]         ], 
    'streamTimeout-1_putBig'            => ['test_put',      [array_merge($cfg, ['stream_timeout' =>-1])                                , 'tester', 65520]     ], 
    'streamTimeout-1_reserve_timeout0'  => ['test_reserve',  [array_merge($cfg, ['stream_timeout' =>-1, 'force_reserve_timeout' => 0])  , 'tester', 0]         ], 
    'streamTimeout-1_reserve_timeout1'  => ['test_reserve',  [array_merge($cfg, ['stream_timeout' =>-1, 'force_reserve_timeout' => 0])  , 'tester', 1]         ], 
    'streamTimeout0_putBig'             => ['test_put',      [array_merge($cfg, ['stream_timeout' => 0])                                , 'tester', 65520]     ], 
    'streamTimeout1_putBig'             => ['test_put',      [array_merge($cfg, ['stream_timeout' => 1])                                , 'tester', 65520]     ], 
    'streamTimeout1_reserve_timeout0'   => ['test_reserve',  [array_merge($cfg, ['stream_timeout' =>1, 'force_reserve_timeout' => 0])  , 'tester', 0]         ], 
    'streamTimeout1_reserve_timeout1'   => ['test_reserve',  [array_merge($cfg, ['stream_timeout' =>1, 'force_reserve_timeout' => 0])  , 'tester', 1]         ], 
    'del'                               => ['test_del',      [$cfg                                                                      , 'tester']            ], 
    'sleepPut2_100'                     => ['test_sleepPut', [$cfg                                                                      , 'tester', 100,  300] ],
    'sleepPut2_1000'                    => ['test_sleepPut', [$cfg                                                                      , 'tester', 1000, 300] ],
];
if(!isset($acts[$act])) exit("可用的动作有：\r\n".implode(array_keys($acts), "\r\n")."\n");
call_user_func_array($acts[$act][0], $acts[$act][1]);

