<?php
ini_set('display_errors', 0); // Don't show PHP errors to users
ini_set('log_errors', 1);     // Log errors instead
error_reporting(E_ALL);       // Still report them in logs
if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'])) {
    http_response_code(403);
    require_once __DIR__ . '/../security/403.html';
    exit;
}
include 'class/session_timeout.php';
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();
require_once 'logs/logs_trig.php';
$trigs = new Trigger();

$provinces = $mysqli->query("SELECT province_id, province_name 
                             FROM province 
                             ORDER BY UPPER(SUBSTRING(province_name, 1, 1)) ASC, province_name ASC")
                             ->fetch_all(MYSQLI_ASSOC);

// Fetch the barangay information from the database
$sqlBarangayInfo = "SELECT * FROM barangay_info";
$resultBarangayInfo = $mysqli->query($sqlBarangayInfo);

$barangayInfo = [];
if ($resultBarangayInfo->num_rows > 0) {
    $barangayInfo = $resultBarangayInfo->fetch_assoc();
} else {
    $barangayInfo = null; // No records found
}

// Fetch province name
if ($barangayInfo) {
    $provinceQuery = "SELECT province_name FROM province WHERE province_id = ?";
    $provinceStmt = $mysqli->prepare($provinceQuery);
    $provinceStmt->bind_param("i", $barangayInfo['province_id']);
    $provinceStmt->execute();
    $provinceResult = $provinceStmt->get_result();
    $provinceName = $provinceResult->fetch_assoc()['province_name'] ?? 'No record found';
} else {
    $provinceName = 'No record found';
}

// Fetch city/municipality name
if ($barangayInfo) {
    $cityQuery = "SELECT city_municipality_name FROM city_municipality WHERE city_municipality_id = ?";
    $cityStmt = $mysqli->prepare($cityQuery);
    $cityStmt->bind_param("i", $barangayInfo['city_municipality_id']);
    $cityStmt->execute();
    $cityResult = $cityStmt->get_result();
    $cityName = $cityResult->fetch_assoc()['city_municipality_name'] ?? 'No record found';
} else {
    $cityName = 'No record found';
}

// Fetch barangay name
if ($barangayInfo) {
    $barangayQuery = "SELECT barangay_name FROM barangay WHERE barangay_id = ?";
    $barangayStmt = $mysqli->prepare($barangayQuery);
    $barangayStmt->bind_param("i", $barangayInfo['barangay_id']);
    $barangayStmt->execute();
    $barangayResult = $barangayStmt->get_result();
    $barangayName = $barangayResult->fetch_assoc()['barangay_name'] ?? 'No record found';
} else {
    $barangayName = 'No record found';
}

// Barangay Address and Council Term (Handling for missing data)
$barangayAddress = isset($barangayInfo['address']) ? $barangayInfo['address'] : 'No record found';
$councilTerm = isset($barangayInfo['council_term']) ? $barangayInfo['council_term'] : 'No record found';
$telephoneNumber = isset($barangayInfo['telephone_number']) ? $barangayInfo['telephone_number'] : '';
$mobileNumber = isset($barangayInfo['mobile_number']) ? $barangayInfo['mobile_number'] : '';

// Handle Add/Edit Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['address']) && isset($_POST['council_term']) && isset($_POST['province_id']) && isset($_POST['city_municipality_id']) && isset($_POST['barangay_id']) && isset($_POST['telephone_number']) && isset($_POST['mobile_number'])) {
    $address = $_POST['address'];
    $councilTerm = $_POST['council_term'];
    $provinceId = $_POST['province_id'];
    $cityMunicipalityId = $_POST['city_municipality_id'];
    $barangayId = $_POST['barangay_id'];
    $telephoneNumber = $_POST['telephone_number'];
    $mobileNumber = $_POST['mobile_number'];

    // If no record, Insert new record
if ($barangayInfo == null) {
    $sql = "INSERT INTO barangay_info (address, council_term, province_id, city_municipality_id, barangay_id, telephone_number, mobile_number) 
            VALUES (?, ?, ?, ?, ?, ?, ?)";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("ssiiiis", $address, $councilTerm, $provinceId, $cityMunicipalityId, $barangayId, $telephoneNumber, $mobileNumber);

    if ($stmt->execute()) {
        $last_id = $stmt->insert_id;
        $trigs->isAdded(13, $last_id); // 13 = logs_name for barangay_info

        echo "<script>
        Swal.fire({
            icon: 'success',
            title: 'Success',
            text: 'Barangay Information Added Successfully!',
            confirmButtonColor: '#3085d6'
        }).then(() => {
            window.location.href = '{$redirects['barangay_info_page']}';
        });
        </script>";
    } else {
        echo "<script>
        Swal.fire({
            icon: 'error',
            title: 'Error',
            text: " . json_encode("Error updating barangay information: " . $stmt->error) . ",
            confirmButtonColor: '#d33'
        });
        </script>";
    }
    $stmt->close();
}
 
    // If record exists, Update the existing record
    else {
        $sql = "UPDATE barangay_info SET address = ?, council_term = ?, province_id = ?, city_municipality_id = ?, barangay_id = ?, telephone_number = ?, mobile_number = ? WHERE id = ?";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ssiiiisi", $address, $councilTerm, $provinceId, $cityMunicipalityId, $barangayId, $telephoneNumber, $mobileNumber, $barangayInfo['id']);

        $filename = (int)13;
        $edited_id = (int)$barangayInfo['id'];

        if ($stmt->execute()) {
            $trigs->isEdit($filename, $edited_id, $barangayInfo);
            echo "<script>
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: 'Barangay Information Updated Successfully!',
                confirmButtonColor: '#3085d6'
            }).then(() => {
                window.location.href = '{$redirects['barangay_info_page']}';
            });
            </script>";
        } else {
            echo "Error updating barangay information: " . $stmt->error;
        }
        $stmt->close();
    }
}

