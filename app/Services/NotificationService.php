<?php

namespace App\Services;

use App\Config\DatabaseAccessors;

class NotificationService
{

    public static function sendNotification($userId, $type,$event, $message)
    {
        switch ($type) {
            case 'system':
                return self::sendInAppSocketFrontend($userId, $message, $event);
            case 'sms':
                return self::sendSMS($userId, $message);
            case 'email':
                // return self::sendEmail( userId: $userId, $message);
                break;
            default:
                return false;
        }
    }

    private static function sendInAppSocketFrontend($userId, $message, $event)
    {
        echo "Attempting to connect to TCP socket...\n";
        $socket = stream_socket_client("tcp://127.0.0.1:9503", $errno, $errstr, 30);
        if (!$socket) {
            error_log("Socket connection failed: $errstr ($errno)");
            return false;
        }
        echo "Connected to TCP socket successfully\n";
        echo "Sending notification for user $userId: $message: $event \n";

        $data = json_encode(["action" => "send_notification", "user_id" => $userId, "message" => $message, "event"=> $event])."\n";
        $bytesWritten = fwrite($socket, $data);
        echo "Wrote $bytesWritten bytes to socket\n";

        fflush($socket);
        usleep(100000); // 100ms

        fclose($socket);
        echo "Socket connection closed\n";
        return true;
    }

    private static function sendSMS($userId, $message)
    {
        // Example: Twilio API
        // $phone = User::getPhoneNumber($userId);
        // $twilio = new TwilioAPI();
        // return $twilio->sendMessage($phone, $message);
    }

    private static function sendEmail($userId, $message)
    {
        // Example: PHPMailer
        // $email = User::getEmail($userId);
        // $mail = new PHPMailer(true);
        // try {
        //     $mail->setFrom('noreply@yourapp.com', 'Your App');
        //     $mail->addAddress($email);
        //     $mail->Subject = "Notification";
        //     $mail->Body = $message;
        //     return $mail->send();
        // } catch (Exception $e) {
        //     return false;
        // }
    }
}
