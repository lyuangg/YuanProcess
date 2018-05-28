<?php

class YuanProcess
{
    protected $pidFile = '';
    protected $logFile = '';
    protected $processName = '';
    protected $masterName = '';
    protected $terminate = false;
    protected $isDaemon = true;
    protected $tasks = [];
    protected $user = '';
    protected $tasksIntervalRun = 2;
    protected $enableLog = true;

    public function __construct($options = null)
    {
        $this->setOptions($options);
        if (empty($this->processName)) {
            $this->processName = __CLASS__;
        }
        if (empty($this->pidFile)) {
            $this->pidFile = '/tmp/' . $this->processName . '.pid';
        }
        if (empty($this->logFile)) {
            $this->logFile = '/tmp/' . $this->processName . '.log';
        }
    }
    public function setOption($option, $val)
    {
        switch ($option) {
            case 'pid':
                if (is_dir(dirname(strval($val)))) {
                    $this->pidFile = trim(strval($val));
                }
                break;
            case 'log':
                if (is_dir(dirname(strval($val)))) {
                    $this->logFile = trim(strval($val));
                }
                break;
            case 'name':
                $this->processName = trim(strval($val));
                break;
            case 'is_daemon':
                $this->isDaemon = $val ? true : false;
                break;
            case 'user':
                $this->user = trim(strval($val));
                break;
            case 'tasks_interval':
                $this->tasksIntervalRun = intval($val) > 0 ? intval($val) : $this->tasksIntervalRun;
                break;
            case 'enable_log':
                $this->enableLog = $val ? true : false;
                break;
            default:
                break;
        }
    }
    public function setOptions($options)
    {
        if ($options && is_array($options)) {
            foreach ($options as $option => $val) {
                $this->setOption($option, $val);
            }
        }
    }
    public function addTask($func, $params = [], $autoUp = false, $processName = '')
    {
        if (empty($processName)) {
            $processName = $this->processName . '_sub_' . (count($this->tasks) + 1);
        }
        $this->tasks[$processName] = ['func' => $func, 'params' => $params, 'auto_up' => $autoUp, 'name' => $processName, 'status' => 'ready', 'pid' => 0];
    }
    public function run()
    {
        $this->runMasterProcess();
        while (true) {
            if ($this->isTerminate()) {
                $this->close();
            } else {
                $this->runTasks();
                sleep($this->tasksIntervalRun);
            }
        }
        die;
    }
    public function runMasterProcess()
    {
        cli_set_process_title($this->processName);
        umask(0);
        if ($this->isDaemon) {
            if (pcntl_fork() != 0) {
                exit();
            }
            posix_setsid();
            if (pcntl_fork() != 0) {
                exit();
            }
            chdir("/");
            $this->setStd();
        }
        $this->setSignalHandler();
        $this->setUser();
        $this->setPidFile();
        $this->plog("start master process(" . $this->processName . ') pid(' . getmypid() . ')');
    }
    public function runTasks()
    {
        if ($this->tasks) {
            foreach ($this->tasks as $key => $task) {
                if ($task['pid'] > 0) {
                    continue;
                }
                $taskName = $task['name'];
                $taskFunc = $task['func'];
                $taskParams = $task['params'];
                $taskAutoUp = $task['auto_up'];
                $taskStatus = $task['status'];
                if ($taskStatus == 'ready' || $taskAutoUp) {
                    $pid = pcntl_fork();
                    if ($pid == 0) {
                        $pid = getmypid();
                        cli_set_process_title($taskName);
                        $this->processName = $taskName;
                        $this->plog("task($taskName) start($pid) runing");
                        $this->setSubSignalHander();
                        if (is_callable($taskFunc)) {
                            try {
                                call_user_func($taskFunc, $this, $taskParams);
                            } catch (\Exception $e) {
                                $this->plog("task($taskName) pid($pid) exception:" . $e->getMessage(), 'ERROR');
                            }
                        } else {
                            $this->plog("task($taskName) pid($pid) function is not callabled", 'ERROR');
                        }
                        exit(0);
                    } else if ($pid == -1) {
                        $this->plog("task($taskName) run error", 'ERROR');
                    } else {
                        $this->tasks[$key]['pid'] = $pid;
                        $this->tasks[$key]['status'] = 'runing';
                    }
                }
            }
        } else {
            $this->plog("task is empty", 'ERROR');
        }
    }
    public function taskNeedStop($func = null)
    {
        $ppid = posix_getppid();
        $taskName = $this->processName;
        if ($this->isTerminate() || $ppid == 0) {
            $pid = getmypid();
            if ($ppid == 0) {
                $this->plog("task($taskName) pid($pid) ppid is 0 exit");
            } else {
                $this->plog("task($taskName) pid($pid) is terminated");
            }
            if (is_callable($func)) {
                call_user_func($func);
            }
            exit(0);
        }
    }
    public function stop()
    {
        if ($this->pidFile && file_exists($this->pidFile)) {
            $content = file_get_contents($this->pidFile);
            $pid = intval($content);
            if (posix_kill($pid, 0)) {
                posix_kill($pid, SIGTERM);
                return true;
            }
        }
        return false;
    }
    protected function close()
    {
        $this->closeTask();
        $this->removePidFile();
        $this->plog($this->processName . " is closed");
        exit();
    }
    protected function closeTask()
    {
        if ($this->tasks) {
            foreach ($this->tasks as $key => $task) {
                if ($task['pid']) {
                    $this->tasks[$key]['auto_up'] = false;
                    posix_kill($task['pid'], SIGTERM);
                    $this->plog("close task($key) pid(" . $task['pid'] . ')');
                }
            }
        }
    }
    public function removePidFile()
    {
        if ($this->pidFile && file_exists($this->pidFile)) {
            unlink($this->pidFile);
        }
    }
    protected function setStd()
    {
        global $STDIN, $STDOUT, $STDERR;
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);
        $STDIN = fopen('/dev/null', 'r');
        $STDOUT = fopen($this->logFile, 'a');
        $STDERR = fopen($this->logFile, 'a');
    }
    protected function setSignalHandler()
    {
        pcntl_signal(SIGCHLD, array(__CLASS__, "signalHandler"), false);
        pcntl_signal(SIGTERM, array(__CLASS__, "signalHandler"), false);
        pcntl_signal(SIGUSR1, array(__CLASS__, "signalHandler"), false);
    }
    protected function setSubSignalHander()
    {
        pcntl_signal(SIGCHLD, SIG_IGN);
        pcntl_signal(SIGTERM, array(__CLASS__, "signalHandler"), false);
        pcntl_signal(SIGUSR1, array(__CLASS__, "signalHandler"), false);
    }
    protected function signalHandler($signo)
    {
        switch ($signo) {
            case SIGUSR1:
                break;
            case SIGCHLD:
                while (($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) {
                    $taskName = '';
                    foreach ($this->tasks as $key => $task) {
                        if ($pid == $task['pid']) {
                            $this->tasks[$key]['pid'] = 0;
                            $this->tasks[$key]['status'] = 'exit';
                            $taskName = $task['name'];
                            break;
                        }
                    }
                    $this->plog("task($taskName) pid($pid) exit");
                }
                break;
            case SIGTERM:
                $this->terminate = true;
                break;
            case SIGHUP:
            case SIGQUIT:
                $this->terminate = true;
                break;
            default:
                return false;
        }
    }
    protected function setUser()
    {
        if (!empty($this->user)) {
            $user = posix_getpwnam($this->user);
            if ($user) {
                $uid = $user['uid'];
                $gid = $user['gid'];
                $result = posix_setuid($uid);
                posix_setgid($gid);
            }
        }
    }
    protected function setPidFile()
    {
        if ($this->pidFile) {
            $pid = getmypid();
            try {
                file_put_contents($this->pidFile, $pid);
            } catch (\Exception $e) {
                $this->plog($e->getMessage(), 'ERROR');
                die(0);
            }
        }
    }
    protected function isTerminate()
    {
        pcntl_signal_dispatch();
        return $this->terminate;
    }
    protected function plog($msg, $logLevel = 'INFO')
    {
        if ($this->enableLog) {
            $msg = '[' . date("Y-m-d H:i:s") . '] ' . $logLevel . ' ' . $msg;
            echo $msg . PHP_EOL;
        }
    }
}
