<?php

/**
 * This file contains a PHP client to Celery distributed task queue
 *
 * LICENSE: 2-clause BSD
 *
 * Copyright (c) 2014, GDR!
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice, this
 *    list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 *    this list of conditions and the following disclaimer in the documentation
 *    and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR
 * ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
 * SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * The views and conclusions contained in the software and documentation are those
 * of the authors and should not be interpreted as representing official policies,
 * either expressed or implied, of the FreeBSD Project.
 *
 * @link http://massivescale.net/
 * @link http://gdr.geekhood.net/
 * @link https://github.com/gjedeer/celery-php
 *
 * @package celery-php
 * @license http://opensource.org/licenses/bsd-license.php 2-clause BSD
 * @author GDR! <gdr@go2.pl>
 */

namespace Celery;

/**
 * Driver for pure PHP implementation of AMQP protocol
 * @link https://github.com/php-amqplib/php-amqplib
 * @package celery-php
 */
class AMQPLibConnector extends AbstractAMQPConnector
{
    /**
     * How long (in seconds) to wait for a message from queue
     * Sadly, this can't be set to zero to achieve complete asynchronity
     */
    public $wait_timeout = 0.1;

    /**
     * \PhpAmqpLib\Message\AMQPMessage object received from the queue
     */
    private $message = null;

    /**
     * AMQPChannel object cached for subsequent GetMessageBody() calls
     */
    private $receiving_channel = null;

    public function GetConnectionObject($details)
    {
        return new \PhpAmqpLib\Connection\AMQPStreamConnection(
            $details['host'],
            $details['port'],
            $details['login'],
            $details['password'],
            $details['vhost']
        );
    }

    /* NO-OP: not required in PhpAmqpLib */
    public function Connect($connection)
    {
    }

    public function PostToExchange($connection, $details, $body, $properties, $headers)
    {
        $ch = $connection->channel();

        $ch->queue_declare(
            $details['binding'],    /* queue name - "celery" */
            false,                  /* passive */
            true,                   /* durable */
            false,                  /* exclusive */
            false                   /* auto_delete */
        );

        $ch->exchange_declare(
            $details['exchange'],    /* name */
            'direct',                /* type */
            false,                   /* passive */
            true,                    /* durable */
            false                    /* auto_delete */
        );

        $ch->queue_bind(
            $details['binding'],    /* queue name - "celery" */
            $details['exchange']    /* exchange name - "celery" */
        );

        $properties['application_headers'] = new \PhpAmqpLib\Wire\AMQPTable($headers);
        $msg = new \PhpAmqpLib\Message\AMQPMessage($body, $properties);

        $ch->basic_publish($msg, $details['exchange'], $details['routing_key']);

        $ch->close();

        /* Satisfy Celery::PostTask() error checking */
        /* TODO: catch some exceptions? Which ones? */
        return true;
    }

    /**
     * A callback function for AMQPChannel::basic_consume
     * @param \PhpAmqpLib\Message\AMQPMessage $msg
     */
    public function Consume($msg)
    {
        $this->message = $msg;
    }

    /**
     * Return result of task execution for $task_id
     * @param \PhpAmqpLib\Connection\AMQPStreamConnection $connection
     * @param string $task_id Celery task identifier
     * @param int $expire expire time result queue, milliseconds
     * @param boolean $removeMessageFromQueue whether to remove message from queue
     * @return array array('body' => JSON-encoded message body, 'complete_result' => AMQPMessage object)
     * 			or false if result not ready yet
     */
    public function GetMessageBody($connection, $task_id, $expire = 0, $removeMessageFromQueue = true)
    {
        if (!$this->receiving_channel) {
            $ch = $connection->channel();
            $expire_args = null;
            if (!empty($expire)) {
                $expire_args = ["x-expires" => ["I", $expire]];
            }

            $ch->queue_declare(
                $task_id,               /* queue name */
                false,                  /* passive */
                true,                   /* durable */
                false,                  /* exclusive */
                true,                   /* auto_delete */
                false,                  /*no wait*/
                $expire_args
            );

            try {
                $ch->queue_bind($task_id, 'celeryresults');
            } catch (\PhpAmqpLib\Exception\AMQPProtocolChannelException $e) {
                $ch->close();
                return false;
            }

            $ch->basic_consume(
                $task_id,                /* queue */
                '',                      /* consumer tag */
                false,                   /* no_local */
                false,                   /* no_ack */
                false,                   /* exclusive */
                false,                   /* nowait */
                [$this, 'Consume']       /* callback */
            );
            $this->receiving_channel = $ch;
        }

        try {
            $this->receiving_channel->wait(null, false, $this->wait_timeout);
        } catch (\PhpAmqpLib\Exception\AMQPTimeoutException $e) {
            return false;
        }

        /* Check if the callback function saved something */
        if ($this->message) {
            if ($removeMessageFromQueue) {
                $this->receiving_channel->queue_delete($task_id);
            }
            $this->receiving_channel->close();
            $connection->close();

            return [
                'complete_result' => $this->message,
                'body' => $this->message->body, // JSON message body
            ];
        }

        return false;
    }
}
