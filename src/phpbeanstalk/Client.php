<?php
namespace Jingwu\PhpBeanstalk;

/**
 * beanstalk: A minimalistic PHP beanstalk client.
 *
 * Copyright (c) 2009-2013 David Persson
 *
 * Distributed under the terms of the MIT License.
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright  2009-2013 David Persson <nperson@gmx.de>
 * @license    http://www.opensource.org/licenses/mit-license.php The MIT License
 * @link       http://github.com/davidpersson/beanstalk
 */

class BeanstalkException extends \Exception {
}

/**
 * An interface to the beanstalk queue service. Implements the beanstalk
 * protocol spec 1.2. Where appropriate the documentation from the protcol has
 * been added to the docblocks in this class.
 *
 * @link https://github.com/kr/beanstalkd/blob/master/doc/protocol.txt
 */
class Client {

    /**
     * Holds a boolean indicating whether a connection to the server is
     * currently established or not.
     *
     * @var boolean
     */
    public $connected = false;

    /**
     * Holds configuration values.
     *
     * @var array
     */
    protected $_config = array();

    /**
     * The current connection resource handle (if any).
     *
     * @var resource
     */
    protected $_connection;

    /**
     * Generated errors. Will hold a maximum of 200 error messages at any time
     * to prevent pilling up messages and using more and more memory. This is
     * especially important if this class is used in long-running workers.
     *
     * @see Socket_Beanstalk::errors()
     * @see Socket_Beanstalk::_error()
     * @var array
     */
    protected $_errors = array();

    /**
     * 网络连续错误次数, 用于检测网络异常, 超过指定次数，则抛异常，程序中断
     */
    protected $_max_netError = 10;

    /**
     * 连续读取数据错误次数, 超过 _max_netError 则抛异常
     */
    protected $_count_readError = 0;

    /**
     * Constructor.
     *
     * @param array $config 连接配置：
     *        - `'persistent'`  是否启用长链接， 默认 true
     *        - `'host'`        服务器IP，默认 127.0.0.1
     *        - `'port'`        服务端口, 默认 11300
     *        - `'connect_timeout'` 建立连接超时设置
     *                  0 不设限制, 走系统默认配置
     *                  1 默认
     *        - `'stream_timeout'`  数据流超时设置
     *                 -1 不限制, 一直等待服务器响应
     *                  0 不等待服务处理，直接返回，此值有极大风险，不可在业务中使用
     *                  1 默认
     *        - `'force_reserve_timeout'`  强制 reserve 命令使用 timeout 设置
     *                  reserve 命令有死循环的bug, 为了避免这个问题，提供强制 timeout 的设置
     *                  0 不强制
     *                  1 强刷
     * @return void
     */
    public function __construct(array $config = array()) {
        $default = array(
            'persistent' => true,
            'host' => '127.0.0.1',
            'port' => 11300,
            'connect_timeout' => 1,
            'stream_timeout' => 1,
            'force_reserve_timeout' => 1,
        );
        $this->_config = $config + $default;
        //超时设置一定要有，否则网络不好时，程序会僵死, 此处由调用者设置
        $connectTimeout = intval($this->_config['connect_timeout']);
        $streamTimeout  = intval($this->_config['stream_timeout']);
    }

    /**
     * Destructor, disconnects from the server.
     *
     * @return void
     */
    public function __destruct() {
        $this->disconnect();
    }

