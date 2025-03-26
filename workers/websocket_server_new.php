<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Swoole\WebSocket\Server;
use Swoole\Table;
use Swoole\Timer;
use Swoole\Server as TcpServer;

use App\Config\DatabaseAccessors;
use App\Models\NotificationModel;
use App\Services\NotificationService;

use \Predis\Client;

$redisCache = new Client(); // Connect to Redis

$server = new Server("0.0.0.0", 9502);

$server->on("open", function ($server, $request) use ($redisCache) {
    $queryString = $request->server['query_string'] ?? '';
    parse_str($queryString, $query);

    echo "Full Request URI: {$request->server['request_uri']}\n";
    echo "Query String: $queryString\n";

    if (isset($query['userId'])) {
        $userId = (int) $query['userId'];
        $redisCache->hset("connected_users", $userId, $request->fd); // Store user in Redis
        echo "User $userId connected with FD {$request->fd}\n";
    }

    swoole_timer_tick(1000, function() use ($server, $request, $redisCache) {
        $userId = $request->get['userId'];
        $queueKey = "notification_queue:{$userId}";
        
        while ($message = $redisCache->lpop($queueKey)) {
            $server->push($request->fd, $message);
        }
    });
});

$server->on("message", function ($server, $frame) use ($redisCache) {
    echo "Received message from client: {$frame->data}\n";
    $data = json_decode($frame->data, true);
    // print_r($data);
    if ($data && isset($data['action']) && $data['action'] === 'send_notification') {
        $userId = (int) $data['user_id'];
        $message = "Socket new man"; //$data['message'];
        sendNotificationToUser($server, $userId, $message, $redisCache, "normal");
    }
});

$server->on("receive", function ($server, $fd, $reactor_id, $data) use ($redisCache) {
    $notification = json_decode($data, true);
    echo "TCP Listener received data: " . $data . "\n";
    echo "=============================================\n";
    if ($notification && isset($notification['action']) && $notification['action'] === 'send_notification') {
        $userId = (int) $notification['user_id'];
        $message = $notification['message'];
        $event = $notification['event'];
        sendNotificationToUser($server, $userId, $message, $redisCache, $event);
    }
});

$server->on("close", function ($server, $fd) use ($redisCache) {
    $users = $redisCache->hgetall("connected_users");
    foreach ($users as $userId => $storedFd) {
        if ($storedFd == $fd) {
            $redisCache->hdel("connected_users", $userId);
            echo "User $userId disconnected.\n";
            break;
        }
    }
});


function sendNotificationToUser22($server, $userId, $message, $redisCache)
{
    if ($redisCache->exists($userId)) {
        $fd = $redisCache->get($userId, 'fd');
        $server->push($fd, json_encode(["user_id" => $userId, "message" => $message]));
        echo "Sent notification to User $userId\n";
    } else {
        echo "User $userId not connected\n";
    }
}
function sendNotificationToUser($server, $userId, $message, $redisCache, $event)
{
    if ($redisCache->exists($userId)) {
        $fd = $redisCache->get($userId, 'fd');
        $result = $server->push($fd, json_encode(["user_id" => $userId, "message" => $message, "event" => $event]));
        echo "Sent notification to User $userId\n";
        return $result;
    } else {
        echo "User $userId not connected\n";
        return false;
    }
}

$port = $server->addlistener("127.0.0.1", 9503, SWOOLE_SOCK_TCP);
echo "TCP Listener started on 127.0.0.1:9503\n";
$port->set([
    // 'open_length_check' => true,
    // 'package_length_type' => 'N', // Unsigned long (32-bit, big-endian)
    // 'package_length_offset' => 0,
    // 'package_body_offset' => 4,
    // // 'package_max_length' => 1024 * 1024, // 1MB
    'open_eof_check' => true,
    'package_eof' => "\n",
]);

$port->on('connect', function ($serv, $fd, $from_id) {
    echo 'connected callback' . PHP_EOL;
});

$port->on("receive", function ($port, $fd, $reactorId, $data) use ($server, $redisCache) {
    try {
        $notification = json_decode(trim($data), true);

        if (!$notification) {
            echo "ERROR: Failed to parse JSON: " . json_last_error_msg() . "\n";
            return;
        }

        if (isset($notification['action']) && $notification['action'] === 'send_notification') {
            $userId = (int) $notification['user_id'];
            $message = $notification['message'];
            $event = $notification['event'];

            echo "TCP Listener: Forwarding notification for user $userId\n";

            if ($redisCache->exists($userId)) {
                $clientFd = $redisCache->get($userId, 'fd');
                $server->push($clientFd, json_encode([
                    "type" => "notification",
                    "user_id" => $userId,
                    "message" => $message,
                    "event" => $event
                ]));
                echo "Sent notification to User $userId (FD: $clientFd)\n";
            } else {
                echo "User $userId not connected to WebSocket\n";
            }
        }
    } catch (\Exception $e) {
        echo "ERROR in TCP listener: " . $e->getMessage() . "\n";
    }
});

$GLOBALS['redisCache'] = $redisCache;

$server->start();
