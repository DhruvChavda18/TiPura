<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Enable output buffering
ob_start();

function cleanUrlString($str) {
    return strtolower(str_replace(['&', ' '], ['and', '-'], trim($str)));
}

function reverseCleanUrl($str) {
    // Special handling for Andaman & Nicobar Islands
    if (strpos($str, 'andaman-and-nicobar-islands') !== false) {
        return 'ANDAMAN & NICOBAR ISLANDS';
    }
    
    // Replace 'and' with '&' and hyphens with spaces
    $str = str_replace(['-and-', '-and', 'and-', '-'], [' & ', ' &', '& ', ' '], $str);
    return strtoupper(trim($str));
}

// Get parameters from URL
$state_name = $_GET['state_name'] ?? '';
$district_name = $_GET['district_name'] ?? '';
$subdistrict_name = $_GET['subdistrict_name'] ?? '';
$village_name = $_GET['village_name'] ?? '';

// Convert URL-friendly names back to proper format
$state = reverseCleanUrl($state_name);
$district = reverseCleanUrl($district_name);
$subdistrict = reverseCleanUrl($subdistrict_name);
$village = reverseCleanUrl($village_name);

// Function to get PIN code using multiple APIs with caching
function getPinCode($village, $subdistrict, $district, $state) {
    // Create cache directory if it doesn't exist
    if (!file_exists("pin_codes")) {
        mkdir("pin_codes", 0777, true);
    }

    // Generate cache key
    $cache_key = md5($village . $subdistrict . $district . $state);
    $cache_file = "pin_codes/{$cache_key}.txt";

    // Check cache first
    if (file_exists($cache_file)) {
        $cached_data = file_get_contents($cache_file);
        if ($cached_data !== false) {
            return $cached_data;
        }
    }

    // Initialize cURL multi handle
    $mh = curl_multi_init();
    $handles = [];

    // Prepare search query
    $search_query = urlencode("$village, $subdistrict, $district, $state, India");
    
    // Prepare multiple API URLs
    $api_urls = [
        "postalpincode" => "https://api.postalpincode.in/pincode/" . $search_query,
        "postalpincode2" => "https://api.postalpincode.in/postoffice/" . urlencode($village),
        "nominatim" => "https://nominatim.openstreetmap.org/search?q=$search_query&format=json&limit=1",
        "nominatim2" => "https://nominatim.openstreetmap.org/search?q=" . urlencode("$village post office, $subdistrict, $district, $state, India") . "&format=json&limit=1"
    ];

    // Initialize cURL handles for each API
    foreach ($api_urls as $key => $url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'VillageDetailsBot/1.0');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    // Execute parallel requests
    $running = null;
    do {
        curl_multi_exec($mh, $running);
    } while ($running);

    // Process responses
    $pin_code = null;
    foreach ($handles as $key => $ch) {
        $response = curl_multi_getcontent($ch);
        $data = json_decode($response, true);

        if (($key === "postalpincode" || $key === "postalpincode2") && 
            isset($data[0]['PostOffice']) && !empty($data[0]['PostOffice'])) {
            $pin_code = $data[0]['PostOffice'][0]['Pincode'];
            break;
        } elseif (($key === "nominatim" || $key === "nominatim2") && !empty($data)) {
            $lat = $data[0]['lat'];
            $lon = $data[0]['lon'];
            
            // Use reverse geocoding to get address details
            $reverse_url = "https://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lon&zoom=18&addressdetails=1";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $reverse_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'VillageDetailsBot/1.0');
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);
            if (isset($data['address']['postcode'])) {
                $pin_code = $data['address']['postcode'];
                break;
            }
        }
    }

    // Clean up
    foreach ($handles as $ch) {
        curl_multi_remove_handle($mh, $ch);
    }
    curl_multi_close($mh);

    // If still no PIN code found, try Google search pattern
    if (!$pin_code) {
        // Try different search patterns
        $search_patterns = [
            "PIN Code of $district/$subdistrict/$village",
            "PIN Code of $village village $subdistrict $district",
            "Postal Code of $village $subdistrict $district",
            "$village village PIN Code $subdistrict $district"
        ];

        foreach ($search_patterns as $pattern) {
            $search_url = "https://www.google.com/search?q=" . urlencode($pattern);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $search_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            curl_close($ch);

            // Look for 6-digit PIN code in the response
            if (preg_match('/\b\d{6}\b/', $response, $matches)) {
                $pin_code = $matches[0];
                break;
            }
        }
    }

    // If still no PIN code found, try to get from nearby post office
    if (!$pin_code) {
        $nearby_url = "https://nominatim.openstreetmap.org/search?q=" . urlencode("post office near $village, $subdistrict, $district, $state, India") . "&format=json&limit=1";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $nearby_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'VillageDetailsBot/1.0');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (!empty($data)) {
            $lat = $data[0]['lat'];
            $lon = $data[0]['lon'];
            
            $reverse_url = "https://nominatim.openstreetmap.org/reverse?format=json&lat=$lat&lon=$lon&zoom=18&addressdetails=1";
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $reverse_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, 'VillageDetailsBot/1.0');
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $response = curl_exec($ch);
            curl_close($ch);

            $data = json_decode($response, true);
            if (isset($data['address']['postcode'])) {
                $pin_code = $data['address']['postcode'];
            }
        }
    }

    // Save to cache if we found a PIN code
    if ($pin_code) {
        file_put_contents($cache_file, $pin_code);
        return $pin_code;
    }

    return "PIN Code not available";
}

