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

// Function to get PIN code using multiple APIs
function getPinCode($village, $subdistrict, $district, $state) {
    // Try postalpincode.in /postoffice/{village}
    $url = "https://api.postalpincode.in/postoffice/" . urlencode($village);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'VillageDetailsBot/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (isset($data[0]['PostOffice']) && !empty($data[0]['PostOffice'])) {
        foreach ($data[0]['PostOffice'] as $office) {
            // Try to match district/state if possible
            if ((isset($office['District']) && stripos($office['District'], $district) !== false) ||
                (isset($office['State']) && stripos($office['State'], $state) !== false)) {
                return $office['Pincode'];
            }
        }
        // If no match, return the first found
        return $data[0]['PostOffice'][0]['Pincode'];
    }

    // Try postalpincode.in /postoffice/{village, district}
    $url = "https://api.postalpincode.in/postoffice/" . urlencode("$village $district");
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'VillageDetailsBot/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (isset($data[0]['PostOffice']) && !empty($data[0]['PostOffice'])) {
        return $data[0]['PostOffice'][0]['Pincode'];
    }

    // Try postalpincode.in /postoffice/{village, state}
    $url = "https://api.postalpincode.in/postoffice/" . urlencode("$village $state");
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'VillageDetailsBot/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    if (isset($data[0]['PostOffice']) && !empty($data[0]['PostOffice'])) {
        return $data[0]['PostOffice'][0]['Pincode'];
    }

    // Try Nominatim as before
    $search_query = urlencode("$village, $subdistrict, $district, $state, India");
    $nominatim_url = "https://nominatim.openstreetmap.org/search?q=$search_query&format=json&limit=1";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $nominatim_url);
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
            return $data['address']['postcode'];
        }
    }

    // Try Google search scraping
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
        if (preg_match('/\\b\\d{6}\\b/', $response, $matches)) {
            return $matches[0];
        }
    }

    // Try nearby post office search (Nominatim)
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
            return $data['address']['postcode'];
        }
    }

    return "Not Available";
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