// Check if file is uploaded for logo
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['logo']) && $_FILES['logo']['error'] == 0) {
    // Get the file details
    $fileTmpName = $_FILES['logo']['tmp_name'];
    $fileType = $_FILES['logo']['type'];
    $logoName = $_POST['logo_name']; // Get the logo name from the form input

    // Check if file is an image
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif'];
    if (!in_array($fileType, $allowedTypes)) {
        echo "Only JPEG, PNG, and GIF files are allowed.";
    } else {
        // Read the file content
        $fileContent = file_get_contents($fileTmpName);
        // Prepare the SQL query to insert the image and logo name into the database
        $sql = "INSERT INTO logos (logo_name, logo_image, status) VALUES (?, ?, 'active')";
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("ss", $logoName, $fileContent);

        // Execute the query
        if ($stmt->execute()) {
                $last_id = $stmt->insert_id;
                $trigs->isAdded(14, $last_id); // 14 = logs_name for logos
            // Reload the page after success
            echo "<script>
            Swal.fire({
                icon: 'success',
                title: 'Uploaded',
                text: 'Logo Added Successfully!',
                confirmButtonColor: '#28a745'
            }).then(() => {
                window.location.href = '{$redirects['barangay_info_page']}';
            });
            </script>";
            exit(); 
        } else {
            echo "<script>
            Swal.fire({
                icon: 'error',
                title: 'Upload Failed',
                text: " . json_encode("Error uploading logo: " . $stmt->error) . ",
                confirmButtonColor: '#d33'
            });
            </script>";
                    }

        $stmt->close();
    }
}

// Handle changing the status of the logo
if (isset($_GET['change_status']) && isset($_GET['logo_id'])) {
    $logoId = $_GET['logo_id'];
    $currentStatus = $_GET['current_status'];
    $newStatus = $currentStatus === 'active' ? 'inactive' : 'active'; // Toggle status

    // Update the status in the database
    $sql = "UPDATE logos SET status = ? WHERE id = ?";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param("si", $newStatus, $logoId);

    if ($stmt->execute()) {
        $trigs->isStatusChange(14, $logoId);
        // After status change, redirect to reload the page
        echo "<script>
        Swal.fire({
            icon: 'success',
            title: 'Updated',
            text: 'Logo status changed successfully!',
            confirmButtonColor: '#198754'
        }).then(() => {
            window.location.href = '{$redirects['barangay_info_page']}';
        });
        </script>";
    } else {
        echo "Error updating logo status: " . $stmt->error;
    }

    $stmt->close();
}

// Fetch logos from the database
$sql = "SELECT * FROM logos";
$result = $mysqli->query($sql);

