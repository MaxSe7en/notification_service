<?php

use Swoole\Timer;
use Swoole\Process;


use App\Models\NotificationModel;
use App\Config\DatabaseAccessors;
use App\Services\NotificationService;
use App\Controllers\NotificationController;
require 'vendor/autoload.php';

echo "Starting Notification Worker...\n";

Timer::tick(5000, function () {
    $db = new DatabaseAccessors();
    echo "Started ticking Worker...\n";
    $notificationModel = new NotificationModel();
    $pending = $notificationModel->getPendingNotifications();
    // print_r($pending);

    // (new NotificationController)->processAndSendNotices();
    foreach ($pending as $notification) {
        echo "Sending notification to ...". json_encode($notification). "\n";

        $success = NotificationService::sendNotification(
            $notification['user_id'],
            $notification['type'],
            $notification['n_event'],
            $notification['message']
        );

        $status = $success ? 'sent' : 'failed';
        $db->update("UPDATE notifications SET status = ? WHERE id = ?", [$status, $notification['id']]);

        echo "Processed Notification ID: {$notification['id']} - Status: {$status}\n";
        echo "Sending: {$notification['message']}\n";
    }
});

Process::wait();