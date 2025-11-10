<?php
session_start();

header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

$postsFile = "posts.csv";

function readPosts() {
    global $postsFile;
    $posts = array();
    if (!file_exists($postsFile)) return $posts;
    if (($handle = fopen($postsFile, "r")) !== FALSE) {
        // read header
        $headers = fgetcsv($handle);
        while (($data = fgetcsv($handle)) !== FALSE) {
            // map fields
            $row = array();
            for ($i = 0; $i < count($headers); $i++) {
                $row[$headers[$i]] = isset($data[$i]) ? $data[$i] : null;
            }
            $posts[] = $row;
        }
        fclose($handle);
    }
    return $posts;
}

function savePost($username, $title, $description, $distanceKm, $geojson) {
    global $postsFile;
    $createdAt = date('c');

    if (!file_exists($postsFile)) {
        // header: id,username,title,description,distanceKm,geojson,createdAt
        file_put_contents($postsFile, "id,username,title,description,distanceKm,geojson,createdAt\n");
    }

    // Determine next id
    $nextId = 1;
    $posts = readPosts();
    if (count($posts) > 0) {
        $last = $posts[count($posts)-1];
        $nextId = intval($last['id']) + 1;
    }

    // Prepare geojson as JSON string
    $geojsonStr = is_string($geojson) ? $geojson : json_encode($geojson);

    // Append safely
    $handle = fopen($postsFile, "a+");
    if ($handle) {
        $stat = fstat($handle);
        if ($stat['size'] > 0) {
            fseek($handle, -1, SEEK_END);
            $last = fgetc($handle);
            if ($last !== "\n" && $last !== "\r") {
                fwrite($handle, PHP_EOL);
            }
            fseek($handle, 0, SEEK_END);
        }
        if (flock($handle, LOCK_EX)) {
            fputcsv($handle, array($nextId, $username, $title, $description, $distanceKm, $geojsonStr, $createdAt));
            fflush($handle);
            flock($handle, LOCK_UN);
        } else {
            fputcsv($handle, array($nextId, $username, $title, $description, $distanceKm, $geojsonStr, $createdAt));
        }
        fclose($handle);
    }

    return array('id' => $nextId, 'username' => $username, 'title' => $title, 'description' => $description, 'distanceKm' => $distanceKm, 'geojson' => $geojson, 'createdAt' => $createdAt);
}

// Handle requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        echo json_encode(array('success' => false, 'message' => 'Invalid JSON'));
        exit;
    }
    if (isset($data['action']) && $data['action'] === 'create') {
        $username = isset($data['username']) ? $data['username'] : 'Anonymous';
        $title = isset($data['title']) ? $data['title'] : '';
        $description = isset($data['description']) ? $data['description'] : '';
        $distanceKm = isset($data['distanceKm']) ? $data['distanceKm'] : 0;
        $geojson = isset($data['geojson']) ? $data['geojson'] : '';

        $post = savePost($username, $title, $description, $distanceKm, $geojson);
        echo json_encode(array('success' => true, 'post' => $post));
        exit;
    }
}

// GET: list posts
$posts = readPosts();
// return in reverse order (newest first)
$posts = array_reverse($posts);
echo json_encode(array('success' => true, 'posts' => $posts));

?>