    /**
     * Initiates a socket connection to the beanstalk server. The resulting
     * stream will not have any timeout set on it. Which means it can wait an
     * unlimited amount of time until a packet becomes available. This is
     * required for doing blocking reads.
     *
     * @see Socket_Beanstalk::$_connection
     * @see Socket_Beanstalk::reserve()
     * @return boolean `true` if the connection was established, `false` otherwise.
     */
    public function connect() {
        if (isset($this->_connection)) {
            $this->disconnect();
        }

        $function = $this->_config['persistent'] ? 'pfsockopen' : 'fsockopen';
        $params = array($this->_config['host'], $this->_config['port'], &$errNum, &$errStr);

        if ($this->_config['connect_timeout']) {
            $params[] = $this->_config['connect_timeout'];
        }
        $this->_connection = @call_user_func_array($function, $params);

        if (!empty($errNum) || !empty($errStr)) {
            $this->_error("Beanstalk {$errNum}: {$errStr}");
            trigger_error("Beanstalk {$errNum}: {$errStr}", E_USER_WARNING);
        }

        $this->connected = is_resource($this->_connection);

        //要设置流超时时间
        if ($this->connected) {
            stream_set_timeout($this->_connection, $this->_config['stream_timeout']);
        }
        return $this->connected;
    }

    /**
     * Closes the connection to the beanstalk server.
     *
     * @return boolean `true` if diconnecting was successful.
     */
    public function disconnect() {
        if (!is_resource($this->_connection)) {
            $this->connected = false;
        } else {
            $this->connected = !fclose($this->_connection);

            if (!$this->connected) {
                $this->_connection = null;
            }
        }
        return !$this->connected;
    }

    /**
     * reset the connection to the beanstalk server.
     * @return boolean true/false
     */
    public function reconnect() {
        $this->disconnect();
        $this->connect();
        $stats = $this->stats();
        return $stats === false ? false : true;
    }

    /**
     * 设置网络连续错误最大次数
     */
    public function setMaxNetError($maxNetError = 5000) {
        $this->_max_netError = $maxNetError;
    }

    /**
     * Returns collected error messages.
     *
     * @return array An array of error messages.
     */
    public function errors() {
        return $this->_errors;
    }

    /**
     * Returns the last error messages.
     *
     * @return string
     */
    public function lastError() {
        return current($this->_errors);
    }

    /**
     * Pushes an error message to `Beanstalk::$_errors`. Ensures
     * that at any point there are not more than 200 messages.
     *
     * @param string $message The error message.
     * @return void
     */
    protected function _error($message) {
        if (count($this->_errors) >= 200) {
            array_shift($this->_errors);
        }
        array_push($this->_errors, $message);
    }

    /**
     * Writes a packet to the socket. Prior to writing to the socket will check
     * for availability of the connection.
     *
     * @param string $data
     * @return integer|boolean number of written bytes or `false` on error.
     */
    protected function _write($data) {
        if (!$this->connected && !$this->connect()) {
            return false;
        }

        $data .= "\r\n";
        $dataLen = strlen($data);
        $result = fwrite($this->_connection, $data, $dataLen);
        //网络写入失败，直接异常
        if($dataLen != $result) {
            trigger_error("Beanstalk net write failed, write len: {$dataLen}, ok len: {$result}", E_USER_WARNING);
            throw new BeanstalkException("Beanstalk net write failed, write len: {$dataLen}, ok len: {$result}");
        }
        return $result;
    }

    /**
     * Reads a packet from the socket. Prior to reading from the socket will
     * check for availability of the connection.
     *
     * @param int $length Number of bytes to read.
     * @return string|boolean Data or `false` on error.
     */
    protected function _read($length = null) {
        if (!$this->connected && !$this->connect()) {
            return false;
        }
        if ($length) {
            if (feof($this->_connection)) {
                return false;
            }
            $data = stream_get_contents($this->_connection, $length + 2);
            $meta = stream_get_meta_data($this->_connection);

            if ($meta['timed_out']) {
                $this->_error('Beanstalk Connection timed out.');
                trigger_error('Beanstalk Connection timed out.', E_USER_WARNING);
                return false;
            }
            $packet = rtrim($data, "\r\n");
        } else {
            $packet = stream_get_line($this->_connection, 16384, "\r\n");
        }
        //网络写入成功，但对方没有响应，直接异常
        if($packet == "") {
            $this->_count_readError++;
            if($this->_count_readError > $this->_max_netError) {
                trigger_error("Beanstalk net error over {$this->_max_netError}", E_USER_WARNING);
                throw new BeanstalkException("Beanstalk net error over {$this->_max_netError}");
            }
        } else {    //网络恢复，重置计数
            $this->_count_readError = 0;
        }
        return $packet;
    }

