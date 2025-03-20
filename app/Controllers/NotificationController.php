<?php

namespace App\Controllers;

use App\Models\Notification;

class NotificationController
{
    public function send($userId, $type, $message)
    {
        $notification = new Notification();
        return $notification->create($userId, $type, $message);
    }
}
