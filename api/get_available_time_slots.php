<?php
// FILE: bugo/api/get_available_time_slots.php

// Set headers for JSON response and CORS
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *'); // Allows requests from any origin (good for development)
header('Access-Control-Allow-Methods: GET, POST, OPTIONS'); // This script will primarily use GET
header('Access-Control-Allow-Headers: Content-Type, Authorization'); // Allows specific headers

// --- IMPORTANT: Include your database connection file ---
include '../include/connection.php';
// You might also need to start the session if your connection.php relies on it or if you intend to use $_SESSION['employee_id']
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get the date from the Flutter app's GET request (e.g., ?date=YYYY-MM-DD)
$selectedDate = $_GET['date'] ?? '';

// Basic input validation for the date
if (empty($selectedDate)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Date parameter is missing. Please provide a date in YYYY-MM-DD format.']);
    exit;
}

$response = []; // Initialize an empty array to store the time slot data

try {
    // 1. Fetch all active time slots from the 'time_slot' table
    // Ordering by time_slot_start ensures slots are returned in chronological order
    $timeSlotsQuery = $mysqli->prepare("SELECT Id, time_slot_name, time_slot_start, time_slot_end, time_slot_number FROM time_slot WHERE status = 'Active' ORDER BY time_slot_start ASC");
    
    // You might want to filter by employee_id here if time slots are specific to an employee
    // Example (uncomment and modify if needed, ensure employee_id is available):
    // $employeeId = $_SESSION['employee_id'] ?? null; // Get employee_id from session or other source
    // if ($employeeId) {
    //     $timeSlotsQuery = $mysqli->prepare("SELECT Id, time_slot_name, time_slot_start, time_slot_end, time_slot_number FROM time_slot WHERE employee_id = ? AND status = 'Active' ORDER BY time_slot_start ASC");
    //     $timeSlotsQuery->bind_param("i", $employeeId);
    // }
    
    $timeSlotsQuery->execute();
    $timeSlotsResult = $timeSlotsQuery->get_result();

    $allTimeSlots = [];
    while ($slot = $timeSlotsResult->fetch_assoc()) {
        $allTimeSlots[] = $slot;
    }
    $timeSlotsQuery->close();

    // 2. For each time slot, count existing *APPROVED*, *RELEASED*, OR *PENDING* appointments
    foreach ($allTimeSlots as $slot) {
        $slotId = $slot['Id']; // Unique ID from the time_slot table
        $slotName = $slot['time_slot_name']; // e.g., "09:00AM-10:00AM"
        $totalCapacity = $slot['time_slot_number'];

        // --- THE CHANGE IS HERE: ADDED 'status = "Pending"' TO THE OR CLAUSE ---
        // We now count appointments with 'status = "Approved"', 'status = "Released"', OR 'status = "Pending"'
        // and also ensure appointment_delete_status is 0 (not deleted)
        $bookedCountQuery = $mysqli->prepare(
            "SELECT COUNT(*) FROM schedules WHERE selected_date = ? AND selected_time = ? AND (status = 'Approved' OR status = 'Released' OR status = 'Pending') AND appointment_delete_status = 0"
        );
        $bookedCountQuery->bind_param("ss", $selectedDate, $slotName);
        $bookedCountQuery->execute();
        $bookedCountQuery->bind_result($bookedCount);
        $bookedCountQuery->fetch();
        $bookedCountQuery->close();

        // Calculate available slots
        $availableSlots = $totalCapacity - $bookedCount;
        if ($availableSlots < 0) { // Ensure available slots don't go negative
            $availableSlots = 0;
        }

        // Add this time slot's detailed availability to the response array
        $response[] = [
            'id' => $slotId, // The time_slot ID
            'time_slot_name' => $slotName, // Ensure this column in DB is VARCHAR/TEXT
            'time_slot_start' => date("h:i A", strtotime($slot['time_slot_start'])), // Format time for Flutter display
            'time_slot_end' => date("h:i A", strtotime($slot['time_slot_end'])),      // Format time for Flutter display
            
            // --- IMPORTANT CHANGE HERE: RENAMED 'total_capacity' to 'maxSlots' to match Flutter model ---
            'maxSlots' => $totalCapacity, 
            
            'booked_count' => $bookedCount,
            'available_slots' => $availableSlots,
            'is_fully_booked' => ($availableSlots <= 0) // Boolean flag for easy checking in Flutter
        ];
    }

} catch (Exception $e) {
    // Catch any database or other errors during execution
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
    exit;
} finally {
    // Ensure the database connection is closed
    if ($mysqli) {
        $mysqli->close();
    }
}

// Send the JSON response back to Flutter
echo json_encode($response);

?>