    /**
     * format beanstalk status error
     *
     * @param mixed $status beanstalk response status
     * @return string format beanstalk status error
     */
    private function _formatStatusError($cmd, $status) {
        return "Beanstalk cmd[$cmd] status error: ".($status === false ? "false" : $status);
    }


    /* Producer Commands */

    /**
     * The `put` command is for any process that wants to insert a job into the queue.
     *
     * @param integer $pri Jobs with smaller priority values will be scheduled
     *        before jobs with larger priorities. The most urgent priority is
     *        0; the least urgent priority is 4294967295.
     * @param integer $delay Seconds to wait before putting the job in the
     *        ready queue.  The job will be in the "delayed" state during this time.
     * @param integer $ttr Time to run - Number of seconds to allow a worker to
     *        run this job.  The minimum ttr is 1.
     * @param string $data The job body.
     * @return integer|boolean `false` on error otherwise an integer indicating
     *         the job id.
     */
    public function put($pri, $delay, $ttr, $data) {
        $this->_write(sprintf("put %d %d %d %d\r\n%s", $pri, $delay, $ttr, strlen($data), $data));
        $status = strtok($this->_read(), ' ');

        switch ($status) {
            case 'INSERTED':
            case 'BURIED':
                return (integer) strtok(' '); // job id
            case 'EXPECTED_CRLF':
            case 'JOB_TOO_BIG':
            default:
                $this->_error($this->_formatStatusError("put", $status));
                trigger_error($this->_formatStatusError("put", $status), E_USER_WARNING);
                return false;
        }
    }

    /**
     * The `use` command is for producers. Subsequent put commands will put jobs into
     * the tube specified by this command. If no use command has been issued, jobs
     * will be put into the tube named `default`.
     *
     * Please note that while obviously this method should better be named
     * `use` it is not. This is because `use` is a reserved keyword in PHP.
     *
     * @param string $tube A name at most 200 bytes. It specifies the tube to
     *        use.  If the tube does not exist, it will be created.
     * @return string|boolean `false` on error otherwise the name of the tube.
     */
    public function choose($tube) {
        $this->_write(sprintf('use %s', $tube));
        $status = strtok($this->_read(), ' ');

        switch ($status) {
            case 'USING':
                return strtok(' ');
            default:
                $this->_error($this->_formatStatusError("use", $status));
                trigger_error($this->_formatStatusError("use", $status), E_USER_WARNING);
                return false;
        }
    }

    /**
     * Alias for choose.
     *
     * @see Socket_Beanstalk::choose()
     * @param string $tube
     * @return string|boolean
     */
    public function useTube($tube) {
        return $this->choose($tube);
    }

    /**
     * useTube and put
     *
     * @param string $tube
     * @param integer $pri Jobs with smaller priority values will be scheduled
     *        before jobs with larger priorities. The most urgent priority is
     *        0; the least urgent priority is 4294967295.
     * @param integer $delay Seconds to wait before putting the job in the
     *        ready queue.  The job will be in the "delayed" state during this time.
     * @param integer $ttr Time to run - Number of seconds to allow a worker to
     *        run this job.  The minimum ttr is 1.
     * @param string $data The job body.
     * @return integer|boolean `false` on error otherwise an integer indicating
     *         the job id.
     */
    public function usePut($tube, $data, $pri=0, $delay=0, $ttr=30) {
        $result = $this->useTube($tube);
        if($result === false) return false;
        $jobId = $this->put($pri, $delay, $ttr, $data);
        return $jobId;
    }

    /* Worker Commands */

