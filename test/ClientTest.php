<?php
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

require './vendor/autoload.php';
use \PHPUnit\Framework\TestCase;
use Jingwu\PhpBeanstalk\Client;

class ClientTest extends TestCase {

	public $client;

	protected function setUp() {
		$this->client = new Client(array(
            'persistent'      => true,
			'host'            => TEST_SERVER_HOST,
			'port'            => TEST_SERVER_PORT,
            'timeout'         => TEST_TIMEOUT,
            'stream_timeout'  => TEST_STREAM_TIMEOUT,
		));
		if (!$this->client->connect()) {
			$message = 'Need a running beanstalk server at ' . TEST_SERVER_HOST . ':' . TEST_SERVER_PORT;
			$this->markTestSkipped($message);
		}

        for($i = 0; $i < 20; $i++) {
            $body = json_encode(['name' => 'tester', 'ctime' => date('Y-m-d H:i:s')]);
            $result = $this->client->usePut(TEST_TUBE, $body);
        }
	}

	public function testConnect() {
		$this->client->disconnect();

		$result = $this->client->connect();
		$this->assertTrue($result);

		$result = $this->client->connected;
		$this->assertTrue($result);

		$result = $this->client->disconnect();
		$this->assertTrue($result);

		$result = $this->client->connected;
		$this->assertFalse($result);
	}

    public function testPut() {
        $result = $this->client->use(TEST_TUBE);
		$this->assertEquals($result, TEST_TUBE);

        $body = json_encode(['name' => 'tester', 'ctime' => date('Y-m-d H:i:s')]);
        $jobid = $result = $this->client->put(0, 0, 30, $body);
        $this->assertGreaterThan(0, $jobid);
    }

    public function testReserve() {
        $result = $this->client->watch(TEST_TUBE);
        $this->assertGreaterThan(0, $result);

        $result = $this->client->ignore('default');
        $this->assertGreaterThan(0, $result);

        $job = $this->client->reserve(TEST_TUBE);
        $this->assertArrayHasKey('body', $job);

        $result = $this->client->touch($job['id']);
		$this->assertTrue($result);

        $result = $this->client->statsJob($job['id']);
        $this->assertArrayHasKey('state', $result);

        $result = $this->client->release($job['id'], 0, 30);
		$this->assertTrue($result);

        $result = $this->client->delete($job['id']);
		$this->assertTrue($result);
    }

    public function testPeek() {
        $result = $this->client->watch(TEST_TUBE);
        $job = $this->client->reserve(TEST_TUBE);

        $result = $this->client->bury($job['id'], 2);
		$this->assertTrue($result);

        $result = $this->client->peek($job['id']);
        $this->assertArrayHasKey('body', $result);

        $result = $this->client->peekReady();
        $this->assertArrayHasKey('body', $result);

        $body = json_encode(['name' => 'tester', 'ctime' => date('Y-m-d H:i:s')]);
        $result = $this->client->usePut(TEST_TUBE, $body, 0, 1);

        $result = $this->client->peekDelayed();
        $this->assertArrayHasKey('body', $result);
    }

    public function testStats() {
        $result = $this->client->stats();
        $this->assertArrayHasKey('total-jobs', $result);

        $result = $this->client->statsTube(TEST_TUBE);
        $this->assertArrayHasKey('total-jobs', $result);
    }

    public function testList() {
        $result = $this->client->watch(TEST_TUBE);
        $result = $this->client->use(TEST_TUBE);

        $result = $this->client->listTubes();
        $this->assertContains(TEST_TUBE, $result);

        $result = $this->client->listTubeUsed();
        $this->assertEquals(TEST_TUBE, $result);

        $result = $this->client->listTubeChosen();
        $this->assertEquals(TEST_TUBE, $result);

        $result = $this->client->listTubesWatched();
        $this->assertContains(TEST_TUBE, $result);
    }

}

