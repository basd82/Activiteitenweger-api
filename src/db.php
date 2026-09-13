<?php

// SPDX-License-Identifier: GPL-3.0-only
// Copyright (C) 2026 Bas van den Dikkenberg

declare(strict_types=1);

function aw_db(): mysqli
{
    static $dbConnection = null;

    if ($dbConnection instanceof mysqli) {
        return $dbConnection;
    }

    require dirname(__DIR__) . '/database.php';
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    $dbConnection = new mysqli($dbserver, $dbuser, $dbww, $db);
    $dbConnection->set_charset('utf8mb4');
    $dbConnection->query("SET time_zone = '+00:00'");

    return $dbConnection;
}
