<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Swoole\WebSocket\Server;
use Swoole\Table;
use Swoole\Timer;
use Swoole\Server as TcpServer;

use App\Exceptions\Console;
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

    // swoole_timer_tick(1000, function() use ($server, $request, $redisCache) {
    //     $userId = $request->get['userId'];
    //     $queueKey = "notification_queue:{$userId}";
    //     echo "Queue key: {$queueKey }\n";
    //     while ($message = $redisCache->lpop($queueKey)) {
    //         echo "Sending notification to User $userId: $message\n";
    //         $server->push($request->fd, $message);
    //     }
    // });
});

$server->on("message", function ($server, $frame) use ($redisCache) {
    echo "Received message from client: {$frame->data}\n";
    $data = json_decode($frame->data, true);

    // Start a timer to check for notifications when a client connects
    swoole_timer_tick(1000, function () use ($server, $data, $frame, $redisCache) {

        // echo "Sent notification to User ". json_encode(isset($data['user_id']));
        if (isset($data['user_id'])) {
            $userId = (int) $data['user_id'];

            // Check for pending notifications in Redis
            $notificationKey = "notification_queue:{$userId}";
            $pendingNotification = $redisCache->lpop($notificationKey);

            if ($pendingNotification) {
                // Send the notification to the specific client
                if ($server->exist($frame->fd)) {
                    $server->push($frame->fd, $pendingNotification);
                    echo "Sent notification counts to User $userId: $pendingNotification\n";
                } else {
                    echo "FD {$frame->fd} is no longer connected.\n";
                }

                echo "Sent notification to User $userId: $pendingNotification\n";
            }
        }
    });
    //for testing purposes
    if ($data && isset($data['action']) && $data['action'] === 'send_notification') {
        $userId = (int) $data['user_id'];
        $message = "Socket new man"; //$data['message'];
        sendNotificationToUser($server, $userId, $message, $redisCache, "normal");
    }
});

// $server->on("receive", function ($server, $fd, $reactor_id, $data) use ($redisCache) {
//     $notification = json_decode($data, true);
//     echo "TCP Listener received data: " . $data . "\n";
//     echo "=============================================\n";
//     if ($notification && isset($notification['action']) && $notification['action'] === 'send_notification') {
//         $userId = (int) $notification['user_id'];
//         $message = $notification['message'];
//         $event = $notification['event'];
//         sendNotificationToUser($server, $userId, $message, $redisCache, $event);
//     }
// });

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


function sendNotificationToUser($server, $userId, $message, $redisCache, $event)
{
    $redisKey = "connected_users"; // The key where connected users are stored
    // Console::log2("-----------------> ",$redisCache->hExists($redisKey, $userId));
    // Console::log2("-----------------> ",$userId);
    if ($redisCache->hExists($redisKey, $userId)) {
        $fd = $redisCache->hGet($redisKey, $userId); // Get the file descriptor
        $newCounts = (new NotificationModel())->getNotificationCounts($userId);
        // Ensure $fd is a valid number before sending the notification
        if ($fd !== false && is_numeric($fd)) {
            $result = $server->push($fd, json_encode([
                "user_id" => $userId,
                "message" => $newCounts,
                "event" => 'notification_count'

            ]));

            echo "Sent notification to User $userId\n";
            return $result;
        } else {
            $redisCache->hDel($redisKey, $userId);
            echo "Invalid file descriptor for User $userId\n";
            return false;
        }
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

            $redisKey = "connected_users"; // Redis hash key for WebSocket FDs

            if ($redisCache->hExists($redisKey, $userId)) {
                $clientFd = $redisCache->hGet($redisKey, $userId);

                // Validate FD before pushing
                if ($clientFd !== false && is_numeric($clientFd) && $server->exist((int)$clientFd)) {
                    $server->push((int)$clientFd, json_encode([
                        "type" => "notification",
                        "user_id" => $userId,
                        "message" => $message,
                        "event" => $event
                    ]));
                    echo "Sent notification to User $userId (FD: $clientFd)\n";
                } else {
                    echo "Invalid or disconnected FD for User $userId\n";
                    $redisCache->hDel($redisKey, $userId); // Remove stale FD
                }
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
