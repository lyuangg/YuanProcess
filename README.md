# YuanProcess
YuanProcess 是一个简单的PHP多进程类，采用 Master + Worker 模式。Master 进程监控 Worker 进程，Worder进程处理任务。



## 用法

- **引入 YuanProcess 类或者复制代码** 

```php
include 'YuanProcess.php'
```

- **设置选项**

```php
$options = [
  'pid' => '/tmp/test.pid',   //pid 文件路径，默认 /tmp 目录
  'log' => '/tmp/test.log',  //日志文件路径，默认/tmp 目录下
  'name' => 'YuanProcess', //进程名，默认类名
  'is_daemon' => true,  //是否作为守护进程运行，默认 true
  'user' => 'www',    //进程用户，默认执行命令的用户
  'tasks_interval' =>2,  //自动拉起进程时间，默认2秒，需要大于0
  'enable_log' => true, //是否启用日志
];

$process = new YuanProcess($options);
/*
OR

$process = new YuanProcess();
$process->setOptions($options);

OR

$process->setOption('pid','/tmp/test.pid');
*/
```

- **添加任务**

```php
$process->addTask(function($process, $params){
    while(true) {
        echo date("Y-m-d H:i:s").' this is task 1'.PHP_EOL;
        sleep(5);
        $process->taskNeedStop(); //子进程手动响应信号退出，为了更好的性能须手动调用。
    }
},[], true);
```

addTask 方法：

```php
/*
	$func callable 执行的任务回调方法
	$params array 方法参数
	$autoUp bool 任务结束时，是否自动拉起任务，默认false
	$processName strig 任务进程名字 
*/
function addTask($func, $params=[], $autoUp=false,  $processName='')
```

addTask 回调方法： 

```php
/*
	$process object 进程对象
	$params array 参数
*/
function($process, $params) {
}
```

- **执行任务**

```php
$proecss->run();
```



- **一个完整示例**

文件：test.php

```php
<?php
include 'YuanProcess.php'
$options = [
  'pid' => '/tmp/test.pid',   
  'log' => '/tmp/test.log',  
  'name' => 'YuanProcess', 
];
$process = new YuanProcess($options);
for($i=0;$i<2;$i++) {
    $process->addTask(function($process, $params) use ($i) {
        while(true) {
            echo date("Y-m-d H:i:s").' this is task '.$i.PHP_EOL;
            sleep(5);
            $process->taskNeedStop(); 
        }
    });
}

if($argv && isset($argv[1])) {
    $cmd = trim($argv[1]);
    if($cmd == 'start') {
        $process->run();
    } else {
        $process->stop();
        echo 'stop done!'.PHP_EOL;
    }
}
```

运行

```shell
php test.php start
```

停止

```shell
php test.php stop
```