    /**
     * Reserve a job (with a timeout)
     *
     * @param integer $timeout If given specifies number of seconds to wait for
     *        a job. 0 returns immediately.
     * @return array|false `false` on error otherwise an array holding job id
     *         and body.
     */
    public function reserve($timeout = null) {
        $timeout = intval($timeout);
        // reserve 命令有死循环的bug, 这里只可以使用timeout方式
        // 强制 reserve 使用 timeout 设置
        if($this->_config['force_reserve_timeout']) {
            if($timeout < 1) {
                $timeout = 1;
            }
        }

        $cmd = "";
        if($timeout) {
            $cmd = "reserve-with-timeout";
            $result = $this->_write(sprintf('%s %d', $cmd, $timeout));
        } else {
            $cmd = "reserve";
            $result = $this->_write($cmd);
        }
        if($result === false) return $result;
        $status = strtok($this->_read(), ' ');
        if($status === false) return false;

        switch ($status) {
            case 'RESERVED':
                return array(
                    'id' => (integer) strtok(' '),
                    'body' => $this->_read((integer) strtok(' '))
                );
            case 'DEADLINE_SOON':
            case 'TIMED_OUT':
            default:
                $this->_error($this->_formatStatusError($cmd, $status));
                trigger_error($this->_formatStatusError($cmd, $status), E_USER_WARNING);
                return false;
        }
    }

    /**
     * Removes a job from the server entirely.
     *
     * @param integer $id The id of the job.
     * @return boolean `false` on error, `true` on success.
     */
    public function delete($id) {
        $cmd = "delete";
        $this->_write(sprintf('%s %d', $cmd, $id));
        $status = $this->_read();

        switch ($status) {
            case 'DELETED':
                return true;
            case 'NOT_FOUND':
                return false;
            default:
                $this->_error($this->_formatStatusError($cmd, $status));
                trigger_error($this->_formatStatusError($cmd, $status), E_USER_WARNING);
                return false;
        }
    }

    /**
     * Puts a reserved job back into the ready queue.
     *
     * @param integer $id The id of the job.
     * @param integer $pri Priority to assign to the job.
     * @param integer $delay Number of seconds to wait before putting the job in the ready queue.
     * @return boolean `false` on error, `true` on success.
     */
    public function release($id, $pri, $delay) {
        $cmd = "release";
        $this->_write(sprintf('%s %d %d %d', $cmd, $id, $pri, $delay));
        $status = $this->_read();

        switch ($status) {
            case 'RELEASED':
            case 'BURIED':
                return true;
            case 'NOT_FOUND':
                return false;
            default:
                $this->_error($this->_formatStatusError($cmd, $status));
                trigger_error($this->_formatStatusError($cmd, $status), E_USER_WARNING);
                return false;
        }
    }

    /**
     * Puts a job into the `buried` state Buried jobs are put into a FIFO
     * linked list and will not be touched until a client kicks them.
     *
     * @param integer $id The id of the job.
     * @param integer $pri *New* priority to assign to the job.
     * @return boolean `false` on error, `true` on success.
     */
    public function bury($id, $pri) {
        $cmd = "bury";
        $this->_write(sprintf('%s %d %d', $cmd, $id, $pri));
        $status = $this->_read();

        switch ($status) {
            case 'BURIED':
                return true;
            case 'NOT_FOUND':
                return false;
            default:
                $this->_error($this->_formatStatusError($cmd, $status));
                trigger_error($this->_formatStatusError($cmd, $status), E_USER_WARNING);
                return false;
        }
    }

    /**
     * Allows a worker to request more time to work on a job
     *
     * @param integer $id The id of the job.
     * @return boolean `false` on error, `true` on success.
     */
    public function touch($id) {
        $cmd = "touch";
        $this->_write(sprintf('%s %d', $cmd, $id));
        $status = $this->_read();

        switch ($status) {
            case 'TOUCHED':
                return true;
            case 'NOT_TOUCHED':
                return false;
            default:
                $this->_error($this->_formatStatusError($cmd, $status));
                trigger_error($this->_formatStatusError($cmd, $status), E_USER_WARNING);
                return false;
        }
    }

