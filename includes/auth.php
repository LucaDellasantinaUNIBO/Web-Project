<?php

include_once __DIR__ . '/../db/db_config.php';

function sec_session_start()
{
    $session_name = 'sec_session_id';
    $secure = false;
    $httponly = true;

    if (ini_set('session.use_only_cookies', 1) === FALSE) {
        header("Location: ../error.php?err=Could_not_initiate_a_safe_session");
        exit();
    }

    $cookieParams = session_get_cookie_params();
    session_set_cookie_params($cookieParams["lifetime"], $cookieParams["path"], $cookieParams["domain"], $secure, $httponly);

    session_name($session_name);
    session_start();
    session_regenerate_id(true);
}

if (session_status() === PHP_SESSION_NONE) {
    sec_session_start();
}

function login($email, $password, $mysqli)
{
    $password = hash('sha512', $password);

    $user_id = null;
    $username = null;
    $db_password = null;
    $salt = null;
    $role = null;

    if ($stmt = $mysqli->prepare("SELECT id, name, password, salt, role FROM users WHERE email = ? LIMIT 1")) {
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->store_result();

        $user_id = $username = $db_password = $salt = $role = null;
        $stmt->bind_result($user_id, $username, $db_password, $salt, $role);
        $stmt->fetch();

        if ($stmt->num_rows == 1) {
            if (checkbrute($user_id, $mysqli) == true) {
                return false;
            } else {
                $password = hash('sha512', $password . $salt);

                if ($db_password == $password) {
                    $user_browser = $_SERVER['HTTP_USER_AGENT'];
                    $user_id = preg_replace("/[^0-9]+/", "", $user_id);
                    $_SESSION['user_id'] = $user_id;
                    $_SESSION['username'] = $username;
                    $_SESSION['role'] = $role;
                    $_SESSION['login_string'] = hash('sha512', $password . $user_browser);

                    return true;
                } else {
                    $now = time();
                    $mysqli->query("INSERT INTO login_attempts(user_id, time) VALUES ('$user_id', '$now')");
                    return false;
                }
            }
        } else {
            return false;
        }
    }
}

function checkbrute($user_id, $mysqli)
{
    $now = time();
    $valid_attempts = $now - (2 * 60 * 60);

    if ($stmt = $mysqli->prepare("SELECT time FROM login_attempts WHERE user_id = ? AND time > '$valid_attempts'")) {
        $stmt->bind_param('i', $user_id);
        $stmt->execute();
        $stmt->store_result();

        if ($stmt->num_rows > 5) {
            return true;
        } else {
            return false;
        }
    }
}

function login_check($mysqli)
{
    if (isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['login_string'])) {
        $user_id = $_SESSION['user_id'];
        $login_string = $_SESSION['login_string'];
        $username = $_SESSION['username'];

        $user_browser = $_SERVER['HTTP_USER_AGENT'];

        if ($stmt = $mysqli->prepare("SELECT password FROM users WHERE id = ? LIMIT 1")) {
            $stmt->bind_param('i', $user_id);
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows == 1) {
                $password = null;
                $stmt->bind_result($password);
                $stmt->fetch();
                $login_check = hash('sha512', $password . $user_browser);

                if ($login_check == $login_string) {
                    return true;
                } else {
                    return false;
                }
            } else {
                return false;
            }
        } else {
            return false;
        }
    } else {
        return false;
    }
}

function require_login()
{
    global $mysqli;
    if (!login_check($mysqli)) {
        header('Location: login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
        exit;
    }
}

function is_admin()
{
    global $mysqli;
    if (login_check($mysqli) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
        return true;
    }
    return false;
}

function require_admin()
{
    if (!is_admin()) {
        header('Location: login.php');
        exit;
    }
}

function esc_url($url)
{
    if ('' == $url) {
        return $url;
    }

    $url = preg_replace('|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\\x80-\\xff]|i', '', $url);

    $strip = array('%0d', '%0a', '%0D', '%0A');
    $url = (string) $url;

    $count = 1;
    while ($count) {
        $url = str_replace($strip, '', $url, $count);
    }

    $url = str_replace('///', '://', $url);
    $url = htmlentities($url);

    $url = str_replace('&amp;', '&#038;', $url);
    $url = str_replace("'", '&#039;', $url);

    if ($url[0] !== '/') {
        return '';
    } else {
        return $url;
    }
}

if (isset($conn)) {
    $siteSettings = [];

    $tableCheck = mysqli_query($conn, "SHOW TABLES LIKE 'site_settings'");
    if (mysqli_num_rows($tableCheck) == 0) {
        $sql = "CREATE TABLE IF NOT EXISTS site_settings (
            setting_key VARCHAR(50) PRIMARY KEY,
            setting_value TEXT
        )";
        mysqli_query($conn, $sql);

        $defaults = [
            'site_name' => 'Housing Rentals',
            'site_description' => 'Find your perfect home with Housing Rentals. Browser properties, contact owners, and easy booking.'
        ];

        foreach ($defaults as $key => $val) {
            $stmt = mysqli_prepare($conn, "INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)");
            mysqli_stmt_bind_param($stmt, "ss", $key, $val);
            mysqli_stmt_execute($stmt);
        }
    }

    $res = mysqli_query($conn, "SELECT * FROM site_settings");
    if ($res) {
        while ($row = mysqli_fetch_assoc($res)) {
            $siteSettings[$row['setting_key']] = $row['setting_value'];
        }
    }

    $SITE_NAME = $siteSettings['site_name'] ?? 'Housing Rentals';
    $SITE_DESC = $siteSettings['site_description'] ?? 'Housing Rentals Platform';
}
