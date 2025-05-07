<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

function cleanUrlString($str) {
    return strtolower(str_replace(['&', ' '], ['and', '-'], trim($str)));
}

// Database connection
try {
    $conn = new mysqli("in-mum-web841.main-hosting.eu", "u133954830_bharat", "u!V7ooV5LfND", "u133954830_bharat");
    if ($conn->connect_error) {
        throw new Exception("Connection failed: " . $conn->connect_error);
    }

    // Optimized query with proper indexing and caching
    $sql = "SELECT State, Name 
            FROM census_data 
            WHERE Level='STATE' 
            ORDER BY Name ASC";
    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }

    // Set cache headers for better performance
    header('Cache-Control: public, max-age=86400'); // Cache for 24 hours since state list rarely changes
    header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + 86400));
    header('Vary: Accept-Encoding');
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
        <h1 class="text-2xl font-bold text-center text-gray-800 mb-6">States of India</h1>

        <?php if (isset($error)): ?>
            <div class='text-red-500 text-center p-4'>
                Error: <?php echo htmlspecialchars($error); ?>
            </div>
        <?php elseif ($result && $result->num_rows > 0): ?>
            <div class="overflow-x-auto">
                <table class='min-w-full border border-gray-300 rounded text-center'>
                    <thead>
                        <tr class='bg-gray-200 text-gray-800'>
                            <th class='py-2 px-4 border-b'>S.No</th>
                            <th class='py-2 px-4 border-b'>State Name</th>
                            <th class='py-2 px-4 border-b'>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $serial = 1;
                        while ($row = $result->fetch_assoc()): 
                        ?>
                            <tr class='hover:bg-gray-100'>
                                <td class='py-2 px-4 border-b'><?php echo $serial++; ?></td>
                                <td class='py-2 px-4 border-b font-medium'><?php echo htmlspecialchars($row['Name']); ?></td>
                                <td class='py-2 px-4 border-b'>
                                    <a href='<?php echo htmlspecialchars(cleanUrlString($row['Name'])); ?>' 
                                       class='text-blue-600 hover:underline'>View Districts</a>
                                </td>
                            </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <p class='text-gray-600 text-center'>No states found in the database.</p>
        <?php endif; ?>

        <!-- Add a home link -->
        <div class="mt-6 text-center">
            <a href="state.php" class="text-blue-600 hover:underline">Back to States List</a>
        </div>
    </div>

</body>

</html>
<?php
if (isset($conn)) {
    $conn->close();
}
?>