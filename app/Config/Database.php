<?php

namespace App\Config;

use PDO;

class Database
{
    public static function connect()
    {
        return new PDO("mysql:host=localhost;dbname=notifs_db", "enzerhub", "enzerhub");
    }
}
