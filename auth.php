<?php
session_start();

// Set headers to allow local development
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

$usersFile = "users.csv";

function readUsers() {
    global $usersFile;
    $users = array();
    if (($handle = fopen($usersFile, "r")) !== FALSE) {
        // Skip header row
        fgetcsv($handle);
        while (($data = fgetcsv($handle)) !== FALSE) {
            $users[] = array(
                'username' => $data[0],
                'email' => $data[1],
                'password' => $data[2],
                'joinDate' => $data[3]
            );
        }
        fclose($handle);
    }
    return $users;
}

function saveUser($username, $email, $password) {
    global $usersFile;
    $joinDate = date('Y-m-d');
    
    // Check if file exists and create with header if it doesn't
    if (!file_exists($usersFile)) {
        file_put_contents($usersFile, "username,email,password,joinDate\n");
    }
    // NOTE: storing passwords in plaintext for prototype (per user request)
    $passwordPlain = $password;

    // Append with exclusive lock
    $handle = fopen($usersFile, "a+"); // open for read/write and place pointer at end
    if ($handle) {
        // Ensure the file ends with a newline to avoid concatenated rows
        $stat = fstat($handle);
        if ($stat['size'] > 0) {
            // Move pointer to last byte
            fseek($handle, -1, SEEK_END);
            $last = fgetc($handle);
            if ($last !== "\n" && $last !== "\r") {
                fwrite($handle, PHP_EOL);
            }
            // Move pointer back to end for append
            fseek($handle, 0, SEEK_END);
        }

        if (flock($handle, LOCK_EX)) {
            fputcsv($handle, array($username, $email, $passwordPlain, $joinDate));
            fflush($handle);
            flock($handle, LOCK_UN);
        } else {
            // fallback to writing without lock
            fputcsv($handle, array($username, $email, $passwordPlain, $joinDate));
        }
        fclose($handle);
    }
    
    return array(
        'username' => $username,
        'email' => $email,
        'joinDate' => $joinDate
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (isset($data['action'])) {
        if ($data['action'] === 'login') {
            $users = readUsers();
            $found = false;
            foreach ($users as $user) {
                if ($user['email'] === $data['email']) {
                    // Stored password may be hashed or plaintext (legacy). First try password_verify, then fallback to direct compare
                    $stored = $user['password'];
                    $match = false;
                    if (strlen($stored) > 0 && (strpos($stored, '$2y$') === 0 || strpos($stored, '$2a$') === 0 || strpos($stored, '$argon2') === 0)) {
                        // probably a bcrypt/argon2 hash
                        $match = password_verify($data['password'], $stored);
                    } else {
                        // legacy plaintext
                        $match = ($stored === $data['password']);
                    }

                    if ($match) {
                        $found = true;
                        echo json_encode(array(
                            'success' => true,
                            'user' => array(
                                'username' => $user['username'],
                                'email' => $user['email'],
                                'joinDate' => $user['joinDate']
                            )
                        ));
                        break;
                    }
                }
            }
            if (!$found) {
                echo json_encode(array('success' => false, 'message' => 'Invalid email or password'));
            }
        }
        else if ($data['action'] === 'signup') {
            // Check if email already exists
            $users = readUsers();
            foreach ($users as $user) {
                if ($user['email'] === $data['email']) {
                    echo json_encode(array('success' => false, 'message' => 'Email already exists'));
                    exit;
                }
            }
            
            $user = saveUser($data['username'], $data['email'], $data['password']);
            echo json_encode(array('success' => true, 'user' => $user));
        }
    }
}
?>