// Function to generate a Google Maps iframe for a village
function google_maps_iframe($village, $taluka = '', $district = '', $state = '') {
    $address_parts = array_filter([$village, $taluka, $district, $state, 'India']);
    $address = implode(', ', $address_parts);
    $address_encoded = urlencode($address);
    $iframe_src = "https://maps.google.co.in/maps?f=q&source=s_q&hl=en&geocode=&q=$address_encoded&z=14&output=embed";
    $larger_map = "https://maps.google.co.in/maps?f=q&source=embed&hl=en&q=$address_encoded&z=14";
    return <<<HTML
<div class="relative w-full" style="height: 400px;">
    <iframe frameborder="0" scrolling="no" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%;" src="$iframe_src"></iframe>
</div>
<div class="mt-2 text-right">
    <a href="$larger_map" target="_blank" class="text-blue-600 hover:underline text-sm">View Larger Map</a>
</div>
HTML;
}

// Get PIN code for the village
$village_code = getPinCode($village, $subdistrict, $district, $state);

// Generate map HTML
$map_html = google_maps_iframe($village, $subdistrict, $district, $state);

// Generate breadcrumb navigation
$base_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$state_url = cleanUrlString($state);
$district_url = cleanUrlString($district);
$subdistrict_url = cleanUrlString($subdistrict);

// Set cache headers
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($village); ?> Village Details</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Preload critical resources -->
    <link rel="preload" href="https://maps.google.co.in/maps?f=q&source=s_q&hl=en&geocode=&q=<?php echo urlencode($village . ', ' . $subdistrict . ', ' . $district . ', ' . $state . ', India'); ?>&z=14&output=embed" as="document">
</head>
<body class="bg-gray-100 p-6">
    <div class="max-w-4xl mx-auto bg-white shadow-lg rounded-lg p-6">
        <!-- Breadcrumb Navigation -->
        <div class="mb-6 text-sm text-gray-600">
            <a href="<?php echo $base_url; ?>/state.php" class="hover:text-blue-600">States</a>
            <span class="mx-2">→</span>
            <a href="<?php echo $base_url . '/' . $state_url; ?>" class="hover:text-blue-600"><?php echo htmlspecialchars($state); ?></a>
            <span class="mx-2">→</span>
            <a href="<?php echo $base_url . '/' . $state_url . '/' . $district_url; ?>" class="hover:text-blue-600"><?php echo htmlspecialchars($district); ?></a>
            <span class="mx-2">→</span>
            <a href="<?php echo $base_url . '/' . $state_url . '/' . $district_url . '/' . $subdistrict_url; ?>" class="hover:text-blue-600"><?php echo htmlspecialchars($subdistrict); ?></a>
            <span class="mx-2">→</span>
            <span class="text-gray-800"><?php echo htmlspecialchars($village); ?></span>
        </div>

        <div class="space-y-6">
            <!-- Village Information -->
            <div class="bg-gray-50 p-6 rounded-lg">
                <h1 class="text-2xl font-bold text-gray-800 mb-4"><?php echo htmlspecialchars($village); ?> Village</h1>
                
                <p class="text-gray-600 mb-6">
                    <?php echo htmlspecialchars($village); ?> Village with PIN Code 
                    <span class="font-semibold"><?php echo $village_code; ?></span> 
                    is located in <?php echo htmlspecialchars($subdistrict); ?> Taluka of 
                    <?php echo htmlspecialchars($district); ?> district in <?php echo htmlspecialchars($state); ?>, India.
                </p>

                <h2 class="text-xl font-semibold mb-4"><?php echo htmlspecialchars($village); ?> on Google Map</h2>
                <?php echo $map_html; ?>
            </div>
        </div>
    </div>
</body>
</html>
<?php
// Flush output buffer
ob_end_flush();
?> 