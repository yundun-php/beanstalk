### beanstalk 网络问题及验证

#### 1. 使用问题

* 1.日志不全
* 2.僵死
* 3.死循环(reserve)
* 4.响应慢

#### 2. 网络超时设置

* timeout 建立连接时，超时设置，默认超时时间，不会僵死
* stream_timeout 发送数据时超时设置, -1长连接，会僵死尸
* reserve 命令，执行时，如果未设置超时，会一直等待，网络异常时会列循环

#### 3. 测试场景

##### 3.1 beanstalkd 未启动
```
前置条件：
    beanstalkd 未启动
```
###### 3.1.1 服务拒绝 
```
执行命令：
    php test/batch.php conn_timeout0
执行结果：
    Beanstalk 报错：Beanstalk 111: Connection refused
原因分析：
    服务未启动，网络拒绝
```

##### 3.2 beanstalkd 启动, 但网络不通
```
前置条件：
    beanstalkd 启动: beanstalkd -F -l 127.0.0.1 -p 11300
    封掉端口：iptables -A OUTPUT -p tcp --sport 11300 -j DROP
```
###### 3.2.1 连接超时
```
执行命令：
    php test/batch.php conn_timeout0
执行结果：
    Beanstalk 报错
    Beanstalk 110: Connection timed out
原因分析：
    因网络不通，等待服务响应超时，因未设置超时限制，故使用默认的超时时间
恢复环境：
    解封端口：iptables -D OUTPUT -p tcp --sport 11300 -j DROP
```

##### 3.3 beanstalkd 启动, 数据流超时不限制，断开网络，程序僵死
```
前置条件：
    beanstalkd 启动: beanstalkd -F -l 127.0.0.1 -p 11300
    stream_timeout 设置为 -1
```

###### 3.3.1 调用put命令，中途用iptables封掉端口，程序僵死
```
执行命令：
    php test/batch.php streamTimeout-1_putBig
    封掉端口：iptables -A OUTPUT -p tcp --sport 11300 -j DROP
执行结果：
    程序僵死
原因分析：
    因设置网络流超时为不限制，故客户端会一直等待，造成程序僵死现象
恢复环境：
    解封端口：iptables -D OUTPUT -p tcp --sport 11300 -j DROP
```

###### 3.3.2 调用reserve命令，并且不设置起时参数，中途用iptables封掉端口，程序僵死
```
执行命令：
    php test/batch.php streamTimeout-1_reserve_timeout0
    封掉端口：iptables -A OUTPUT -p tcp --sport 11300 -j DROP
执行结果：
    程序僵死
原因分析：
    因设置网络流超时为不限制，故客户端会一直等待，造成程序僵死现象
恢复环境：
    解封端口：iptables -D OUTPUT -p tcp --sport 11300 -j DROP
```

###### 3.3.3 调用reserve命令，并设置起时参数为1，中途用iptables封掉端口，程序僵死
```
执行命令：
    php test/batch.php streamTimeout-1_reserve_timeout1
    封掉端口：iptables -A OUTPUT -p tcp --sport 11300 -j DROP
执行结果：
    程序僵死
原因分析：
    因设置网络流超时为不限制，故客户端会一直等待，造成程序僵死现象
    stream_timeout为不限制时，reserve的timeout设置无效
恢复环境：
    解封端口：iptables -D OUTPUT -p tcp --sport 11300 -j DROP
```

##### 3.4 beanstalkd 启动, 数据流超时限制为0，程序异常几率大大增加
```
前置条件：
    beanstalkd 启动: beanstalkd -F -l 127.0.0.1 -p 11300
    stream_timeout 设置为 0
```

###### 3.4.1 调用put命令
```
执行命令：
    php test/batch.php streamTimeout0_putBig
执行结果：
    多次调用put命令，有成功有失败，失败几率大大增长
原因分析：
    stream_timeout 设置为 0 时，客户端不等待服务端的响应，立即返回，故失败几率大增
恢复环境：
    无
```

##### 3.5 beanstalkd 启动, 数据流超时限制为1，网络起时返回false, 并记录日志
```
前置条件：
    beanstalkd 启动: beanstalkd -F -l 127.0.0.1 -p 11300
    stream_timeout 设置为 1
```

###### 3.5.1 调用put命令，中途用iptables封掉端口，程序按设定的超时时间返回false, 并记录日志
```
执行命令：
    php test/batch.php streamTimeout1_putBig
    封掉端口：iptables -A OUTPUT -p tcp --sport 11300 -j DROP
执行结果：
    程序按设定的超时时间返回false，并记录日志
    Warning: Beanstalk cmd[put] status error: false
原因分析：
    因设置网络流超时为1，在网络阻断后，客户端在限定的时间内自动处理, 返回空
    在新版的客户端中，封装了日志处理，所以能看到错误信息
恢复环境：
    解封端口：iptables -D OUTPUT -p tcp --sport 11300 -j DROP
```

###### 3.5.2 调用reserve命令，并设置超时参数为0，中途用iptables封掉端口，程序按设定的超时时间返回false, 并记录日志
```
执行命令：
    php test/batch.php streamTimeout1_reserve_timeout0
    封掉端口：iptables -A OUTPUT -p tcp --sport 11300 -j DROP
执行结果：
    程序按设定的超时时间返回false, 并记录日志
    Warning: Beanstalk cmd[delete] status error: false
原因分析：
    因设置网络流超时为1，在网络阻断后，客户端在限定的时间内自动处理, 返回空
    在新版的客户端中，封装了日志处理，所以能看到错误信息
    如果业务中没有处理，会形成死循环
恢复环境：
    解封端口：iptables -D OUTPUT -p tcp --sport 11300 -j DROP
```

###### 3.5.3 调用reserve命令，并设置超时参数为1，中途用iptables封掉端口，程序按设定的超时时间返回false, 并记录日志
```
执行命令：
    php test/batch.php streamTimeout1_reserve_timeout1
    封掉端口：iptables -A OUTPUT -p tcp --sport 11300 -j DROP
执行结果：
    程序按设定的超时时间返回false, 并记录日志
    Warning: Beanstalk cmd[delete] status error: false
原因分析：
    因设置网络流超时为1，在网络阻断后，客户端在限定的时间内自动处理, 返回空
    在新版的客户端中，封装了日志处理，所以能看到错误信息
    如果业务中没有处理，会形成死循环
恢复环境：
    解封端口：iptables -D OUTPUT -p tcp --sport 11300 -j DROP
```

##### 3.6 beanstalkd 启动, 数据流超时限制为1，网络不限制
```
前置条件：
    beanstalkd 启动: beanstalkd -F -l 127.0.0.1 -p 11300
    stream_timeout 设置为 1
```

###### 3.6.1 调用reserve命令，并设置超时参数为1，中途用iptables封掉端口，程序按设定的超时时间返回false, 并记录日志
```
执行命令(同时启动)：
    php test/batch.php streamTimeout1_putBig
    php test/batch.php sleepPut2_100
执行结果：
    查看 sleepPut2_100 的输出，每次执行的时间会越来越长
原因分析：
    因 beanstalkd 启动时设置了队列落地，故大量的队列堆积时，会导致beanstalkd的IO操作增加，
    处理请求的能力降低，所以客户端执行时间会越来越长
恢复环境：
    无
```