$logos = [];
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $logos[] = $row;
    }
}

// Close the connection
$mysqli->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Information and Logo Upload</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="css/BrgyInfo/BrgyInfo.css">
    <style>
        /* Custom styling for the tabs */
        .nav-pills .nav-link {
            border-radius: 0.5rem;
            padding: 10px 20px;
            font-weight: 600;
            text-align: center;
            margin: 0 5px;  /* Reduced the margin to bring the tabs closer */
        }

        .nav-pills .nav-link.active {
            background-color: #007bff;
            color: white;
        }

        /* Ensures the tabs are aligned closely next to each other */
        .nav-pills {
            display: list-item;
            justify-content: flex-start; /* Align tabs starting from the left */
            margin-bottom: 20px;
        }

        /* Add some spacing between the sections */
        .tab-content {
            margin-top: 30px;
        }

        /* Style the add logo button */
        .btn-success {
            border-radius: 20px;
            padding: 10px 20px;
            font-weight: bold;
        }

        .btn-sm {
            border-radius: 20px;
        }

        /* Increase font size for the barangay info display */
        .barangay-info {
    font-size: 1rem;
    color: #212529;
}
.barangay-info strong {
    color: #000;
    font-weight: 600;
}

  .logo-card {
        transition: transform 0.2s ease, box-shadow 0.2s;
    }

    .logo-card:hover {
        transform: scale(1.01);
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.07);
    }

    .logo-name {
        font-size: 1rem;
        font-weight: 600;
        color: #333;
        max-width: 150px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .logo-scroll-container {
        max-height: 500px;
        overflow-y: auto;
        padding-right: 5px;
    }

    </style>
    <script>
       function confirmUpload(event) {
            event.preventDefault();
            const logoName = document.getElementById('logo_name').value;

            Swal.fire({
                title: 'Confirm Upload',
                text: "Upload logo: " + logoName + "?",
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#0d6efd',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, upload'
            }).then((result) => {
                if (result.isConfirmed) {
                    event.target.submit();
                }
            });

            return false;
        }

        // Function to open larger image modal
        function openImageModal(imageSrc) {
            const modalImage = document.getElementById("modalImage");
            modalImage.src = imageSrc;
            const imageModal = new bootstrap.Modal(document.getElementById("imageModal"));
            imageModal.show();
        }

        // Confirmation dialog for status change
         const barangayInfoUrl = "<?php echo $redirects['barangay_info_admin']; ?>";

        function confirmStatusChange(logoId, currentStatus) {
            Swal.fire({
                title: 'Change Logo Status',
                text: 'Are you sure you want to update this logo status?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, change it',
                cancelButtonText: 'Cancel',
                confirmButtonColor: '#ffc107',
                cancelButtonColor: '#6c757d'
            }).then((result) => {
                if (result.isConfirmed) {
                    const finalUrl = `${barangayInfoUrl}&change_status=true&logo_id=${logoId}&current_status=${currentStatus}`;
                    window.location.href = finalUrl;
                }
            });
        }


        $(document).ready(function() {
    // When province changes, load corresponding municipalities
    $('#province').change(function() {
        var province_id = $(this).val();
        if (province_id) {
            $.ajax({
                url: 'include/get_locations.php', // Correct path to get_locations.php inside the include folder
                type: 'POST',
                data: { province_id: province_id },
                success: function(data) {
                    var response = JSON.parse(data);
                    if (response.type === 'city_municipality') {
                        $('#city_municipality').html(response.options.join('')).prop('disabled', false);
                        $('#barangay').html('<option value="">Select Barangay</option>').prop('disabled', true);
                    }
                }
            });
        } else {
            $('#city_municipality').html('<option value="">Select City/Municipality</option>').prop('disabled', true);
            $('#barangay').html('<option value="">Select Barangay</option>').prop('disabled', true);
        }
    });

    // When city/municipality changes, load corresponding barangays
    $('#city_municipality').change(function() {
        var city_id = $(this).val();
        if (city_id) {
            $.ajax({
                url: 'include/get_locations.php', // Correct path to get_locations.php inside the include folder
                type: 'POST',
                data: { municipality_id: city_id },
                success: function(data) {
                    var response = JSON.parse(data);
                    if (response.status === 'success' && response.type === 'barangay') {
                        // Clear previous barangay options
                        $('#barangay').html('<option value="">Select Barangay</option>');
                        if (response.data.length > 0) {
                            // Add new barangay options
                            $.each(response.data, function(index, barangay) {
                                $('#barangay').append('<option value="'+ barangay.id +'">'+ barangay.name +'</option>');
                            });
                        } else {
                            $('#barangay').append('<option value="">No Barangay found</option>');
                        }
                        // Enable the barangay dropdown
                        $('#barangay').prop('disabled', false);
                    } else {
                        console.log(response.message); // For debugging
                    }
                },
                error: function() {
                    alert("An error occurred while fetching the data.");
                }
            });
        } else {
            $('#barangay').html('<option value="">Select Barangay</option>').prop('disabled', true);
        }
    });
});
    </script>
</head>
<body>
<div class="container mt-5">
    <h2>Barangay Information and Logo List</h2>
    
    <!-- Nav Tabs for Barangay Information and Logo List -->
    <ul class="nav nav-pills mb-3" id="pills-tab" role="tablist">
        <li class="nav-item" role="presentation">
            <a class="nav-link active" id="pills-overview-tab" data-bs-toggle="pill" href="#pills-overview" role="tab" aria-controls="pills-overview" aria-selected="true">Barangay Information</a>
        </li>
        <li class="nav-item" role="presentation">
            <a class="nav-link" id="pills-logo-tab" data-bs-toggle="pill" href="#pills-logo" role="tab" aria-controls="pills-logo" aria-selected="false">Logo List</a>
        </li>
    </ul>
    
    <!-- Tab Content -->
    <div class="tab-content" id="pills-tabContent">
       <!-- Barangay Information Display -->
        <div class="tab-pane fade show active" id="pills-overview" role="tabpanel" aria-labelledby="pills-overview-tab">
            <!-- <h4>Barangay Information</h4> -->
            <div class="card shadow-sm border-0 p-4 mb-4">
    <div class="row gy-3">
        <div class="col-md-6"><strong class="text-dark">Province:</strong> <?php echo $provinceName; ?></div>
        <div class="col-md-6"><strong class="text-dark">City/Municipality:</strong> <?php echo $cityName; ?></div>
        <div class="col-md-6"><strong class="text-dark">Barangay Name:</strong> <?php echo $barangayName; ?></div>
        <div class="col-md-6"><strong class="text-dark">Barangay Address:</strong> <?php echo $barangayAddress; ?></div>
        <div class="col-md-6"><strong class="text-dark">Council Term:</strong> <?php echo $councilTerm; ?></div>
    </div>
    <div class="mt-4">
        <?php if ($barangayInfo == null): ?>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#editBarangayModal">Add</button>
        <?php else: ?>
            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#editBarangayModal">Edit</button>
        <?php endif; ?>
    </div>
</div>

        </div>  

        <!-- Logo List Tab -->
        <div class="tab-pane fade" id="pills-logo" role="tabpanel" aria-labelledby="pills-logo-tab">
            <h4>Logo List</h4>
            <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#logoModal">Add Logo</button>
           <div class="row g-3 logo-scroll-container">
    <?php foreach ($logos as $logo): ?>
        <div class="col-md-6">
            <div class="d-flex align-items-center justify-content-between p-3 border rounded-4 shadow-sm logo-card bg-white">
                <div class="d-flex align-items-center">
                    <img 
                        src="data:image/jpeg;base64,<?= base64_encode($logo['logo_image']); ?>" 
                        alt="<?= htmlspecialchars($logo['logo_name']); ?>" 
                        width="55" height="55" class="rounded-2 me-3 border" 
                        style="cursor:pointer; background-color: #fff;" 
                        onclick="openImageModal('data:image/jpeg;base64,<?= base64_encode($logo['logo_image']); ?>')">
                    <div>
                       <div class="logo-name"><?= htmlspecialchars($logo['logo_name']); ?></div>
                        <span class="badge <?= $logo['status'] === 'active' ? 'bg-success' : 'bg-danger'; ?>">
                            <?= ucfirst($logo['status']); ?>
                        </span>
                    </div>
                </div>
                <button class="btn btn-warning btn-sm" onclick="confirmStatusChange(<?= $logo['id']; ?>, '<?= $logo['status']; ?>')">
                    Change Status
                </button>
            </div>
        </div>
    <?php endforeach; ?>
</div>

        </div>
    </div>

</div>

<!-- Modal for editing barangay information -->
<div class="modal fade" id="editBarangayModal" tabindex="-1" aria-labelledby="editBarangayModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editBarangayModalLabel"><?php echo ($barangayInfo == null) ? 'Add Barangay Information' : 'Edit Barangay Information'; ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <!-- Barangay Address and Council Term Inputs (with Telephone and Mobile Number fields) -->
<div class="modal-body">
    <form action="" method="POST">
        <!-- Province Dropdown -->
        <div class="mb-3">
            <label for="province_id" class="form-label" style="font-weight: bold;">Province</label>
            <select class="form-control" id="province" name="province_id" required>
                <option value="" disabled>Select Province</option>
                <?php foreach ($provinces as $province): ?>
                    <option value="<?php echo $province['province_id']; ?>" <?php echo ($barangayInfo && $province['province_id'] == $barangayInfo['province_id']) ? 'selected' : ''; ?>>
                        <?php echo $province['province_name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- City/Municipality Dropdown -->
        <div class="mb-3">
            <label for="city_municipality_id" class="form-label" style="font-weight: bold;">City/Municipality</label>
            <select class="form-control" id="city_municipality" name="city_municipality_id" required>
                <option value="" disabled>Select City/Municipality</option>
            </select>
        </div>

        <!-- Barangay Name Dropdown -->
        <div class="mb-3">
            <label for="barangay_name" class="form-label" style="font-weight: bold;">Barangay Name</label>
            <select class="form-control" id="barangay" name="barangay_id" required>
                <option value="" disabled>Select Barangay</option>
            </select>
        </div>

        <!-- Barangay Address Input -->
        <div class="mb-3">
            <label for="address" class="form-label" style="font-weight: bold;">Barangay Address</label>
            <input type="text" class="form-control" id="address" name="address" value="<?php echo $barangayInfo ? $barangayInfo['address'] : ''; ?>" required>
        </div>

        <!-- Council Term Input -->
        <div class="mb-3">
            <label for="council_term" class="form-label" style="font-weight: bold;">Council Term</label>
            <input type="text" class="form-control" id="council_term" name="council_term" value="<?php echo $barangayInfo ? $barangayInfo['council_term'] : ''; ?>" required>
        </div>

        <!-- Telephone Number Input -->
        <div class="mb-3">
            <label for="telephone_number" class="form-label" style="font-weight: bold;">Telephone Number</label>
            <input type="text" class="form-control" id="telephone_number" name="telephone_number" value="<?php echo $barangayInfo ? $barangayInfo['telephone_number'] : ''; ?>" required>
        </div>

        <!-- Mobile Number Input -->
        <div class="mb-3">
            <label for="mobile_number" class="form-label" style="font-weight: bold;">Mobile Number</label>
            <input type="text" class="form-control" id="mobile_number" name="mobile_number" value="<?php echo $barangayInfo ? $barangayInfo['mobile_number'] : ''; ?>" required>
        </div>

        <!-- Submit Button -->
        <button type="submit" class="btn btn-primary">Save Changes</button>
    </form>
</div>

        </div>
    </div>
</div>

<!-- Modal for uploading logo -->
<div class="modal fade" id="logoModal" tabindex="-1" aria-labelledby="logoModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logoModalLabel">Upload Logo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form action="" method="POST" enctype="multipart/form-data" onsubmit="return confirmUpload(event);">
                    <div class="mb-3">
                        <label for="logo_name" class="form-label" style="font-weight: bold;">Logo Name</label>
                        <input type="text" class="form-control" id="logo_name" name="logo_name" required>
                    </div>
                    <div class="mb-3">
                        <label for="logo" class="form-label" style="font-weight: bold;">Logo Image</label>
                        <input type="file" class="form-control" id="logo" name="logo" accept="image/*" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Upload</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Modal for viewing larger logo -->
<div class="modal fade" id="imageModal" tabindex="-1" aria-labelledby="imageModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="imageModalLabel">View Logo</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <!-- Large image will be displayed here -->
                <img id="modalImage" src="" alt="Logo" class="img-fluid">
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
