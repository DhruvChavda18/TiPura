<?php
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    // AJAX REQUEST HANDLER
    $conn = new mysqli("in-mum-web841.main-hosting.eu", "u133954830_bharat", "u!V7ooV5LfND", "u133954830_bharat");
    if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

    // Function to clean URL strings
    function cleanUrlString($str) {
        return strtolower(str_replace(['&', ' '], ['and', '-'], trim($str)));
    }

    // Get base URL
    $base_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

    // Function to get village details from cache or fetch new
    function getVillageDetails($conn, $state, $district, $subdistrict, $village) {
        // Try to get from cache first
        $cacheQuery = "SELECT * FROM village_details_cache 
                      WHERE state_code = ? AND district_code = ? 
                      AND subdistrict_code = ? AND village_code = ?";
        $stmt = $conn->prepare($cacheQuery);
        $stmt->bind_param("ssss", $state, $district, $subdistrict, $village);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }

        // If not in cache, fetch from APIs
        $details = fetchVillageDetails($state, $district, $subdistrict, $village);
        
        // Save to cache
        if ($details) {
            $insertQuery = "INSERT INTO village_details_cache 
                          (state_code, district_code, subdistrict_code, village_code, 
                           taluka_code, census_code, latitude, longitude) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $conn->prepare($insertQuery);
            $stmt->bind_param("ssssssdd", 
                $state, $district, $subdistrict, $village,
                $details['taluka_code'], $details['census_code'],
                $details['latitude'], $details['longitude']
            );
            $stmt->execute();
        }
        
        return $details;
    }

    // Function to fetch village details from APIs
    function fetchVillageDetails($state, $district, $subdistrict, $village) {
        // Generate taluka code (first 3 letters of subdistrict)
        $taluka_code = 'TC-' . substr(preg_replace('/[^A-Z]/', '', strtoupper($subdistrict)), 0, 3);
        
        // Generate census code (combination of district and subdistrict codes)
        $census_code = 'CC-' . substr(preg_replace('/[^A-Z]/', '', strtoupper($district)), 0, 3) . 
                      substr(preg_replace('/[^A-Z]/', '', strtoupper($subdistrict)), 0, 3);

        // Get coordinates from OpenStreetMap Nominatim API
        $search_query = urlencode("$village, $subdistrict, $district, $state, India");
        $nominatim_url = "https://nominatim.openstreetmap.org/search?q=$search_query&format=json&limit=1";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $nominatim_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'VillageDetailsBot/1.0');
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if (!empty($data)) {
            return [
                'taluka_code' => $taluka_code,
                'census_code' => $census_code,
                'latitude' => $data[0]['lat'],
                'longitude' => $data[0]['lon']
            ];
        }
        
        return null;
    }

    // Function to generate a Google Maps iframe for a village
    function google_maps_iframe($village, $taluka = '', $district = '', $state = '') {
        $address_parts = array_filter([$village, $taluka, $district, $state, 'India']);
        $address = implode(', ', $address_parts);
        $address_encoded = urlencode($address);
        $iframe_src = "https://maps.google.co.in/maps?f=q&source=s_q&hl=en&geocode=&q=$address_encoded&z=14&output=embed";
        $larger_map = "https://maps.google.co.in/maps?f=q&source=embed&hl=en&q=$address_encoded&z=14";
        return <<<HTML
<iframe frameborder="0" scrolling="no" height="400px" width="100%" src="$iframe_src"></iframe>
<small>
  <a href="$larger_map" target="_blank">View Larger Map</a>
</small>
HTML;
    }

    // Handle village details request
    if (isset($_GET['get_details'])) {
        $state = $_GET['state'] ?? '';
        $district = $_GET['district'] ?? '';
        $subdistrict = $_GET['subdistrict'] ?? '';
        $village = $_GET['village'] ?? '';
        // Compose the map iframe
        $map_html = google_maps_iframe($village, $subdistrict, $district, $state);
        // Return as part of the JSON response
        $details = [
            'taluka_code' => 'Not available',
            'census_code' => 'Not available',
            'latitude' => null,
            'longitude' => null,
            'map_html' => $map_html
        ];
        header('Content-Type: application/json');
        echo json_encode($details);
        exit;
    }

    // Get parameters
    $state_name = $_GET['state_name'] ?? '';
    $district_name = $_GET['district_name'] ?? '';
    $subdistrict_name = $_GET['subdistrict_name'] ?? '';
    $population = isset($_GET['population']) && is_numeric($_GET['population']) && $_GET['population'] !== '' ? intval($_GET['population']) : null;
    $page = intval($_GET['page'] ?? 1);
    $limit = 100;
    $offset = ($page - 1) * $limit;

    // Special handling for states with '&'
    $state_name = str_replace('-and-', ' & ', $state_name);
    $state_name = str_replace('-', ' ', $state_name);
    $state_name = strtoupper($state_name);

    // Special handling for districts with '&'
    $district_name = str_replace('-and-', ' & ', $district_name);
    $district_name = str_replace('-', ' ', $district_name);
    $district_name = strtoupper($district_name);

    // Handle subdistrict names
    $subdistrict_name = str_replace('-', ' ', $subdistrict_name);
    $subdistrict_name = strtoupper($subdistrict_name);

    // Debug information
    if (isset($_GET['debug'])) {
        echo "<div class='bg-gray-100 p-4 mb-4'>";
        echo "Processed names:<br>";
        echo "State: " . htmlspecialchars($state_name) . "<br>";
        echo "District: " . htmlspecialchars($district_name) . "<br>";
        echo "Subdistrict: " . htmlspecialchars($subdistrict_name) . "<br>";
        echo "</div>";
    }

    // Optimized query to get IDs and data in one go with proper indexing
    $idQuery = "SELECT v1.State, v1.District, v1.Subdistt, v1.Name as SubdistrictName, 
                       s.Name as StateName, d.Name as DistrictName
                FROM census_data v1 
                JOIN census_data s ON v1.State = s.State 
                JOIN census_data d ON v1.State = d.State AND v1.District = d.District
                WHERE UPPER(s.Name) = ? 
                AND UPPER(d.Name) = ?
                AND UPPER(v1.Name) = ?
                AND s.Level='STATE'
                AND d.Level='DISTRICT'
                AND v1.Level='SUB-DISTRICT'
                LIMIT 1";
    
    $stmt = $conn->prepare($idQuery);
    if ($stmt === false) {
        die("Query preparation failed: " . $conn->error);
    }
    
    $stmt->bind_param("sss", $state_name, $district_name, $subdistrict_name);
    if (!$stmt->execute()) {
        die("Query execution failed: " . $stmt->error);
    }
    
    $idResult = $stmt->get_result();

    // Debug information for query
    if (isset($_GET['debug'])) {
        echo "<div class='bg-gray-100 p-4 mb-4'>";
        echo "ID Query results:<br>";
        echo "Number of results: " . $idResult->num_rows . "<br>";
        if ($idResult->num_rows > 0) {
            $row = $idResult->fetch_assoc();
            echo "Found IDs:<br>";
            echo "State: " . htmlspecialchars($row['State']) . "<br>";
            echo "District: " . htmlspecialchars($row['District']) . "<br>";
            echo "Subdistrict: " . htmlspecialchars($row['Subdistt']) . "<br>";
        }
        echo "</div>";
    }

    if ($idResult->num_rows > 0) {
        $idRow = $idResult->fetch_assoc();
        $state = $idRow['State'];
        $district = $idRow['District'];
        $subdistrict = $idRow['Subdistt'];

        // Build the WHERE clause for the main query
        $where = "WHERE State = ? AND District = ? AND Subdistt = ?";
        $params = [$state, $district, $subdistrict];
        $types = "sss";

        if ($population !== null) {
            $where .= " AND TOT_P > ?";
            $params[] = $population;
            $types .= "i";
        }

        // Optimized count query with proper indexing
        $countSql = "SELECT COUNT(*) as total 
                     FROM census_data 
                     $where";
        $stmt = $conn->prepare($countSql);
        if ($stmt === false) {
            die("Count query preparation failed: " . $conn->error);
        }
        
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            die("Count query execution failed: " . $stmt->error);
        }
        
        $countResult = $stmt->get_result();
        $totalRows = $countResult->fetch_assoc()['total'];
        $totalPages = ceil($totalRows / $limit);

        // Optimized main query with specific columns and proper indexing
        $sql = "SELECT Town_Village, Ward, EB, Name, TRU, No_HH, TOT_P,
                       State, District, Subdistt
                FROM census_data 
                $where
                ORDER BY Name ASC
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";
        
        $stmt = $conn->prepare($sql);
        if ($stmt === false) {
            die("Main query preparation failed: " . $conn->error);
        }
        
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            die("Main query execution failed: " . $stmt->error);
        }
        
        $result = $stmt->get_result();

        // Output the table with optimized HTML
        echo "<div class='overflow-x-auto'>";
        echo "<table class='min-w-full text-sm text-gray-800 border border-gray-300 rounded'>
                <thead class='bg-gray-200'>
                    <tr>
                        <th class='px-4 py-2 border'>Sr. No.</th>
                        <th class='px-4 py-2 border'>Village</th>
                        <th class='px-4 py-2 border'>Ward</th>
                        <th class='px-4 py-2 border'>EB</th>
                        <th class='px-4 py-2 border'>Name</th>
                        <th class='px-4 py-2 border'>TRU</th>
                        <th class='px-4 py-2 border'>No_HH</th>
                        <th class='px-4 py-2 border'>TOT_P</th>
                        <th class='px-4 py-2 border'>Details</th>
                    </tr>
                </thead>
                <tbody>";

        $serial = ($page - 1) * $limit + 1;
        while ($row = $result->fetch_assoc()) {
            echo "<tr class='hover:bg-gray-100'>";
            echo "<td class='border px-4 py-2'>" . $serial++ . "</td>";
            foreach ($row as $key => $value) {
                if ($key !== 'State' && $key !== 'District' && $key !== 'Subdistt') {
                    echo "<td class='border px-4 py-2'>" . htmlspecialchars($value) . "</td>";
                }
            }
            // Add details button with real village name
            $state_url = cleanUrlString($state_name);
            $district_url = cleanUrlString($district_name);
            $subdistrict_url = cleanUrlString($subdistrict_name);
            $village_url = cleanUrlString($row['Name']);
            $details_url = $base_url . '/' . $state_url . '/' . $district_url . '/' . $subdistrict_url . '/' . $village_url;
            
            echo "<td class='border px-4 py-2'>
                    <a href='" . htmlspecialchars($details_url) . "' 
                       class='bg-blue-500 text-white px-3 py-1 rounded hover:bg-blue-600 inline-block'>
                        View Details
                    </a>
                  </td>";
            echo "</tr>";
        }
        echo "</tbody></table></div>";

        // Add pagination if needed
        if ($totalPages > 1) {
            echo "<div class='mt-4 flex justify-center gap-2'>";
            for ($i = 1; $i <= $totalPages; $i++) {
                $active = $i === $page ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700';
                echo "<a href='#' onclick='loadVillages($i); return false;' 
                         class='pagination-link px-3 py-1 rounded $active' 
                         data-page='$i'>$i</a>";
            }
            echo "</div>";
        }
    } else {
        // Debug: Show all matching states and districts
        if (isset($_GET['debug'])) {
            echo "<div class='bg-gray-100 p-4 mb-4'>";
            echo "Available states in database:<br>";
            $debugQuery = "SELECT DISTINCT Name FROM census_data WHERE Level='STATE' ORDER BY Name";
            $debugResult = $conn->query($debugQuery);
            while ($row = $debugResult->fetch_assoc()) {
                echo "- " . htmlspecialchars($row['Name']) . "<br>";
            }
            echo "</div>";
        }
        echo "<p class='text-red-500'>Location not found.</p>";
    }

    $conn->close();
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Tinkering India</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Add Leaflet CSS and JS -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        #villageMap {
            height: 300px;
            width: 100%;
            border-radius: 0.5rem;
        }
        .modal-content {
            max-width: 600px;
            width: 90%;
        }
    </style>
