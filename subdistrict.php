<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

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

// Debug information
if (isset($_GET['debug'])) {
    echo "<pre>";
    echo "GET parameters:\n";
    print_r($_GET);
    echo "\nRequest URI: " . $_SERVER['REQUEST_URI'] . "\n";
    echo "</pre>";
}

// Get state and district names from the URL
$state_name = $_GET['state_name'] ?? '';
$district_name = $_GET['district_name'] ?? '';

try {
    // Database connection
    $conn = new mysqli("in-mum-web841.main-hosting.eu", "u133954830_bharat", "u!V7ooV5LfND", "u133954830_bharat");
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    if ($state_name && $district_name) {
        // Convert URL-friendly names back to database format
        $search_state_name = reverseCleanUrl($state_name);
        $search_district_name = reverseCleanUrl($district_name);
        
        // Optimized query to get all required data in one go
        $sql = "SELECT DISTINCT s.State, s.District, s.Subdistt, s.Name as SubdistrictName,
                       d.Name as DistrictName, st.Name as StateName
                FROM VillageData s
                JOIN VillageData d ON s.State = d.State AND s.District = d.District
                JOIN VillageData st ON s.State = st.State
                WHERE UPPER(st.Name) = ? 
                AND UPPER(d.Name) = ?
                AND st.Level = 'STATE'
                AND d.Level = 'DISTRICT'
                AND s.Level = 'SUB-DISTRICT'
                ORDER BY s.Name ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $search_state_name, $search_district_name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $firstRow = $result->fetch_assoc();
            $districtName = $firstRow['DistrictName'];
            $stateName = $firstRow['StateName'];
            $result->data_seek(0); // Reset result pointer
        } else {
            throw new Exception("State or district not found.");
        }
    } else {
        throw new Exception("State and district must be specified.");
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tinkering India</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-gray-100 p-6">

    <div class="max-w-4xl mx-auto bg-white shadow-xl rounded-lg p-6">
        <h1 class="text-2xl font-bold text-center text-gray-800 mb-6">Subdistricts in District</h1>

        <?php if (isset($error)): ?>
            <div class='text-red-500 text-center p-4'>
                Error: <?php echo htmlspecialchars($error); ?>
            </div>
        <?php elseif (isset($districtName) && isset($stateName) && $result && $result->num_rows > 0): ?>
            <h3 class='text-xl font-semibold text-center mb-4'>
                <?php echo htmlspecialchars($districtName . ', ' . $stateName); ?>
            </h3>
            
            <div class="overflow-x-auto">
                <table class='min-w-full border border-gray-300 rounded text-center'>
                    <thead>
                        <tr class='bg-gray-200 text-gray-800'>
                            <th class='py-2 px-4 border-b'>S.No</th>
                            <th class='py-2 px-4 border-b'>Subdistrict Name</th>
                            <th class='py-2 px-4 border-b'>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $serial = 1;
                        $base_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                        while ($row = $result->fetch_assoc()): 
                            $state_url = cleanUrlString($stateName);
                            $district_url = cleanUrlString($districtName);
                            $subdistrict_url = cleanUrlString($row['SubdistrictName']);
                            $url = $base_url . '/' . $state_url . '/' . $district_url . '/' . $subdistrict_url;
                        ?>
                            <tr class='hover:bg-gray-100'>
                                <td class='py-2 px-4 border-b'><?php echo $serial++; ?></td>
                                <td class='py-2 px-4 border-b font-medium'><?php echo htmlspecialchars($row['SubdistrictName']); ?></td>
                                <td class='py-2 px-4 border-b'>
                                    <a href='<?php echo htmlspecialchars($url); ?>' 
                                       class='text-blue-600 hover:underline'>View Villages</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class='text-gray-600 text-center'>No subdistricts found in this district.</p>
        <?php endif; ?>

        <!-- Navigation -->
        <div class="mt-6 text-center space-x-4">
            <a href="<?php echo rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>/state.php" class="text-blue-600 hover:underline">Back to States List</a>
            <?php if (isset($state_url)): ?>
            <a href="<?php echo rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/' . htmlspecialchars($state_url); ?>" class="text-blue-600 hover:underline">Back to Districts</a>
            <?php endif; ?>
        </div>
    </div>

</body>

</html>
<?php
if (isset($conn)) {
    $conn->close();
}
?>