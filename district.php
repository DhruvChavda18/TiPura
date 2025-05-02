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

// Get state name from the URL
$state_name = isset($_GET['state_name']) ? $_GET['state_name'] : '';

try {
    // Database connection
    $conn = new mysqli("in-mum-web841.main-hosting.eu", "u133954830_bharat", "u!V7ooV5LfND", "u133954830_bharat");
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    if ($state_name) {
        // Convert URL-friendly state name back to database format
        $search_state_name = reverseCleanUrl($state_name);
        
        // Optimized query to get state and districts in one go
        $sql = "SELECT DISTINCT d.State, d.District, d.Name as DistrictName, s.Name as StateName
                FROM VillageData d
                JOIN VillageData s ON d.State = s.State 
                WHERE UPPER(s.Name) = ? 
                AND s.Level = 'STATE'
                AND d.Level = 'DISTRICT'
                ORDER BY d.Name ASC";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $search_state_name);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows > 0) {
            $firstRow = $result->fetch_assoc();
            $stateName = $firstRow['StateName'];
            $result->data_seek(0); // Reset result pointer
        } else {
            throw new Exception("State not found: " . htmlspecialchars($search_state_name));
        }
    } else {
        throw new Exception("No state specified.");
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
        <h1 class="text-2xl font-bold text-center text-gray-800 mb-6">Districts in State</h1>

        <?php if (isset($error)): ?>
            <div class='text-red-500 text-center p-4'>
                Error: <?php echo htmlspecialchars($error); ?>
            </div>
        <?php elseif (isset($stateName) && $result && $result->num_rows > 0): ?>
            <h3 class='text-xl font-semibold text-center mb-4'><?php echo htmlspecialchars($stateName); ?></h3>
            
            <div class="overflow-x-auto">
                <table class='min-w-full border border-gray-300 rounded text-center'>
                    <thead>
                        <tr class='bg-gray-200 text-gray-800'>
                            <th class='py-2 px-4 border-b'>S.No</th>
                            <th class='py-2 px-4 border-b'>District Name</th>
                            <th class='py-2 px-4 border-b'>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $serial = 1;
                        $base_url = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
                        while ($row = $result->fetch_assoc()): 
                            $state_url = cleanUrlString($stateName);
                            $district_url = cleanUrlString($row['DistrictName']);
                            $url = $base_url . '/' . $state_url . '/' . $district_url;
                        ?>
                            <tr class='hover:bg-gray-100'>
                                <td class='py-2 px-4 border-b'><?php echo $serial++; ?></td>
                                <td class='py-2 px-4 border-b font-medium'><?php echo htmlspecialchars($row['DistrictName']); ?></td>
                                <td class='py-2 px-4 border-b'>
                                    <a href='<?php echo htmlspecialchars($url); ?>' 
                                       class='text-blue-600 hover:underline'>View Sub-Districts</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class='text-gray-600 text-center'>No districts found in this state.</p>
        <?php endif; ?>

        <div class="mt-6 text-center">
            <a href="<?php echo rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); ?>/state.php" 
               class="text-blue-600 hover:underline">Back to States List</a>
        </div>
    </div>

</body>

</html>
<?php
if (isset($conn)) {
    $conn->close();
}
?>