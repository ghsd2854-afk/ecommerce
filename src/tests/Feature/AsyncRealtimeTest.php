<?php

namespace Tests\Feature;

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Modules\Notifications\Listeners\SendOrderConfirmationEmail;
use Modules\Notifications\Listeners\SendOrderStatusEmail;
use Modules\Notifications\Mail\OrderPlacedMail;
use Modules\Notifications\Mail\OrderStatusMail;
use Modules\Orders\Events\OrderPlaced;
use Modules\Orders\Events\OrderStatusChanged;
use Modules\Orders\Models\Order;
use Modules\Users\Contracts\UserDirectory;
use ReflectionProperty;
use RuntimeException;

/**
 * Requirement 4 - Asynchronous queues and real-time updates.
 *
 * What these tests prove (the main flow of the requirement):
 *  1. placing an order does NOT send the e-mail during the request: it is only queued;
 *  2. the worker side (the queued listener) sends the right e-mail to the right customer;
 *  3. the e-mail listeners are queued with retries, and a permanent failure is reported;
 *  4. the order events are broadcast on PRIVATE channels;
 *  5. only the right people may join those channels (admin dashboard / order owner);
 *  6. the pages ship the WebSocket client and its settings.
 *
 * RabbitMQ, Reverb and SMTP are not used here (the tests run on the in-memory database with
 * the sync queue); they are shown on the interfaces (see the demo scenario).
 * Run: php artisan test --filter=AsyncRealtimeTest
 */
class AsyncRealtimeTest extends FeatureTestCase
{
    private function placeOrder(): TestResponse
    {
        return $this->api('POST', '/orders', ['items' => [['product_id' => $this->mouse->id, 'quantity' => 1]]], $this->customer);
    }

    /** The authorisation rule registered with Broadcast::channel() for a channel pattern. */
    private function channelRule(string $pattern): callable
    {
        $channels = (new ReflectionProperty(Broadcaster::class, 'channels'))->getValue(Broadcast::driver());

        return $channels[$pattern];
    }

    /* ---------- the order is answered first, the e-mail is queued ---------- */

