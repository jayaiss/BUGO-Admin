<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection();

// ✅ PH time SQL constant (no other code changed)
define('SQL_NOW_PH', "CONVERT_TZ(UTC_TIMESTAMP(), '+00:00', '+08:00')");

class Trigger {

    public $login_id;

    public function __construct() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (isset($_SESSION['employee_id'])) {
            $this->login_id = $_SESSION['employee_id'];
        } else {
            $this->login_id = null;
        }
    }
    
    public function isUpdated($filename, $updated_id) {
        global $mysqli;

        $auditTable = 'audit_info';
        $actionMade = 8;
        $role = $this->getRoleById($this->login_id);

        $sql = "INSERT INTO $auditTable (logs_name, id, roles, action_made, action_by, date_created)
                VALUES (?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("iiiii", $filename, $updated_id, $role, $actionMade, $this->login_id);
        $stmt->execute();
        $stmt->close();
    }

    public function isStatusUpdate($filename, $resident_id, $status, $trackingNumber) {
        global $mysqli;

        $auditTable = 'audit_info';
        $actionMade = 8; // Status Update Action
        $role = $this->getRoleById($this->login_id);

        $sql = "INSERT INTO $auditTable (logs_name, id, roles, action_made, action_by, date_created)
                VALUES (?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";

        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param('iiiis', $filename, $resident_id, $role, $actionMade, $this->login_id);
        if ($stmt->execute() === false) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }

        $stmt->close();
    }

    public function isBESOUpdate($filename, $resident_id, $status, $trackingNumber) {
        global $mysqli;

        $actionMade = 8; // Status update
        $role = $this->getRoleById($this->login_id);

        if (!is_numeric($filename) || !is_numeric($resident_id) || !is_numeric($role) || !is_numeric($this->login_id)) {
            throw new Exception("Invalid types for audit log");
        }

        $sql = "INSERT INTO audit_info (logs_name, id, roles, action_made, action_by, date_created)
                VALUES (?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);

        $stmt->bind_param("iiiii", $filename, $resident_id, $role, $actionMade, $this->login_id);
        if (!$stmt->execute()) {
            throw new Exception('Execute failed: ' . $stmt->error);
        }

        $stmt->close();
    }

    public function isDelete($filename, $deleted_id) {
        global $mysqli;

        $auditTable = 'audit_info';
        $actionMade = 1;
        $role = ($filename === 1) ? $this->getRoleById($deleted_id) : NULL;
        $sql = "INSERT INTO $auditTable (logs_name, id, roles, action_made, action_by, date_created)
                VALUES (?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";

        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            die('Prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param('iiiii', $filename, $deleted_id, $role, $actionMade, $this->login_id);
        if ($stmt->execute() === false) {
            die('Execute failed: ' . $stmt->error);
        }

        $stmt->close();
    }

    public function isAdded($filename, $added_id) {
        global $mysqli;

        $auditTable = 'audit_info';
        $actionMade = 3;
        $role = ($filename  === 1) ? $this->getRoleById($added_id) : NULL;
        $sql = "INSERT INTO $auditTable (logs_name, id, roles, action_made, action_by, date_created)
                VALUES (?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";

        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            die('Prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param('iiiii', $filename, $added_id, $role, $actionMade, $this->login_id);
        if ($stmt->execute() === false) {
            die('Execute failed: ' . $stmt->error);
        }

        $stmt->close();
    }

    public function isUrgent($filename, $added_id) {
        global $mysqli;

        $auditTable = 'audit_info';
        $actionMade = 10;
        $role = ($filename  === 1) ? $this->getRoleById($added_id) : NULL;
        $sql = "INSERT INTO $auditTable (logs_name, id, roles, action_made, action_by, date_created)
                VALUES (?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";

        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            die('Prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param('iiiii', $filename, $added_id, $role, $actionMade, $this->login_id);
        if ($stmt->execute() === false) {
            die('Execute failed: ' . $stmt->error);
        }

        $stmt->close();
    }

    public function isBatchAdded($filename, $totalRecords) {
        global $mysqli;

        $auditTable = 'audit_info';
        $actionMade = 9; // still "ADDED"
        $role = $this->getRoleById($this->login_id);
        $summaryNote = "Batch uploaded {$totalRecords} case(s)";

        $sql = "INSERT INTO $auditTable 
                (logs_name, roles, action_made, action_by, old_version, date_created)
                VALUES (?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";

        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            die('Prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param('iiiss', $filename, $role, $actionMade, $this->login_id, $summaryNote);
        if (!$stmt->execute()) {
            die('Execute failed: ' . $stmt->error);
        }

        $stmt->close();
    }

    public function isResidentBatchAdded($filename, $count) {
        global $mysqli;

        $auditTable = 'audit_info';
        $actionMade = 9; // ADD
        $role = $this->getRoleById($this->login_id);

        $summary = json_encode([
            "summary" => "Batch uploaded {$count} resident(s)"
        ]);

        $dummyOld = '{}';

        $sql = "INSERT INTO $auditTable 
            (logs_name, roles, action_made, action_by, old_version, new_version, date_created)
            VALUES (?, ?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);

        $stmt->bind_param('iiisss', $filename, $role, $actionMade, $this->login_id, $dummyOld, $summary);
        $stmt->execute();
        $stmt->close();
    }

    public function isCaseBatchAdded($filename, $count) {
        global $mysqli;

        $auditTable = 'audit_info';
        $actionMade = 9; // ADD
        $role = $this->getRoleById($this->login_id);

        $summary = "Batch uploaded {$count} records";

        // Provide both old_version and new_version (even if dummy)
        $oldVersion = '{}';
        $newVersion = json_encode(['summary' => $summary]);

        $sql = "INSERT INTO $auditTable 
                (logs_name, roles, action_made, action_by, old_version, new_version, date_created)
                VALUES (?, ?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param('iiisss', $filename, $role, $actionMade, $this->login_id, $oldVersion, $newVersion);
        $stmt->execute();
        $stmt->close();
    }

    public function isPrinted($filename, $viewedID = 0) {
        global $mysqli;

        $auditTable = 'audit_info';
        $actionMade = 11; // 🔍 Custom action code for print
        $role = $this->getRoleById($this->login_id);

        $sql = "INSERT INTO $auditTable (logs_name, id, roles, action_made, action_by, date_created)
                VALUES (?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            die('Prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param('iiiii', $filename, $viewedID, $role, $actionMade, $this->login_id);
        if (!$stmt->execute()) {
            die('Execute failed: ' . $stmt->error);
        }

        $stmt->close();
    }

    public function isViewed($filename, $viewedID) {
        global $mysqli;
    
        $auditTable = 'audit_info';
        $actionMade = 4;
        $role = $this->getRoleById($this->login_id);
    
        $sql = "INSERT INTO $auditTable (logs_name, id, roles, action_made, action_by, date_created)
                VALUES (?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";
    
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            die('Prepare failed: ' . $mysqli->error);
        }
    
        $stmt->bind_param('iiiii', $filename, $viewedID, $role, $actionMade, $this->login_id);
        if ($stmt->execute() === false) {
            die('Execute failed: ' . $stmt->error);
        }
    
        $stmt->close();
    }
    
    public function isRestored($filename, $restoredID, $field) {
        global $mysqli;

        $auditTable = 'audit_info';
        $actionMade = 5;
        $sql = "INSERT INTO $auditTable (logs_name, id, roles, action_made, restore_value, action_by, date_created)
                VALUES (?, ?, NULL, ?, ?, ?, " . SQL_NOW_PH . ")";

        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            die('Prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param('iiiii', $filename, $restoredID, $actionMade, $field, $this->login_id);
        if ($stmt->execute() === false) {
            die('Execute failed: ' . $stmt->error);
        }

        $stmt->close();
    }

    public function isLogin($actionMade, $loginID) {
        global $mysqli;

        $auditTable = 'audit_info';
        $filename = 7;
        $sql = "INSERT INTO $auditTable (action_made, action_by, date_created)
                VALUES (?, ?, " . SQL_NOW_PH . ")";

        $role = $this->getRoleById($loginID);
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            die('Prepare failed: ' . $mysqli->error);
        }
        $user = $this->getUserOrAdminById($loginID);
        $stmt->bind_param('ii', $actionMade, $user);
        if ($stmt->execute() === false) {
            die('Execute failed: ' . $stmt->error);
        }

        $stmt->close();
    }

    public function isLogout($actionMade, $logoutID) {
        global $mysqli;
        $auditTable = 'audit_info';
        $filename = 8;
        $sql = "INSERT INTO $auditTable (action_made, action_by, date_created)
                VALUES (?, ?, " . SQL_NOW_PH . ")";
        $role = $this->getRoleById($logoutID);
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            die('Prepare failed: '. $mysqli->error);
        }
        $user = $this->getUserOrAdminById($logoutID);
        $stmt->bind_param('ii', $actionMade, $user);
        if ($stmt->execute() === false) {
            die('Execute failed: '. $stmt->error);
        }
        $stmt->close();
    }

    public function isStatusChange($logs_name, $logs_id) {
        global $mysqli;

        if (!is_numeric($logs_name) || !is_numeric($logs_id)) {
            throw new Exception("Invalid filename provided");
        }

        $role = $this->getRoleById($this->login_id);
        $action_made = 8; // EDIT
        $summary = NULL;

        $sql = "INSERT INTO audit_info (logs_name, roles, action_made, action_by, old_version, date_created)
                VALUES (?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";

        $stmt = $mysqli->prepare($sql);
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $mysqli->error);
        }

        $stmt->bind_param('iiiss', $logs_name, $role, $action_made, $this->login_id, $summary);
        $stmt->execute();
        $stmt->close();
    }

    public function isEdit($filename, $edited_id, $oldData) {
        global $mysqli;

        try {
            $newData = $this->getOldAndNewData($edited_id, $filename);

            // 🧹 Exclude binary/blob fields that can break JSON encoding
            $this->sanitizeBinaryFields($oldData);
            $this->sanitizeBinaryFields($newData);

            $auditTable = 'audit_info';
            $actionMade = 2;
            $role = $this->getRoleById($this->login_id);

            $oldDataJson = json_encode($oldData);
            $newDataJson = json_encode($newData);

            if ($oldDataJson === false || $newDataJson === false) {
                throw new Exception('JSON encoding failed: ' . json_last_error_msg());
            }

            $sql = "INSERT INTO $auditTable 
                    (logs_name, id, roles, action_made, action_by, old_version, new_version, date_created)
                    VALUES (?, ?, ?, ?, ?, ?, ?, " . SQL_NOW_PH . ")";

            $stmt = $mysqli->prepare($sql);
            if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);

            $stmt->bind_param('iiiiiss', $filename, $edited_id, $role, $actionMade, $this->login_id, $oldDataJson, $newDataJson);

            if (!$stmt->execute()) {
                throw new Exception('Execute failed: ' . $stmt->error);
            }

            $stmt->close();
        } catch (Exception $e) {
            error_log($e->getMessage());
            die('Error: ' . $e->getMessage());
        }
    }

    public function getRoleById($id) {
        global $mysqli;
        $stmt = $mysqli->prepare("SELECT Role_Name FROM employee_roles WHERE ROle_Id     = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();

        $role = null; // ✅ Declare before binding
        $stmt->bind_result($role);

        if ($stmt->fetch()) {
            $stmt->close();
            return $role;
        } else {
            $stmt->close();
            return null;
        }
    }

    public function getUserOrAdminById($id) {
        global $mysqli;
        $sql = "SELECT employee_id FROM employee_list WHERE employee_id = ?";
        $stmt = $mysqli->prepare($sql);
        if ($stmt === false) {
            die('Prepare failed: ' . $mysqli->error);
        }

        $stmt->bind_param("i", $id);
        $stmt->execute();

        $employee_id = null; // ✅ Declare before binding
        $stmt->bind_result($employee_id);
        $stmt->fetch();

        $stmt->close();
        $this->login_id = $employee_id;

        return $employee_id;
    }

    public function getOldAndNewData($edited_id, $filename) {
        global $mysqli;

        try {
            switch ($filename) {
                case 1: $sql = "SELECT * FROM employee_list WHERE employee_id = ?"; break;
                case 2: $sql = "SELECT * FROM residents WHERE id = ?"; break;
                case 3: $sql = "SELECT * FROM schedules WHERE id = ?"; break;
                case 4: $sql = "SELECT * FROM cedula WHERE Ced_Id = ?"; break;
                case 5: $sql = "SELECT * FROM cases WHERE case_number = ?"; break;
                case 6: $sql = "SELECT * FROM archive_table WHERE id = ?"; break;
                case 13: $sql = "SELECT * FROM barangay_info WHERE id = ?"; break;
                case 18: $sql = "SELECT * FROM zone WHERE Id = ?"; break;
                case 17: $sql = "SELECT * FROM zone_leaders WHERE Leaders_Id = ?"; break;
                case 19: $sql = "SELECT * FROM guidelines WHERE Id = ?"; break;
                case 20: $sql = "SELECT * FROM urgent_request WHERE urg_id = ?"; break;
                case 28: $sql = "SELECT * FROM beso WHERE Id = ?"; break;
                case 29: $sql = "SELECT * FROM announcement WHERE Id = ?"; break;
                default: throw new Exception('Invalid filename provided: ' . var_export($filename, true));
            }

            $stmt = $mysqli->prepare($sql);
            if (!$stmt) throw new Exception('Prepare failed: ' . $mysqli->error);

            $stmt->bind_param('i', $edited_id);
            $stmt->execute();

            $result = $stmt->get_result();
            $oldData = $result->fetch_assoc();
            $stmt->close();

            // 🧹 Sanitize binary fields before returning
            $this->sanitizeBinaryFields($oldData);

            return $oldData;
        } catch (Exception $e) {
            error_log($e->getMessage());
            die('Error: ' . $e->getMessage());
        }
    }

    private function sanitizeBinaryFields(&$data) {
        if (!is_array($data)) return;

        foreach ($data as $key => $value) {
            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                unset($data[$key]); // Remove malformed/binary fields
            }
        }

        // Optionally remove known binary fields explicitly
        $binaryKeys = ['profilePicture', 'document_file', 'image_blob'];
        foreach ($binaryKeys as $key) {
            if (isset($data[$key])) {
                unset($data[$key]);
            }
        }
    }

}

/* ---------- NEW: handle print logging (action=print) ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $trigger = new Trigger();

    // 🔹 Print log endpoint for AJAX: logs the print action (action_made = 11)
    if (isset($_POST['action']) && $_POST['action'] === 'print' && isset($_POST['filename']) && isset($_POST['viewedID'])) {
        header('Content-Type: application/json');
        $id = (int)$_POST['viewedID'];     // resident id
        $filePath = (int)$_POST['filename']; // e.g., 3 = APPOINTMENTS
        try {
            $trigger->isPrinted($filePath, $id);
            echo json_encode(['success' => true]);
        } catch (Exception $e) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    // Existing: generic "viewed" logger
    if (isset($_POST['filename']) && isset($_POST['viewedID'])) {
        header('Content-Type: application/json');
        $id = (int)$_POST['viewedID'];    // Ensure viewedID is an integer
        $filePath = (int)$_POST['filename'];

        // Trigger object already created above
        $trigger->isViewed($filePath, $id);
        echo json_encode(['success' => true]);
        exit;
    }
}
?>
<?php
require_once __DIR__ . '/../include/connection.php';
$mysqli = db_connection(); // Include your database connection file

if (isset($_POST['logID']) && isset($_POST['id']) && isset($_POST['logPath'])) {
    $logID = $_POST['logID'];
    $id = $_POST['id'];
    $logPath = $_POST['logPath'];

    // Validate that ID is numeric and logID is within the expected range
    if (!is_numeric($logID) || !is_numeric($id) || !in_array($logPath, [1, 2, 3, 4, 5])) {
        echo json_encode(['error' => 'Invalid input']);
        exit;
    }

    // Prepare the query to fetch the log details
    $query = "SELECT old_version, new_version FROM audit_info WHERE logs_id = ? AND id = ? AND logs_name = ?";

    // Use prepared statements to prevent SQL injection
    if ($stmt = $mysqli->prepare($query)) {
        $stmt->bind_param("iii",$logID, $id, $logPath); // Bind $id and $logID as integers
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();

            // Assume the old_version and new_version are JSON strings stored in the database
            $oldJSON = $row['old_version'];
            $newJSON = $row['new_version'];

            // Decode the JSON strings to validate if they're valid JSON data
            $oldData = json_decode($oldJSON, true);
            $newData = json_decode($newJSON, true);

            // Check if the JSON decoding was successful
            if (json_last_error() === JSON_ERROR_NONE) {
                // Return the old and new data as JSON
                echo json_encode([
                    'old' => $oldData,
                    'new' => $newData
                ]);
            } else {
                echo json_encode(['error' => 'Invalid JSON data']);
            }
        } else {
            echo json_encode(['error' => 'No data found']);
        }
        $stmt->close();
    } else {
        echo json_encode(['error' => 'Query preparation failed']);
    }
}
?>
