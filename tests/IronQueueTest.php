<?php

use Mockery as m;
use PHPUnit\Framework\TestCase;
use SuperClosure\Serializer;
use PHPUnit\Framework\TestCase;

class IronQueueTest extends TestCase
{
    public function tearDown()
    {
        m::close();
    }

    public function testPushProperlyPushesJobOntoIron()
    {
        $iron = m::mock('IronMQ\IronMQ');
        $iron->shouldReceive('postMessage')->once()->with('default', 'encrypted', [])->andReturn((object) ['id' => 1]);
        $crypt = m::mock('Illuminate\Contracts\Encryption\Encrypter');
        $crypt->shouldReceive('encrypt')->once()->with(json_encode(['displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'timeout' => null, 'data' => [1, 2, 3], 'queue' => 'default']))->andReturn('encrypted');
        $queue = new Collective\IronQueue\IronQueue($iron, m::mock('Illuminate\Http\Request'), 'default', true);
        $queue->setEncrypter($crypt);

        $this->assertEquals(1, $queue->push('foo', [1, 2, 3]));
    }

    public function testPushProperlyPushesJobOntoIronWithoutEncryption()
    {
        $iron = $iron = m::mock('IronMQ\IronMQ');
        $iron->shouldReceive('postMessage')->once()->with('default', json_encode(['displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'timeout' => null, 'data' => [1, 2, 3], 'queue' => 'default']), [])->andReturn((object) ['id' => 1]);
        $crypt = m::mock('Illuminate\Contracts\Encryption\Encrypter');
        $crypt->shouldReceive('encrypt')->never();
        $queue = new Collective\IronQueue\IronQueue($iron, m::mock('Illuminate\Http\Request'), 'default');
        $queue->setEncrypter($crypt);

        $this->assertEquals(1, $queue->push('foo', [1, 2, 3]));
    }

    public function testDelayedPushProperlyPushesJobOntoIron()
    {
        $iron = m::mock('IronMQ\IronMQ');
        $iron->shouldReceive('postMessage')->once()->with('default', 'encrypted', ['delay' => 5])->andReturn((object) ['id' => 1]);
        $crypt = m::mock('Illuminate\Contracts\Encryption\Encrypter');
        $crypt->shouldReceive('encrypt')->once()->with(json_encode(['displayName' => 'foo', 'job' => 'foo', 'maxTries' => null, 'timeout' => null, 'data' => [1, 2, 3], 'queue' => 'default']))->andReturn('encrypted');
        $queue = new Collective\IronQueue\IronQueue($iron, m::mock('Illuminate\Http\Request'), 'default', true);
        $queue->setEncrypter($crypt);
        $this->assertEquals(1, $queue->later(5, 'foo', [1, 2, 3]));
    }

    public function testPopProperlyPopsJobOffOfIron()
    {
        $queue = new Collective\IronQueue\IronQueue($iron = m::mock('IronMQ\IronMQ'), m::mock('Illuminate\Http\Request'), 'default', true);
        $crypt = m::mock('Illuminate\Contracts\Encryption\Encrypter');
        $queue->setEncrypter($crypt);
        $queue->setContainer(m::mock('Illuminate\Container\Container'));
        $iron->shouldReceive('reserveMessage')->once()->with('default', 60)->andReturn($job = m::mock('IronMQ_Message'));
        $job->body = 'foo';
        $crypt->shouldReceive('decrypt')->once()->with('foo')->andReturn('foo');
        $result = $queue->pop();

        $this->assertInstanceOf('Collective\IronQueue\Jobs\IronJob', $result);
    }

    public function testPopProperlyPopsJobOffOfIronWithCustomTimeout()
    {
        $queue = new Collective\IronQueue\IronQueue($iron = m::mock('IronMQ\IronMQ'), m::mock('Illuminate\Http\Request'), 'default', true, 120);
        $crypt = m::mock('Illuminate\Contracts\Encryption\Encrypter');
        $queue->setEncrypter($crypt);
        $queue->setContainer(m::mock('Illuminate\Container\Container'));
        $iron->shouldReceive('reserveMessage')->once()->with('default', 120)->andReturn($job = m::mock('IronMQ_Message'));
        $job->body = 'foo';
        $crypt->shouldReceive('decrypt')->once()->with('foo')->andReturn('foo');
        $result = $queue->pop();

        $this->assertInstanceOf('Collective\IronQueue\Jobs\IronJob', $result);
    }

    public function testPopProperlyPopsJobOffOfIronWithoutEncryption()
    {
        $queue = new Collective\IronQueue\IronQueue($iron = m::mock('IronMQ\IronMQ'), m::mock('Illuminate\Http\Request'), 'default');
        $crypt = m::mock('Illuminate\Contracts\Encryption\Encrypter');
        $queue->setEncrypter($crypt);
        $queue->setContainer(m::mock('Illuminate\Container\Container'));
        $iron->shouldReceive('reserveMessage')->once()->with('default', 60)->andReturn($job = m::mock('IronMQ_Message'));
        $job->body = 'foo';
        $crypt->shouldReceive('decrypt')->never();
        $result = $queue->pop();

        $this->assertInstanceOf('Collective\IronQueue\Jobs\IronJob', $result);
    }

    /**
     * @expectedException IronCore\HttpException
     */
    public function testDeleteJobWithExpiredReservationIdThrowsAnException()
    {
        $queue = new Collective\IronQueue\IronQueue($iron = m::mock('IronMQ\IronMQ'), m::mock('Illuminate\Http\Request'), 'default', false, 30);
        $iron->shouldReceive('deleteMessage')->with('default', 1, 'def456')->andThrow('IronCore\HttpException', '{"msg":"Reservation has timed out"}');
        // 'def456' refers to a reservation id that expired
        $queue->deleteMessage('default', 1, 'def456');
    }

    public function testPushedJobsCanBeMarshaled()
    {
        $queue = $this->getMockBuilder('Collective\IronQueue\IronQueue')->setMethods(['createPushedIronJob'])->setConstructorArgs([$iron = m::mock('IronMQ\IronMQ'), $request = m::mock('Illuminate\Http\Request'), 'default', true])->getMock();
        $crypt = m::mock('Illuminate\Contracts\Encryption\Encrypter');
        $queue->setEncrypter($crypt);
        $request->shouldReceive('header')->once()->with('iron-message-id')->andReturn('message-id');
        $request->shouldReceive('getContent')->once()->andReturn($content = json_encode(['foo' => 'bar']));
        $crypt->shouldReceive('decrypt')->once()->with($content)->andReturn($content);
        $job = (object) ['id' => 'message-id', 'body' => json_encode(['foo' => 'bar']), 'pushed' => true];
        $queue->expects($this->once())->method('createPushedIronJob')->with($this->equalTo($job))->will($this->returnValue($mockIronJob = m::mock('StdClass')));
        $mockIronJob->shouldReceive('fire')->once();

        $response = $queue->marshal();

        $this->assertInstanceOf('Illuminate\Http\Response', $response);
        $this->assertEquals(200, $response->getStatusCode());
    }
}