    /**
     * Adds the named tube to the watch list for the current
     * connection.
     *
     * @param string $tube Name of tube to watch.
     * @return integer|boolean `false` on error otherwise number of tubes in watch list.
     */
    public function watch($tube) {
        $cmd = "watch";
        $this->_write(sprintf('%s %s', $cmd, $tube));
        $status = strtok($this->_read(), ' ');

        switch ($status) {
            case 'WATCHING':
                return (integer) strtok(' ');
            default:
                $this->_error($this->_formatStatusError($cmd, $status));
                trigger_error($this->_formatStatusError($cmd, $status), E_USER_WARNING);
                return false;
        }
    }

    /**
     * Remove the named tube from the watch list.
     *
     * @param string $tube Name of tube to ignore.
     * @return integer|boolean `false` on error otherwise number of tubes in watch list.
     */
    public function ignore($tube) {
        $cmd = "ignore";
        $this->_write(sprintf('%s %s', $cmd, $tube));
        $status = strtok($this->_read(), ' ');

        switch ($status) {
            case 'WATCHING':
                return (integer) strtok(' ');
            case 'NOT_IGNORED':
                return false;
            default:
                $this->_error($this->_formatStatusError($cmd, $status));
                trigger_error($this->_formatStatusError($cmd, $status), E_USER_WARNING);
                return false;
        }
    }

    /* Other Commands */

    /**
     * Inspect a job by its id.
     *
     * @param integer $id The id of the job.
     * @return string|boolean `false` on error otherwise the body of the job.
     */
    public function peek($id) {
        $cmd = "peek";
        $this->_write(sprintf('%s %d', $cmd, $id));
        return $this->_peekRead($cmd);
    }

    /**
     * Inspect the next ready job.
     *
     * @return string|boolean `false` on error otherwise the body of the job.
     */
    public function peekReady() {
        $cmd = "peek-ready";
        $this->_write($cmd);
        return $this->_peekRead($cmd);
    }

    /**
     * Inspect the job with the shortest delay left.
     *
     * @return string|boolean `false` on error otherwise the body of the job.
     */
    public function peekDelayed() {
        $cmd = "peek-delayed";
        $this->_write($cmd);
        return $this->_peekRead($cmd);
    }

    /**
     * Inspect the next job in the list of buried jobs.
     *
     * @return string|boolean `false` on error otherwise the body of the job.
     */
    public function peekBuried() {
        $cmd = "peek-buried";
        $this->_write($cmd);
        return $this->_peekRead($cmd);
    }

    /**
     * Handles response for all peek methods.
     *
     * @return string|boolean `false` on error otherwise the body of the job.
     */
    protected function _peekRead($cmd) {
        $status = strtok($this->_read(), ' ');

        switch ($status) {
            case 'FOUND':
                return array(
                    'id' => (integer) strtok(' '),
                    'body' => $this->_read((integer) strtok(' '))
                );
            case 'NOT_FOUND':
                return false;
            default:
                $this->_error($this->_formatStatusError($cmd, $status));
                trigger_error($this->_formatStatusError($cmd, $status), E_USER_WARNING);
                return false;
        }
    }

    /**
     * Moves jobs into the ready queue (applies to the current tube).
     *
     * If there are buried jobs those get kicked only otherwise
     * delayed jobs get kicked.
     *
     * @param integer $bound Upper bound on the number of jobs to kick.
     * @return integer|boolean False on error otherwise number of job kicked.
     */
    public function kick($bound) {
        $cmd = "kick";
        $this->_write(sprintf('%s %d', $cmd, $bound));
        $status = strtok($this->_read(), ' ');

        switch ($status) {
            case 'KICKED':
                return (integer) strtok(' ');
            default:
                $this->_error($this->_formatStatusError($cmd, $status));
                trigger_error($this->_formatStatusError($cmd, $status), E_USER_WARNING);
                return false;
        }
    }

