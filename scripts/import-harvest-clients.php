<?php
/**
 * Import Harvest clients to Crater
 * Run: cd ~/Astro/crater-invoicing && railway run php scripts/import-harvest-clients.php
 */

$harvestToken = '9626.pt.3nIKMokUmKfhiNJqeEXiOtvpsy787RvfLGpHZPVYbI4W3ptgbhB5JFeMVc_imjgwn3Obg3ZCe9srFUfYG0a0KQ';
$harvestAccountId = '155800';

// Fetch Harvest clients
$ch = curl_init('https://api.harvestapp.com/v2/clients?per_page=2000');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Bearer $harvestToken",
    "Harvest-Account-Id: $harvestAccountId",
    "User-Agent: EliteWebLabs (thomas@eliteweblabs.com)"
]);
$response = curl_exec($ch);
$data = json_decode($response, true);
curl_close($ch);

if (!isset($data['clients'])) {
    echo "Error fetching Harvest clients\n";
    exit(1);
}

// Connect to Crater DB (using Railway internal networking)
$dbHost = '127.0.0.1';
$dbUser = 'root';
$dbPass = 'crater';
$dbName = 'crater';

echo "Connecting to Crater DB...\n";
$conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conn->connect_error) {
    // Try alternative hosts
    $hosts = ['db.railway.internal', 'mysql.railway.internal', 'localhost'];
    foreach ($hosts as $host) {
        echo "Trying $host...\n";
        $conn = new mysqli($host, $dbUser, $dbPass, $dbName);
        if ($conn->connect_error === false) {
            echo "Connected via $host\n";
            break;
        }
    }
}

if ($conn->connect_error) {
    echo "Could not connect to database: " . $conn->connect_error . "\n";
    exit(1);
}

echo "Connected. Importing clients...\n";

$imported = 0;
$skipped = 0;

foreach ($data['clients'] as $client) {
    // Only import active clients
    if (empty($client['is_active'])) {
        $skipped++;
        continue;
    }
    
    // Check if client already exists
    $check = $conn->query("SELECT id FROM customers WHERE name = '" . $conn->real_escape_string($client['name']) . "'");
    if ($check->num_rows > 0) {
        $skipped++;
        continue;
    }
    
    // Insert client
    $name = $conn->real_escape_string($client['name']);
    $email = !empty($client['email']) ? $conn->real_escape_string($client['email']) : '';
    $phone = !empty($client['phone']) ? $conn->real_escape_string($client['phone']) : '';
    $address = !empty($client['default_task']) ? $conn->real_escape_string($client['default_task']) : '';
    
    $sql = "INSERT INTO customers (name, email, phone, company_id, contact_name, currency_id, created_at, updated_at)
            VALUES ('$name', '$email', '$phone', 1, '$name', 1, NOW(), NOW())";
    
    if ($conn->query($sql)) {
        echo "Imported: {$client['name']}\n";
        $imported++;
    } else {
        echo "Failed: {$client['name']} - " . $conn->error . "\n";
    }
}

echo "\nDone! Imported: $imported, Skipped: $skipped\n";

$conn->close();