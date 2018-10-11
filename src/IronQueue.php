<?php

namespace Collective\IronQueue;

use Collective\IronQueue\Jobs\IronJob;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Queue\Queue;
use IronMQ\IronMQ;

class IronQueue extends Queue implements QueueContract
{
    /**
     * The IronMQ instance.
     *
     * @var \IronMQ\IronMQ
     */
    protected $iron;

    /**
     * The current request instance.
     *
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * The name of the default tube.
     *
     * @var string
     */
    protected $default;

    /**
     * Indicates if the messages should be encrypted.
     *
     * @var bool
     */
    protected $shouldEncrypt;

    /**
     * Number of seconds before the reservation_id times out on a newly popped message.
     *
     * @var int
     */
    protected $timeout;

    /**
     * Create a new IronMQ queue instance.
     *
     * @param \IronMQ\IronMQ           $iron
     * @param \Illuminate\Http\Request $request
     * @param string                   $default
     * @param bool                     $shouldEncrypt
     * @param int                      $timeout
     */
    public function __construct(IronMQ $iron, Request $request, $default, $shouldEncrypt = false, $timeout = 60)
    {
        $this->iron = $iron;
        $this->request = $request;
        $this->default = $default;
        $this->shouldEncrypt = $shouldEncrypt;
        $this->timeout = $timeout;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param string $job
     * @param mixed  $data
     * @param string $queue
     *
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        return $this->pushRaw($this->createPayload($job, $queue, $data), $queue);
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param string $payload
     * @param string $queue
     * @param array  $options
     *
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = [])
    {
        if ($this->shouldEncrypt) {
            $payload = $this->getEncrypter()->encrypt($payload);
        }

        return $this->iron->postMessage($this->getQueue($queue), $payload, $options)->id;
    }

    /**
     * Push a raw payload onto the queue after encrypting the payload.
     *
     * @param string $payload
     * @param string $queue
     * @param int    $delay
     *
     * @return mixed
     */
    public function recreate($payload, $queue, $delay)
    {
        $options = ['delay' => $this->secondsUntil($delay)];

        return $this->pushRaw($payload, $queue, $options);
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param \DateTime|int $delay
     * @param string        $job
     * @param mixed         $data
     * @param string        $queue
     *
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        $delay = $this->secondsUntil($delay);

        $payload = $this->createPayload($job, $queue, $data);

        return $this->pushRaw($payload, $queue, compact('delay'));
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param string $queue
     *
     * @return \Illuminate\Contracts\Queue\Job|null
     */
    public function pop($queue = null)
    {
        $queue = $this->getQueue($queue);

        $job = $this->iron->reserveMessage($queue, $this->timeout);

        // If we were able to pop a message off of the queue, we will need to decrypt
        // the message body, as all Iron.io messages are encrypted, since the push
        // queues will be a security hazard to unsuspecting developers using it.
        if (!is_null($job)) {
            $job->body = $this->parseJobBody($job->body);

            return new IronJob($this->container, $this, $job);
        }
    }

    /**
     * Delete a message from the Iron queue.
     *
     * @param string $queue
     * @param string $id
     * @param string $reservation_id
     *
     * @return void
     */
    public function deleteMessage($queue, $id, $reservation_id)
    {
        $this->iron->deleteMessage($queue, $id, $reservation_id);
    }

    /**
     * Marshal a push queue request and fire the job.
     *
     * @return \Illuminate\Http\Response
     *
     * @deprecated since version 5.1.
     */
    public function marshal()
    {
        $this->createPushedIronJob($this->marshalPushedJob())->fire();

        return new Response('OK');
    }

    /**
     * Marshal out the pushed job and payload.
     *
     * @return object
     */
    protected function marshalPushedJob()
    {
        $r = $this->request;

        $body = $this->parseJobBody($r->getContent());

        return (object) [
            'id' => $r->header('iron-message-id'), 'body' => $body, 'pushed' => true,
        ];
    }

    /**
     * Create a new IronJob for a pushed job.
     *
     * @param object $job
     *
     * @return \Illuminate\Queue\Jobs\IronJob
     */
    protected function createPushedIronJob($job)
    {
        return new IronJob($this->container, $this, $job, true);
    }

    /**
     * Parse the job body for firing.
     *
     * @param string $body
     *
     * @return string
     */
    protected function parseJobBody($body)
    {
        return $this->shouldEncrypt ? $this->getEncrypter()->decrypt($body) : $body;
    }

    /**
     * Get the queue or return the default.
     *
     * @param string|null $queue
     *
     * @return string
     */
    public function getQueue($queue)
    {
        return $queue ?: $this->default;
    }

    /**
     * Get the underlying IronMQ instance.
     *
     * @return \IronMQ\IronMQ
     */
    public function getIron()
    {
        return $this->iron;
    }

    /**
     * Get the request instance.
     *
     * @return \Illuminate\Http\Request
     */
    public function getRequest()
    {
        return $this->request;
    }

    /**
     * Set the request instance.
     *
     * @param \Illuminate\Http\Request $request
     *
     * @return void
     */
    public function setRequest(Request $request)
    {
        $this->request = $request;
    }

    /**
     * Create a payload array from the given job and data.
     *
     * @param  string  $job
     * @param  mixed   $data
     * @param  string  $queue
     * @return array
     */
    protected function createPayloadArray($job, $queue, $data = '')
    {
        return array_merge(parent::createPayloadArray($job, $queue, $data), [
            'queue' => $this->getQueue($queue),
        ]);
    }

    /**
     * Get the size of the queue.
     *
     * @param null $queue
     *
     * @return int
     */
    public function size($queue = null)
    {
        return (int)$this->iron->getQueue($queue)->size;
    }

    /**
     * Get the encrypter implementation.
     *
     * @return  \Illuminate\Contracts\Encryption\Encrypter
     *
     * @throws \Exception
     */
    protected function getEncrypter()
    {
        if (is_null($this->encrypter)) {
            throw new \Exception('No encrypter has been set on the Queue.');
        }
        return $this->encrypter;
    }

    /**
     * Set the encrypter implementation.
     *
     * @param  \Illuminate\Contracts\Encryption\Encrypter $encrypter
     * @return void
     */
    public function setEncrypter(Encrypter $encrypter)
    {
        $this->encrypter = $encrypter;
    }
}
