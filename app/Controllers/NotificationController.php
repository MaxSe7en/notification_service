<?php

namespace App\Controllers;

use App\Models\NotificationModel;
use App\Services\NotificationService;

class NotificationController
{

    private $notification;

    public function __construct()
    {
        $this->notification = new NotificationModel();
    }


    public function send($userId, $type, $message)
    {
        $notification = new NotificationModel();
        return $notification->create($userId, $type, $message);
    }

    public function processAndSendNotices()
    {
        $notices = $this->notification->getAllPendingNotices();
        // print_r($notices);

        foreach ($notices as $notice) {
            if ($notice['ms_type'] === 'general') {
                // Fetch all users for general notices
                $users = $this->notification->getAllUsers();
                foreach ($users as $user) {
                    $this->sendNotification($user['uid'], $notice);
                }
            } else {
                // Send personal notices
                if (!empty($notice['user_id'])) {
                    $this->sendNotification($notice['user_id'], $notice);
                }
            }

            // Update status to sent
            // $this->markNoticeAsSent($notice['msg_id']);
        }
    }

    private function sendNotification($userId, $notice)
    {
        // Example: Send via WebSocket, Email, SMS, etc.
        NotificationService::sendNotification($userId, 'system',"normal", $notice['content']);
    }
}