// Function to generate descriptive paragraph from census data
function generateVillageDescription($data) {
    $desc = "The village of " . $data['Name'] . ", classified as a " . strtolower($data['TRU']) . " settlement, ";
    
    // Population and households
    $desc .= "consists of " . $data['No_HH'] . " households with a total population of " . $data['TOT_P'] . " individuals, ";
    $desc .= "of which " . $data['TOT_M'] . " are male and " . $data['TOT_F'] . " are female. ";
    
    // Children under 6
    if ($data['P_06'] > 0) {
        $desc .= "There are " . $data['P_06'] . " children under the age of 6 (" . $data['M_06'] . " male and " . $data['F_06'] . " female), ";
    } else {
        $desc .= "There are no children under the age of 6, ";
    }
    
    // SC/ST population
    if ($data['P_SC'] > 0 || $data['P_ST'] > 0) {
        $desc .= "and the village has ";
        if ($data['P_SC'] > 0) {
            $desc .= $data['P_SC'] . " Scheduled Caste (SC) individuals (" . $data['M_SC'] . " male and " . $data['F_SC'] . " female) ";
        }
        if ($data['P_SC'] > 0 && $data['P_ST'] > 0) {
            $desc .= "and ";
        }
        if ($data['P_ST'] > 0) {
            $desc .= $data['P_ST'] . " Scheduled Tribe (ST) individuals (" . $data['M_ST'] . " male and " . $data['F_ST'] . " female) ";
        }
    } else {
        $desc .= "and the village has no Scheduled Caste (SC) or Scheduled Tribe (ST) population. ";
    }
    
    // Literacy
    if ($data['P_LIT'] > 0) {
        $desc .= "Literacy is present, with " . $data['P_LIT'] . " literate individuals (" . $data['M_LIT'] . " male and " . $data['F_LIT'] . " female) ";
        if ($data['P_ILL'] > 0) {
            $desc .= "and " . $data['P_ILL'] . " illiterate individuals (" . $data['M_ILL'] . " male and " . $data['F_ILL'] . " female). ";
        } else {
            $desc .= "and no illiterate individuals. ";
        }
    } else {
        $desc .= "There are no literate individuals in the village. ";
    }
    
    // Work status
    if ($data['TOT_WORK_P'] > 0) {
        $desc .= "In terms of work status, " . $data['TOT_WORK_P'] . " individuals are engaged in work activities (" . $data['TOT_WORK_M'] . " male and " . $data['TOT_WORK_F'] . " female). ";
        
        // Main workers
        if ($data['MAINWORK_P'] > 0) {
            $desc .= "Among these, " . $data['MAINWORK_P'] . " are main workers (" . $data['MAINWORK_M'] . " male and " . $data['MAINWORK_F'] . " female), ";
            if ($data['MAIN_CL_P'] > 0 || $data['MAIN_AL_P'] > 0 || $data['MAIN_HH_P'] > 0 || $data['MAIN_OT_P'] > 0) {
                $desc .= "including ";
                $workTypes = [];
                if ($data['MAIN_CL_P'] > 0) $workTypes[] = $data['MAIN_CL_P'] . " cultivators";
                if ($data['MAIN_AL_P'] > 0) $workTypes[] = $data['MAIN_AL_P'] . " agricultural labourers";
                if ($data['MAIN_HH_P'] > 0) $workTypes[] = $data['MAIN_HH_P'] . " household industry workers";
                if ($data['MAIN_OT_P'] > 0) $workTypes[] = $data['MAIN_OT_P'] . " other workers";
                $desc .= implode(", ", $workTypes) . ". ";
            }
        }
        
        // Marginal workers
        if ($data['MARGWORK_P'] > 0) {
            $desc .= "Additionally, " . $data['MARGWORK_P'] . " are marginal workers (" . $data['MARGWORK_M'] . " male and " . $data['MARGWORK_F'] . " female). ";
        }
    }
    
    // Non-workers
    if ($data['NON_WORK_P'] > 0) {
        $desc .= "There are " . $data['NON_WORK_P'] . " non-workers in the village (" . $data['NON_WORK_M'] . " male and " . $data['NON_WORK_F'] . " female). ";
    } else {
        $desc .= "There are no non-workers in the village, highlighting a 100% workforce participation rate. ";
    }
    
    return $desc;
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

// Get village data from database
try {
    $conn = new mysqli("in-mum-web841.main-hosting.eu", "u133954830_bharat", "u!V7ooV5LfND", "u133954830_bharat");
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Optimized query using JOINs instead of subqueries
    $sql = "SELECT v.* 
            FROM census_data v
            JOIN census_data s ON v.State = s.State AND s.Level = 'STATE' AND s.Name = ?
            JOIN census_data d ON v.State = d.State AND v.District = d.District AND d.Level = 'DISTRICT' AND d.Name = ?
            JOIN census_data sd ON v.State = sd.State AND v.District = sd.District AND v.Subdistt = sd.Subdistt AND sd.Level = 'SUB-DISTRICT' AND sd.Name = ?
            WHERE v.Level = 'VILLAGE' 
            AND v.Name = ?
            LIMIT 1";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssss", $state, $district, $subdistrict, $village);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $village_data = $result->fetch_assoc();
        $village_description = generateVillageDescription($village_data);
    } else {
        $village_description = "No detailed census data available for this village.";
    }
} catch (Exception $e) {
    $village_description = "Error fetching village data: " . $e->getMessage();
}

// Set cache headers for better performance
header('Cache-Control: public, max-age=3600'); // Cache for 1 hour
header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 3600));
header('Vary: Accept-Encoding');

// Get PIN code for the village
$village_code = getPinCode($village, $subdistrict, $district, $state);

// Generate map HTML
$map_html = google_maps_iframe($village, $subdistrict, $district, $state);

// Generate breadcrumb navigation
$base_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$state_url = cleanUrlString($state);
$district_url = cleanUrlString($district);
$subdistrict_url = cleanUrlString($subdistrict);

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

                <!-- Village Description -->
                <div class="mt-6 bg-gray-50 p-6 rounded-lg">
                    <div class="mb-4 p-4 bg-blue-50 border-l-4 border-blue-500 text-blue-700">
                        <p class="text-sm">
                            <strong>Note:</strong> The demographic and socio-economic information presented below is based on the 2011 Census of India data.
                        </p>
                    </div>
                    <h2 class="text-xl font-semibold mb-4">Village Details</h2>
                    <p class="text-gray-700 leading-relaxed">
                        <?php echo htmlspecialchars($village_description); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
// Flush output buffer
ob_end_flush();
?> 