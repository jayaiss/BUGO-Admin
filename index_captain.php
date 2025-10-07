<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/include/connection.php';
$mysqli = db_connection();
include 'class/session_timeout.php';
require_once './include/encryption.php';
require_once  './include/redirects.php';

// Disable caching
header("Cache-Control: no-cache, no-store, must-revalidate"); // HTTP 1.1.
header("Pragma: no-cache"); // HTTP 1.0.
header("Expires: 0"); // Proxies.

// Redirect to dashboard if already logged in

// Check for session and user role
if (!isset($_SESSION['username']) || $mysqli->connect_error) {
    http_response_code(404);
    header('Content-Type: text/html; charset=UTF-8');
    require_once __DIR__ . '../security/404.html';
    exit;
}

$loggedInUsername = $_SESSION['username'] ?? null;
$profile = $mysqli->prepare("
    SELECT employee_fname, employee_mname, employee_lname, employee_sname,
           employee_email, employee_civil_status, profilePicture
    FROM employee_list
    WHERE employee_username = ?
");
$profile->bind_param("s", $loggedInUsername);
$profile->execute();
$result = $profile->get_result();
$employee = $result->fetch_assoc();

$stmt = $mysqli->prepare("
    SELECT el.employee_fname, el.employee_id, er.Role_Name 
    FROM employee_list el
    JOIN employee_roles er ON el.Role_Id = er.Role_Id
    WHERE el.employee_username = ?
");

if ($stmt) {
    $stmt->bind_param("s", $loggedInUsername);
    $stmt->execute();
    $stmt->bind_result($userName, $employee_id, $roleName);
    $stmt->fetch();
    $stmt->close();

    $_SESSION['employee_id'] = $employee_id;
    $_SESSION['Role_Name'] = $roleName;

    $roleNameLower = strtolower($roleName);
    if (strpos($roleNameLower, 'punong barangay') === false) {
        header("Location: index.php");
        exit();
    }
}
 else {
    die('Prepare failed: ' . htmlspecialchars($mysqli->error));
}
require_once './logs/logs_trig.php';

$trigger = new Trigger();
// Check if logout request is made
if (isset($_POST['logout']) && $_POST['logout'] === 'true') {
          $trigger->isLogout(7, $employee_id);
    // Unset and destroy session
    session_unset();  // Unset all session variables
    session_destroy(); // Destroy the session
    header("Location: index.php"); // Redirect to login page after logout
    exit();
}
// $currentDate = new DateTime();
// $sixtyDaysAgo = (clone $currentDate)->sub(new DateInterval('P60D'))->format('Y-m-d');

// // Query for new notifications only
// $query = "
//     SELECT case_num, case_date_filed, case_firstC_hearing
//     FROM case_info
//     WHERE case_date_filed <= ?
//     AND case_firstC_hearing = '0000-00-00 00:00:00'

//     ORDER BY case_date_filed DESC
// ";

// $stmt = $mysqli->prepare($query);
// if ($stmt) {
//     $stmt->bind_param("s", $sixtyDaysAgo);
//     $stmt->execute();
//     $result = $stmt->get_result();
//     $notifications = $result->fetch_all(MYSQLI_ASSOC);
//     $newCount = count($notifications);
//     $stmt->close();
// } else {
//     die('Prepare failed: ' . htmlspecialchars($mysqli->error));
// }

// Fetch barangay info from the database (assuming you already have the 'barangay_info' table)
$barangayInfoSql = "SELECT 
                        bm.city_municipality_name, 
                        b.barangay_name
                    FROM barangay_info bi
                    LEFT JOIN city_municipality bm ON bi.city_municipality_id = bm.city_municipality_id
                    LEFT JOIN barangay b ON bi.barangay_id = b.barangay_id
                    WHERE bi.id = 1"; // Adjust the 'WHERE' condition to get the correct row, this could be dynamic

$barangayInfoResult = $mysqli->query($barangayInfoSql);

if ($barangayInfoResult->num_rows > 0) {
    $barangayInfo = $barangayInfoResult->fetch_assoc();

    // Get the barangay name and remove "(Pob.)" if it exists
    $barangayName = $barangayInfo['barangay_name'];
    $barangayName = (preg_replace('/\s*\(Pob\.\)\s*/', '', $barangayName)); // Remove "(Pob.)" if it exists

    // If 'Barangay' is present first, just show the barangay name as is
    if (stripos($barangayName, "Barangay") !== false) {
        // Remove any extra "(Pob.)" and leave the name as is
        $barangayName = ($barangayName);
    }
    // If 'Pob' is found but 'Barangay' is not, prepend "POBLACION"
    else if (stripos($barangayName, "Pob") !== false && stripos($barangayName, "Poblacion") === false) {
        $barangayName = "Poblacion " .($barangayName); // Add "POBLACION" and convert to uppercase
    }
    // If 'Poblacion' is already in the name, don't add "POBLACION" again
    else if (stripos($barangayName, "Poblacion") !== false) {
        $barangayName = ($barangayName); // Keep "Poblacion" name as is
    }
    // If neither 'Barangay' nor 'Pob' is found, add 'Barangay' to the name
    else {
        $barangayName = "Barangay " . ($barangayName); // Add "BARANGAY" and convert to uppercase
    }
} else {
    $barangayName = "NO BARANGAY FOUND";
}

$logo_sql = "SELECT * FROM logos WHERE logo_name LIKE '%Barangay%' AND status = 'active' LIMIT 1";
$logo_result = $mysqli->query($logo_sql);

$logo = null;
if ($logo_result->num_rows > 0) {
    // Fetch the logo details
    $logo = $logo_result->fetch_assoc();
}

$mysqli->close();
// function enc_page($name) {
//     return 'index_admin.php?page=' . urlencode(encrypt($name));}
//     $page = isset($_GET['page']) ? decrypt($_GET['page']) : 'admin_dashboard';

?>


<!DOCTYPE html>
<html lang="en">
    <head>
        <meta charset="utf-8" />
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="description" content="" />
        <meta name="author" content="" />
        
        <title>LGU BUGO - Barangay Captain</title>
        <link rel="stylesheet" href="css/form.css">
        <!-- <link rel="stylesheet" href="assets/css/form_print.css"> -->
        <link href="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/style.min.css" rel="stylesheet" />
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/popper.js/2.11.6/umd/popper.min.js"></script>
    <script src="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
        <link href="css/styles.css" rel="stylesheet" />
    <link href="https://maxcdn.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" rel="stylesheet">
        <script src="https://use.fontawesome.com/releases/v6.3.0/js/all.js" crossorigin="anonymous"></script>
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
            <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
            <link rel="icon" type="image/png" href="assets/logo/logo.png">
            <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    </head>
    <body class="sb-nav-fixed">
        <nav class="sb-topnav navbar navbar-expand navbar-dark bg-dark">
            <!-- Navbar Brand-->
            <a class="navbar-brand ps-3" > <?php if ($logo): ?>
    <img src="data:image/jpeg;base64,<?php echo base64_encode($logo['logo_image']); ?>" alt="Barangay Bugo Logo" style="width: 40px; height: auto; margin-right: 10px; filter: brightness(0) invert(1);">
    <?php else: ?>
        <p>No active Barangay logo found.</p>
    <?php endif; ?>
    <?php echo $barangayName?>
</a>

            <!-- Sidebar Toggle-->
            <button class="btn btn-link btn-sm order-1 order-lg-0 me-4 me-lg-0" id="sidebarToggle" href="#!"><i class="fas fa-bars"></i></button>
            <!-- Navbar Search-->
             <?php require_once 'util/helper/router.php';?>
            <ul class="navbar-nav ms-auto me-4">
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle text-white d-flex align-items-center" href="#" id="profileDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <?php if (!empty($employee['profilePicture'])): ?>
                            <img src="data:image/jpeg;base64,<?= base64_encode($employee['profilePicture']) ?>" class="rounded-circle me-2" style="width: 32px; height: 32px; object-fit: cover;">
                        <?php else: ?>
                            <i class="fas fa-user-circle me-2 fs-4"></i>
                        <?php endif; ?>
                        <?= htmlspecialchars($employee['employee_fname'] ?? 'Profile') ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="profileDropdown">
                        <li><a class="dropdown-item" href="<?= get_role_based_action('profile') ?>">View Profile</a></li>
                        <li><a class="dropdown-item" href="<?= get_role_based_action('settings') ?>">Settings</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php" onclick="return confirmLogout();">Logout</a></li>
                    </ul>
                </li>
            </ul>
            <!-- Navbar-->
        </nav>
        <!-- Profile Modal -->
<div class="modal fade" id="profileModal" tabindex="-1" aria-labelledby="profileModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content">
      <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="profileModalLabel"><i class="fas fa-user-circle me-2"></i>Employee Profile</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body row">
        <div class="col-md-4 text-center">
          <?php if (!empty($employee['profilePicture'])): ?>
              <img src="data:image/jpeg;base64,<?= base64_encode($employee['profilePicture']) ?>" class="rounded-circle img-fluid" style="width: 150px; height: 150px; object-fit: cover;">
          <?php else: ?>
              <i class="fas fa-user-circle text-secondary" style="font-size: 150px;"></i>
          <?php endif; ?>
        </div>
        <div class="col-md-8">
          <p><strong>Full Name:</strong> <?= htmlspecialchars($employee['employee_fname'] . ' ' . $employee['employee_mname'] . ' ' . $employee['employee_lname'] . ' ' . $employee['employee_sname']) ?></p>
          <p><strong>Email:</strong> <?= htmlspecialchars($employee['employee_email']) ?></p>
          <p><strong>Civil Status:</strong> <?= htmlspecialchars($employee['employee_civil_status']) ?></p>
        </div>
      </div>
    </div>
  </div>
</div>
        <div id="layoutSidenav" class="container-fluid">
            <div id="layoutSidenav_nav">
                <nav class="sb-sidenav accordion sb-sidenav-dark" id="sidenavAccordion">
                    <div class="sb-sidenav-menu">
                        <div class="nav">
                            <div class="sb-sidenav-menu-heading">Core</div>
                            <!-- Encrypted Sidebar Links -->
                                <a class="nav-link <?php echo ($page === 'admin_dashboard') ? '' : 'collapsed'; ?>" href="index_captain.php?page=<?php echo urlencode(encrypt('admin_dashboard')); ?>">
                                <div class="sb-nav-link-icon"><i class="fas fa-tachometer-alt"></i></div> Dashboard
                                </a>

                                <a class="nav-link <?php echo ($page === 'resident_info') ? '' : 'collapsed'; ?>" href="index_captain.php?page=<?php echo urlencode(encrypt('resident_info')); ?>">
                                <div class="sb-nav-link-icon"><i class="fas fa-users"></i></div> Resident List
                                </a>

                                <a class="nav-link <?php echo ($page === 'feedbacks') ? '' : 'collapsed'; ?>" href="index_captain.php?page=<?php echo urlencode(encrypt('feedbacks')); ?>">
                                <div class="sb-nav-link-icon"><i class="fas fa-comment-dots"></i></div> Feedback
                                </a>
                                 <a class="nav-link <?php echo ($page === 'reports') ? '' : 'collapsed'; ?>" href="index_captain.php?page=<?php echo urlencode(encrypt('reports')); ?>">
                                <div class="sb-nav-link-icon"><i class="fas fa-chart-line"></i></div> Report
                                </a>
                                <a class="nav-link <?php echo ($page === 'case_list') ? '' : 'collapsed'; ?>" href="index_captain.php?page=<?php echo urlencode(encrypt('case_list')); ?>">
                                <div class="sb-nav-link-icon"><i class="fas fa-gavel"></i></div> Case List
                                </a>
                                <a class="nav-link <?php echo ($page === 'view_appointments') ? '' : 'collapsed'; ?>" href="index_captain.php?page=<?php echo urlencode(encrypt('view_appointments')); ?>">
                                <div class="sb-nav-link-icon"><i class="fas fa-calendar-check"></i></div> View Appointments
                                </a>
                        </div>
                    </div>
                    <div class="sb-sidenav-footer">
                        <div class="small">Logged in as:</div>
                        <h5 class="mt-4">Welcome, <?php echo htmlspecialchars($userName); ?> (<?php echo htmlspecialchars($roleName); ?>)</h5>

                    </div>
                </nav>
            </div>
            <div id="layoutSidenav_content">
                <main>
                    <div class="container-fluid px-4">
                    <!-- <a class="nav-link"> -->
                        <!-- <h1 class="mt-4"><?php echo htmlspecialchars($roleName); ?></h1> -->
                        <!-- <ol class="breadcrumb mb-4">
                            <li class="breadcrumb-item active">DASHBOARD</li>
                        </ol> -->
<?php require_once 'Modals/announcement.php'; ?>
                    <main id="main" class="main">

<!-- section for main content -->
<section class="section"> 

          <?php
require_once __DIR__ . '/include/connection.php';
$mysqli = db_connection();
// include './include/encryption.php'; // Make sure this exists and has decrypt()

$decryptedPage = 'admin_dashboard'; // fallback/default

if (isset($_GET['page'])) {
    $decrypted = decrypt($_GET['page']);
//    echo "<pre>Decrypted page: $decrypted</pre>";
    if ($decrypted !== false) {
        $decryptedPage = $decrypted;
    }
}



switch ($decryptedPage) {
            case 'admin_dashboard':
                include 'Modules/captain_modules/admin_dashboard.php';
              break;
            case 'feedbacks':
                include 'Modules/captain_modules/Feedbacks.php';
              break;
            case 'reports':
                include 'Modules/captain_modules/reports.php';
              break;
              case 'resident_info':
                include 'Modules/captain_modules/resident_info.php';
              break;
              case 'case_list':
                include 'Modules/captain_modules/case_list.php';
              break;
              case 'view_appointments':
                include 'Modules/captain_modules/view_appointments.php';
              break;
              case 'families':
        include 'Pages/linked_families.php'; // ✅ adjust path if needed
        break;
        case 'add_announcement':
    include 'components/announcement/add_announcement.php';
    break;
    case 'profile':
    include 'Pages/profile.php';
    break;
case 'settings':
    include 'Pages/settings.php';
    break;
    case 'verify_2fa_password':
    include 'auth/verify_2fa_password.php';
    break;

    default:
       echo "<div class='alert alert-danger'>Invalid or missing page: <code>" . htmlspecialchars($decryptedPage) . "</code></div>";
        break;
}
?>



</section>

</main>

                </main>
                <footer class="py-4 bg-light mt-auto">
                    <div class="container-fluid px-4">
                        <div class="d-flex align-items-center justify-content-between small">
                        <div class="text-muted">Copyright &copy; <?php echo $barangayName . ' ' . date('Y'); ?></div>
                            <div>
                                <a href="#">Privacy Policy</a>
                                &middot;
                                <a href="#">Terms &amp; Conditions</a>
                            </div>
                        </div>
                    </div>
                </footer>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
        <script src="js/scripts.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/2.8.0/Chart.min.js" crossorigin="anonymous"></script>
        <!-- <script src="assets/demo/chart-area-demo.js"></script> -->
        <!-- <script src="assets/demo/chart-bar-demo.js"></script> -->
        <script src="https://cdn.jsdelivr.net/npm/simple-datatables@7.1.2/dist/umd/simple-datatables.min.js" crossorigin="anonymous"></script>
        <script>
function confirmLogout() {
    Swal.fire({
        title: 'Are you sure?',
        text: "You will be logged out.",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#3085d6',
        cancelButtonColor: '#d33',
        confirmButtonText: 'Yes, logout'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Logging out...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            // Slight delay so loading is visible
            setTimeout(() => {
                window.location.href = "logout.php";
            }, 1000);
        }
    });
    return false;
}
        </script>
    </body>
</html>