BEANSTALK
---- 轻量级 beanstalkd 的 php 客户端

说明
--------
本库可直接操作beanstalkd[1]接口，且支持完整的Beanstalk协议。
本库是基于 http://github.com/davidpersson/beanstalk 二次开发。

本库修正了有关网络的部分问题，并完善了单元测试，增加了网络相关的测试脚本。
1. 增加异常类 BeanstalkException
2. 增加了 stream_timeout 参数，可设置数据流的网络超时时间，以解决网络异常时，客户端僵死问题
3. 增加 force_reserve_timeout 参数，强制调用 reserve 时，设置超时时间，默认 1 秒，以解决使用者不注意时，造成的死循环问题
4. 增加网络异常处理，发送数据时，数据长度与发送成功长度不一致时，将抛出异常
5. 增加网络异常处理，接收数据时，连续（默认10次）接收到空数据，将抛出异常
6. 增加日志，通过 trigger_error 产生，对应的错误级别为：E_USER_WARNING
7. 增加 setMaxNetError 方法，设置允许的网络连续错误次数
8. 增加 lastError 方法，用于获取最后一次错误信信息
9. 有关网络问题的测试 请看 batch.md

[1] http://kr.github.com/beanstalkd

Copyright & License
-------------------
Beanstalk, 轻量级 beanstalkd 的 php 客户端, 版权所有 2018- 菁武.
代码遵守 MIT 协议, 见 LICENSE 文件。

Versions & Requirements
-----------------------
0.1.6, PHP >=5.4.0 (in progress)

Usage
-----
Add ``jingwu/phpbeanstalk`` as a dependency in your project's ``composer.json`` file (change version to suit your version of Elasticsearch):
```json
    {
        "require": {
            "jingwu/phpbeanstalk": "0.1.6"
        }
    }
```

```
<?php
require 'vendor/autoload.php';
use Jingwu\PhpBeanstalk\Client;

$cfg = [
    'persistent' => true, 
    'host' => '127.0.0.1', 
    'port' => 11300, 
    'connect_timeout' => 1,         //连接超时设置
    'stream_timeout' => 1,          //数据流超时设置
    'force_reserve_timeout' => 1,   //强制 reserve 设置超时，默认1秒
];
$client = new Client($cfg);
$tube = 'flux';

//队列生产者示例
$client->connect();
$client->useTube($tube);
$client->put(
    23,                         // 设置任务优先级23Give the job a priority of 23.
    0,                          // 不等待，直接发送任务到ready队列
    60,                         // 设置任务1分钟的执行时间
    '/path/to/cat-image.png'    // 任务的内容
);
$client->disconnect();

//队列消费者示例
$client = new Client();
$client->connect();
$client->watch($tube);

while(true) {
    $job = $client->reserve();  // 阻塞，直到有可用的任务
    // 网络原因，会导致取不到可用的任务，而返回false
    // 极个别情况下会形成死循环
    if($job === false) {
        sleep(1);
        continue;
    }
    // $job 实例如下：
    // array('id' => 123, 'body' => '/path/to/cat-image.png')

    // 设置任务执行中
    $result = touch($job['body']);

    if($result) {
        $client->delete($job['id']);
    } else {
        $client->bury($job['id']);
    }
}

// 断开连接
// $client->disconnect();

?>
```

单元测试
-----------------
此库中包括单元测试， 你需要先启动 beanstalkd 实例

$ beanstalkd -VV -l 127.0.0.1 -p 11300

执行如下命令，运行单元测试：

$ cd /path/to/beanstalk/src
$ phpunit -c ../phpunit.xml

[1] http://www.phpunit.de/manual/current/en/installation.html