    public function test_the_order_is_answered_before_the_email_is_sent(): void
    {
        Queue::fake();
        Mail::fake();

        $this->placeOrder()->assertStatus(201);

        Mail::assertNothingSent(); // nothing was sent during the request
        Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === SendOrderConfirmationEmail::class);
    }

    public function test_placing_an_order_queues_a_broadcast_for_the_admin_dashboard(): void
    {
        Queue::fake();

        $this->placeOrder()->assertStatus(201);

        Queue::assertPushed(BroadcastEvent::class, fn ($job) => $job->event instanceof OrderPlaced);
    }

    public function test_the_admin_changing_the_status_queues_the_email_and_the_broadcast(): void
    {
        Queue::fake();
        $order = $this->orderOf($this->customer);

        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'confirmed'], $this->admin)->assertSuccessful();

        Queue::assertPushed(CallQueuedListener::class, fn ($job) => $job->class === SendOrderStatusEmail::class);
        Queue::assertPushed(BroadcastEvent::class, fn ($job) => $job->event instanceof OrderStatusChanged && $job->event->orderId === $order->id);
    }

    /* ---------- what the worker does with the queued messages ---------- */

    public function test_the_worker_sends_the_confirmation_email_to_the_customer(): void
    {
        Mail::fake(); // the queue is "sync" in the tests: the listener runs right away, like the worker would

        $this->placeOrder()->assertStatus(201);
        $order = Order::firstOrFail();

        Mail::assertSent(OrderPlacedMail::class, fn (OrderPlacedMail $mail) => $mail->hasTo('customer@shop.test')
            && $mail->orderId === $order->id
            && $mail->customerName === 'Test Customer');
    }

    public function test_the_status_email_reaches_the_customer(): void
    {
        Mail::fake();
        $order = $this->orderOf($this->customer);

        $this->api('PATCH', "/orders/{$order->id}/status", ['status' => 'shipped'], $this->admin)->assertSuccessful();

        Mail::assertSent(OrderStatusMail::class, fn (OrderStatusMail $mail) => $mail->hasTo('customer@shop.test')
            && $mail->orderId === $order->id
            && $mail->status === 'shipped');
    }

    public function test_no_email_is_sent_when_the_user_no_longer_exists(): void
    {
        Mail::fake();

        (new SendOrderConfirmationEmail(app(UserDirectory::class)))
            ->handle(new OrderPlaced(1, 999999, 'Ghost'));

        Mail::assertNothingSent();
    }

    /* ---------- reliability: retries, failure, dead-letter ---------- */

    public function test_the_email_listeners_are_queued_with_retries(): void
    {
        foreach ([SendOrderConfirmationEmail::class, SendOrderStatusEmail::class] as $class) {
            $listener = new $class(app(UserDirectory::class));

            $this->assertInstanceOf(ShouldQueue::class, $listener, "$class must be queued");
            $this->assertSame(3, $listener->tries);
            $this->assertSame([10, 30], $listener->backoff());
        }
    }

    public function test_a_failing_email_throws_so_the_worker_retries_it(): void
    {
        $this->app['env'] = 'local'; // the simulation switch only works locally
        config(['notifications.simulate_mail_failure' => true]);
        Mail::fake();
        $order = $this->orderOf($this->customer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Simulated e-mail service failure.');

        (new SendOrderConfirmationEmail(app(UserDirectory::class)))
            ->handle(new OrderPlaced($order->id, $this->customer->id, 'Test Customer'));
    }

    public function test_the_failure_simulation_never_works_outside_the_local_environment(): void
    {
        config(['notifications.simulate_mail_failure' => true]); // switched on, but APP_ENV is testing
        Mail::fake();
        $order = $this->orderOf($this->customer);

        (new SendOrderConfirmationEmail(app(UserDirectory::class)))
            ->handle(new OrderPlaced($order->id, $this->customer->id, 'Test Customer'));

        Mail::assertSent(OrderPlacedMail::class);
    }

    public function test_the_listener_reports_a_permanent_failure(): void
    {
        Log::spy();

        (new SendOrderConfirmationEmail(app(UserDirectory::class)))
            ->failed(new OrderPlaced(7, $this->customer->id, 'Test Customer'), new RuntimeException('boom'));

        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message) => str_contains($message, 'Order #7'));
    }

    /* ---------- real-time: private channels and who may join them ---------- */

    public function test_the_order_events_are_broadcast_on_private_channels(): void
    {
        $placed = new OrderPlaced(5, 6, 'Nasreen');
        $this->assertInstanceOf(ShouldBroadcast::class, $placed);
        $this->assertSame('private-admin.orders', $placed->broadcastOn()[0]->name);
        $this->assertSame('order.placed', $placed->broadcastAs());
        $this->assertSame(['order_id' => 5, 'customer' => 'Nasreen', 'status' => 'pending'], $placed->broadcastWith());

        $changed = new OrderStatusChanged(5, 6, 'shipped');
        $this->assertInstanceOf(ShouldBroadcast::class, $changed);
        $this->assertSame('private-orders.5', $changed->broadcastOn()[0]->name);
        $this->assertSame('order.status', $changed->broadcastAs());
        $this->assertSame(['order_id' => 5, 'status' => 'shipped'], $changed->broadcastWith());
    }

    public function test_only_admins_may_join_the_admin_orders_channel(): void
    {
        $join = $this->channelRule('admin.orders');

        $this->assertTrue((bool) $join($this->admin));
        $this->assertFalse((bool) $join($this->customer));
    }

    public function test_only_the_owner_may_join_an_order_channel(): void
    {
        $order = $this->orderOf($this->customer);
        $join = $this->channelRule('orders.{orderId}');

        $this->assertTrue((bool) $join($this->customer, $order->id), 'the owner');
        $this->assertFalse((bool) $join($this->customer2, $order->id), 'another customer');
        $this->assertFalse((bool) $join($this->admin, $order->id), 'not even an admin');
        $this->assertFalse((bool) $join($this->customer, 999999), 'an order that does not exist');
    }

    public function test_the_channel_authorisation_endpoint_requires_a_login(): void
    {
        $payload = ['socket_id' => '123.456', 'channel_name' => 'private-admin.orders'];

        $this->json('POST', '/api/v1/broadcasting/auth', $payload)->assertStatus(401);

        $status = $this->api('POST', '/broadcasting/auth', $payload, $this->customer)->status();
        $this->assertNotContains($status, [401, 404, 405], 'the route exists and accepts a logged-in user');
    }

    /* ---------- the pages ---------- */

    public function test_the_pages_ship_the_websocket_client(): void
    {
        $this->assertFileExists(public_path('js/pusher.min.js'));
        $this->assertStringContainsString('const Realtime', file_get_contents(public_path('js/shop.js')));

        foreach (['/admin/orders', '/orders/1'] as $page) {
            $this->get($page)->assertOk()
                ->assertSee('/js/pusher.min.js', false)
                ->assertSee('window.REALTIME', false);
        }
    }
}