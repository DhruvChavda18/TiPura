<?php
if (isset($_GET['ajax']) && $_GET['ajax'] == '1') {
    // AJAX REQUEST HANDLER
    $conn = new mysqli("in-mum-web841.main-hosting.eu", "u133954830_bharat", "u!V7ooV5LfND", "u133954830_bharat");
    if ($conn->connect_error) die("Connection failed: " . $conn->connect_error);

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

    // Optimized query to get IDs and data in one go
    $idQuery = "SELECT DISTINCT v1.State, v1.District, v1.Subdistt, v1.Name as SubdistrictName, 
                       s.Name as StateName, d.Name as DistrictName
                FROM VillageData v1 
                JOIN VillageData s ON v1.State = s.State 
                JOIN VillageData d ON v1.State = d.State AND v1.District = d.District
                WHERE UPPER(s.Name) = ? 
                AND UPPER(d.Name) = ?
                AND UPPER(v1.Name) = ?
                AND s.Level='STATE'
                AND d.Level='DISTRICT'
                AND v1.Level='SUB-DISTRICT'
                LIMIT 1";
    
    $stmt = $conn->prepare($idQuery);
    $stmt->bind_param("sss", $state_name, $district_name, $subdistrict_name);
    $stmt->execute();
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

        // Optimized count query
        $countSql = "SELECT COUNT(*) as total FROM VillageData $where";
        $stmt = $conn->prepare($countSql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $countResult = $stmt->get_result();
        $totalRows = $countResult->fetch_assoc()['total'];
        $totalPages = ceil($totalRows / $limit);

        // Optimized main query with specific columns
        $sql = "SELECT Town_Village, Ward, EB, Name, TRU, No_HH, TOT_P 
                FROM VillageData 
                $where
                ORDER BY Name ASC
                LIMIT ? OFFSET ?";
        
        $params[] = $limit;
        $params[] = $offset;
        $types .= "ii";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
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
                    </tr>
                </thead>
                <tbody>";

        $serial = ($page - 1) * $limit + 1;
        while ($row = $result->fetch_assoc()) {
            echo "<tr class='hover:bg-gray-100'>";
            echo "<td class='border px-4 py-2'>" . $serial++ . "</td>";
            foreach ($row as $value) {
                echo "<td class='border px-4 py-2'>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table></div>";

        // Optimized pagination
        if ($totalPages > 1) {
            echo "<div class='mt-4 flex flex-wrap gap-2 justify-center'>";
            // Previous page link
            if ($page > 1) {
                echo "<a href='#' class='pagination-link px-3 py-1 rounded border bg-white text-blue-500 hover:bg-blue-100' data-page='" . ($page - 1) . "'>Previous</a>";
            }
            // Page numbers
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            if ($startPage > 1) {
                echo "<a href='#' class='pagination-link px-3 py-1 rounded border bg-white text-blue-500 hover:bg-blue-100' data-page='1'>1</a>";
                if ($startPage > 2) echo "<span class='px-2'>...</span>";
            }
            
            for ($i = $startPage; $i <= $endPage; $i++) {
                echo "<a href='#' class='pagination-link px-3 py-1 rounded border " . ($i == $page ? "bg-blue-500 text-white" : "bg-white text-blue-500 hover:bg-blue-100") . "' data-page='$i'>$i</a>";
            }
            
            if ($endPage < $totalPages) {
                if ($endPage < $totalPages - 1) echo "<span class='px-2'>...</span>";
                echo "<a href='#' class='pagination-link px-3 py-1 rounded border bg-white text-blue-500 hover:bg-blue-100' data-page='$totalPages'>$totalPages</a>";
            }
            
            // Next page link
            if ($page < $totalPages) {
                echo "<a href='#' class='pagination-link px-3 py-1 rounded border bg-white text-blue-500 hover:bg-blue-100' data-page='" . ($page + 1) . "'>Next</a>";
            }
            echo "</div>";
        }
    } else {
        // Debug: Show all matching states and districts
        if (isset($_GET['debug'])) {
            echo "<div class='bg-gray-100 p-4 mb-4'>";
            echo "Available states in database:<br>";
            $debugQuery = "SELECT DISTINCT Name FROM VillageData WHERE Level='STATE' ORDER BY Name";
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

            $.get("villages.php", params, function(data) {
                $('#villageTableContainer').html(data);
            });
        }

        $(document).ready(function() {
            loadVillages(1);

            $('#population').on('input', function() {
                loadVillages(1);
            });

            $(document).on('click', '.pagination-link', function(e) {
                e.preventDefault();
                const page = $(this).data('page');
                loadVillages(page);
            });

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