<?php

use Swoole\Timer;
use Swoole\Process;


use App\Models\Notification;
use App\Config\DatabaseAccessors;
use App\Services\NotificationService;

require 'vendor/autoload.php';

echo "Starting Notification Worker...\n";

Timer::tick(5000, function () {
    $db = new DatabaseAccessors();
    echo "Started ticking Worker...\n";
    $notificationModel = new Notification();
    $pending = $notificationModel->getPendingNotifications();
    // print_r($pending);
    foreach ($pending as $notification) {
        // Logic to send notification (email/SMS)
        echo "Sending notification to ...". $notification['user_id']. "\n";

        $success = NotificationService::sendNotification(
            $notification['user_id'],
            $notification['type'],
            $notification['message']
        );

        // Update status use better query builders for bulk updates
        $status = $success ? 'sent' : 'failed';
        $db->update("UPDATE notifications SET status = ? WHERE id = ?", [$status, $notification['id']]);

        echo "Processed Notification ID: {$notification['id']} - Status: {$status}\n";
        echo "Sending: {$notification['message']}\n";
    }
});

// Keep the process running
Process::wait();