<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPProtocolChannelException;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;


class RabbitMqSetup extends Command
{
    protected $signature = 'shop:rabbitmq-setup';

    protected $description = 'Requirement 4: declare the main queue, the dead-letter exchange and the DLQ (safe to run again)';

    public function handle(): int
    {
        $config = config('queue.connections.rabbitmq');
        $host = $config['hosts'][0];
        $main = $config['queue'];
        $dlx = $config['options']['queue']['failed_exchange'];
        $dlq = sprintf($config['options']['queue']['failed_routing_key'], $main);

        $connection = new AMQPStreamConnection(
            $host['host'], (int) $host['port'], $host['user'], $host['password'], $host['vhost']
        );
        $channel = $connection->channel();

        try {
           
            $channel->exchange_declare($dlx, 'direct', false, true, false);
            $channel->queue_declare($dlq, false, true, false, false);
            $channel->queue_bind($dlq, $dlx, $dlq);
            $channel->queue_declare($main, false, true, false, false, false, new AMQPTable([
                'x-dead-letter-exchange' => $dlx,
                'x-dead-letter-routing-key' => $dlq,
            ]));
        } catch (AMQPProtocolChannelException $e) {
            $this->error("RabbitMQ refused the declaration: {$e->getMessage()}");
            $this->line("If the queue \"{$main}\" already exists without the dead-letter settings, delete it at http://localhost:15672 (Queues tab) and run this command again.");

            return self::FAILURE;
        } finally {
            try {
                $connection->close();
            } catch (Throwable) {
               
            }
        }

        $this->info('RabbitMQ is ready:');
        $this->line("  queue     {$main}  (dead-letters to {$dlx})");
        $this->line("  exchange  {$dlx}");
        $this->line("  DLQ       {$dlq}");

        return self::SUCCESS;
    }
}