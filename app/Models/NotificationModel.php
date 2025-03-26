<?php

namespace App\Models;

use PDO;
use PDOException;
use App\Config\Database;

class NotificationModel
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

    public function getPendingNotices()
    {
        $stmt = $this->db->prepare("SELECT * FROM notices WHERE ms_type = 'personal'");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getUserNotices($userId)
    {
        $stmt = $this->db->prepare("
        SELECT n.msg_id, n.title, n.content, n.send_by, n.ms_type, n.created_at, n.ms_status 
        FROM notices n
        LEFT JOIN notice_users nu ON n.msg_id = nu.notice_id AND nu.user_id = :userId
        WHERE n.ms_type = 'general' OR nu.user_id IS NOT NULL
        ORDER BY n.created_at DESC
    ");

        $stmt->execute([':userId' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllPendingNotices()
    {
        $stmt = $this->db->prepare("
        SELECT n.msg_id, n.title, n.content, n.send_by, n.ms_type, n.created_at, n.ms_status, nu.user_id
        FROM notices n
        LEFT JOIN notice_users nu ON n.msg_id = nu.notice_id
        WHERE n.ms_status = 'Active'
        ORDER BY n.created_at DESC
    ");

        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllUsers()
    {
        $stmt = $this->db->query("SELECT uid FROM users_test");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markNoticeAsSent($msgId)
    {
        $stmt = $this->db->prepare("UPDATE notices SET ms_status = 'sent' WHERE msg_id = :msgId");
        $stmt->execute([':msgId' => $msgId]);
    }

    public function getNotificationCounts(string $userId)
    {
        try {
            // Query to count all notifications
            $totalStmt = $this->db->query("SELECT COUNT(*) as total FROM notifications");
            $totalCount = $totalStmt->fetch(PDO::FETCH_ASSOC);

            // Query to count all general notices
            $generalStmt = $this->db->query("SELECT COUNT(*) as general FROM notices WHERE ms_type = 'general'");
            $generalCount = $generalStmt->fetch(PDO::FETCH_ASSOC);

            // Prepare personal notifications query with parameterized statement
            $personalStmt = $this->db->prepare("
                SELECT COUNT(*) as personal
                FROM notices
                LEFT JOIN notice_users ON notices.msg_id = notice_users.msg_id
                WHERE notices.ms_type != 'general'
                AND notices.ms_status = 'active'
                AND notice_users.user_id = :userId
            ");
            $personalStmt->bindParam(':userId', $userId, PDO::PARAM_STR);
            $personalStmt->execute();
            $personalCount = $personalStmt->fetch(PDO::FETCH_ASSOC);

            return [
                'system_notifications' => $totalCount['total'] ?? 0,
                'general_notices' => $generalCount['general'] ?? 0,
                'personal_notifications' => $personalCount['personal'] ?? 0
            ];
        } catch (PDOException $e) {
            // Log the error or handle it appropriately
            error_log('Notification count error: ' . $e->getMessage());
            return [
                'system_notifications' => 0,
                'general_notices' => 0,
                'personal_notifications' => 0
            ];
        }
    }
}
