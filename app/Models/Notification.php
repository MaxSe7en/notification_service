<?php

namespace App\Models;

use PDO;
use App\Config\Database;

class Notification
{
    private $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    public function create($userId, $type, $message)
    {
        $stmt = $this->db->prepare("INSERT INTO notifications (user_id, type, message, status) VALUES (?, ?, ?, 'pending')");
        return $stmt->execute([$userId, $type, $message]);
    }

    public function getPendingNotifications()
    {
        $stmt = $this->db->prepare("SELECT * FROM notifications WHERE status = 'pending'");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