    /* Stats Commands */

    /**
     * Gives statistical information about the specified job if it exists.
     *
     * @param integer $id The job id
     * @return string|boolean `false` on error otherwise a string with a yaml formatted dictionary
     */
    public function statsJob($id) {
        $cmd = "stats-job";
        $this->_write(sprintf('%s %d', $cmd, $id));
        return $this->_statsRead($cmd);
    }

    /**
     * Gives statistical information about the specified tube if it exists.
     *
     * @param string $tube Name of the tube.
     * @return string|boolean `false` on error otherwise a string with a yaml formatted dictionary.
     */
    public function statsTube($tube) {
        $cmd = "stats-tube";
        $this->_write(sprintf('%s %s', $cmd, $tube));
        return $this->_statsRead($cmd);
    }

    /**
     * Gives statistical information about the system as a whole.
     *
     * @return string|boolean `false` on error otherwise a string with a yaml formatted dictionary.
     */
    public function stats() {
        $cmd = "stats";
        $this->_write($cmd);
        return $this->_statsRead($cmd);
    }

    /**
     * Returns a list of all existing tubes.
     *
     * @return string|boolean `false` on error otherwise a string with a yaml formatted list.
     */
    public function listTubes() {
        $cmd = "list-tubes";
        $this->_write($cmd);
        return $this->_statsRead($cmd);
    }

    /**
     * Returns the tube currently being used by the producer.
     *
     * @return string|boolean `false` on error otherwise a string with the name of the tube.
     */
    public function listTubeUsed() {
        $cmd = "list-tube-used";
        $this->_write($cmd);
        $status = strtok($this->_read(), ' ');

        switch ($status) {
            case 'USING':
                return strtok(' ');
            default:
                $this->_error($this->_formatStatusError($cmd, $status));
                trigger_error($this->_formatStatusError($cmd, $status), E_USER_WARNING);
                return false;
        }
    }

    /**
     * Alias for listTubeUsed.
     *
     * @see Socket_Beanstalk::listTubeUsed()
     * @return string|boolean `false` on error otherwise a string with the name of the tube.
     */
    public function listTubeChosen() {
        return $this->listTubeUsed();
    }

    /**
     * Returns a list of tubes currently being watched by the worker.
     *
     * @return string|boolean `false` on error otherwise a string with a yaml formatted list.
     */
    public function listTubesWatched() {
        $cmd = "list-tubes-watched";
        $this->_write($cmd);
        return $this->_statsRead($cmd);
    }

    /**
     * Handles responses for all stat methods.
     *
     * @param boolean $decode Whether to decode data before returning it or not. Default is `true`.
     * @return array|string|boolean `false` on error otherwise statistical data.
     */
    protected function _statsRead($cmd, $decode = true) {
        $status = strtok($this->_read(), ' ');

        switch ($status) {
            case 'OK':
                $data = $this->_read((integer) strtok(' '));
                return $decode ? $this->_decode($data) : $data;
            default:
                $this->_error($this->_formatStatusError($cmd, $status));
                trigger_error($this->_formatStatusError($cmd, $status), E_USER_WARNING);
                return false;
        }
    }

    /**
     * Decodes YAML data. This is a super naive decoder which just works on a
     * subset of YAML which is commonly returned by beanstalk.
     *
     * @param string $data The data in YAML format, can be either a list or a dictionary.
     * @return array An (associative) array of the converted data.
     */
    protected function _decode($data) {
        $data = array_slice(explode("\n", $data), 1);
        $result = array();

        foreach ($data as $key => $value) {
            if ($value[0] === '-') {
                $value = ltrim($value, '- ');
            } elseif (strpos($value, ':') !== false) {
                list($key, $value) = explode(':', $value);
                $value = ltrim($value, ' ');
            }
            if (is_numeric($value)) {
                $value = (integer) $value == $value ? (integer) $value : (float) $value;
            }
            $result[$key] = $value;
        }
        return $result;
    }
}