</head>

<body class="bg-gray-100 p-6">
    <div class="max-w-6xl mx-auto bg-white shadow-lg rounded-lg p-6">
        <?php
        // Get the names from URL and convert them back to proper format
        $state_name = isset($_GET['state_name']) ? str_replace('-', ' ', strtoupper($_GET['state_name'])) : '';
        $district_name = isset($_GET['district_name']) ? str_replace('-', ' ', strtoupper($_GET['district_name'])) : '';
        $subdistrict_name = isset($_GET['subdistrict_name']) ? str_replace('-', ' ', strtoupper($_GET['subdistrict_name'])) : '';

        // Special handling for states and districts with 'and'
        $state_name = str_replace('-and-', ' & ', $state_name);
        $district_name = str_replace('-and-', ' & ', $district_name);
        ?>
        <h1 class="text-2xl font-bold mb-4 text-center text-gray-700">Villages in Sub District</h1>
        <h2 class="text-xl font-semibold text-center mb-6 text-gray-600"><?php echo htmlspecialchars($subdistrict_name . ', ' . $district_name . ', ' . $state_name); ?></h2>

        <!-- Filter -->
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mb-4">
            <div>
                <label for="population" class="mr-2 font-medium text-gray-600">Minimum Population:</label>
                <input type="number" id="population" class="border p-2 rounded w-40" placeholder="Enter min pop..." />
            </div>
            <button id="downloadCSV" class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">Download CSV</button>
        </div>

        <!-- Data Table -->
        <div id="villageTableContainer">
            <!-- AJAX will load data here -->
        </div>
    </div>

    <script>
        function getParams(overrides = {}) {
            const urlParams = new URLSearchParams(window.location.pathname);
            const pathParts = window.location.pathname.split('/').filter(Boolean);
            return {
                state_name: pathParts[pathParts.length - 3] || '',
                district_name: pathParts[pathParts.length - 2] || '',
                subdistrict_name: pathParts[pathParts.length - 1] || '',
                population: overrides.population ?? '',
                page: overrides.page ?? 1,
                ajax: 1
            };
        }

        function loadVillages(page = 1) {
            const populationInput = $('#population').val().trim();
            const hasPopulationFilter = populationInput !== '';
            const params = getParams({
                page: page,
                population: hasPopulationFilter ? populationInput : ''
            });
            $.get("/pura/villages.php", params, function(data) {
                $('#villageTableContainer').html(data);
            });
        }

        $(document).ready(function() {
            // Initialize village table
            loadVillages(1);
            // Add event listeners for filters and pagination
            $('#population').on('input', function() {
                loadVillages(1);
            });
            $(document).on('click', '.pagination-link', function(e) {
                e.preventDefault();
                const page = $(this).data('page');
                loadVillages(page);
            });
            // CSV download functionality
            $('#downloadCSV').on('click', function() {
                const rows = [];
                $("#villageTableContainer table tbody tr").each(function() {
                    const row = [];
                    $(this).find('td').each(function() {
                        row.push('"' + $(this).text().replace(/"/g, '""') + '"');
                    });
                    rows.push(row.join(","));
                });
                const csvContent = "data:text/csv;charset=utf-8," + rows.join("\n");
                const encodedUri = encodeURI(csvContent);
                const link = document.createElement("a");
                link.setAttribute("href", encodedUri);
                link.setAttribute("download", "villages_filtered.csv");
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            });
        });
    </script>
</body>

</html>