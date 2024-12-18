<?php
session_start();
include_once 'connections/connection.php';
include_once 'components/nav.php';
include 'components/admin-modal.php';

$conn = connection();

// Fetch parking slots
$parkingSql = "SELECT ps.*, u.fname, u.lname, u.email, u.contact_no, v.plate_number, vt.type_name AS vehicle_type 
              FROM parkingslots_tbl ps 
              LEFT JOIN user_tbl u ON ps.user_id = u.user_id 
              LEFT JOIN vehicle_tbl v ON ps.vehicle_id = v.vehicle_id 
              LEFT JOIN vehicletype_tbl vt ON v.vehicle_type_id = vt.type_id";
$stmtParking = $conn->prepare($parkingSql);
$stmtParking->execute();
$parkingResult = $stmtParking->get_result();
$parkingSlots = [];

while ($row = $parkingResult->fetch_assoc()) {
  $parkingSlots[] = $row;
}

// Fetch users with their vehicles
$userQuery = "SELECT u.user_id AS id, u.fname, u.lname, u.email, u.contact_no, v.vehicle_id, v.plate_number 
              FROM user_tbl u 
              LEFT JOIN vehicle_tbl v ON u.user_id = v.user_id 
              WHERE u.access_level != 'admin' 
              ORDER BY u.fname ASC, u.lname ASC";
$userResult = $conn->query($userQuery);
$users = [];
while ($row = $userResult->fetch_assoc()) {
  $users[] = $row;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $slotNumber = $_POST['slotNumber'];
  $status = $_POST['status'];
  $userId = $_POST['user_id'] ?: null;

  if ($status === 'occupied' && $userId) {
    // Get vehicle_id for the user
    $vehicleQuery = "SELECT vehicle_id FROM vehicle_tbl WHERE user_id = ?";
    $stmtVehicle = $conn->prepare($vehicleQuery);
    $stmtVehicle->bind_param("i", $userId);
    $stmtVehicle->execute();
    $vehicleResult = $stmtVehicle->get_result();
    $vehicle = $vehicleResult->fetch_assoc();

    // Get parking slot ID
    $slotQuery = "SELECT pslot_id FROM parkingslots_tbl WHERE slot_number = ?";
    $stmtSlot = $conn->prepare($slotQuery);
    $stmtSlot->bind_param("s", $slotNumber);
    $stmtSlot->execute();
    $slotResult = $stmtSlot->get_result();
    $slot = $slotResult->fetch_assoc();

    // Begin transaction
    $conn->begin_transaction();

    try {
      // Update parking slot
      $updateSlot = "UPDATE parkingslots_tbl SET status = ?, user_id = ?, vehicle_id = ? WHERE slot_number = ?";
      $stmtUpdate = $conn->prepare($updateSlot);
      $stmtUpdate->bind_param("siis", $status, $userId, $vehicle['vehicle_id'], $slotNumber);
      $stmtUpdate->execute();

      // Create ticket
      $ticketNo = 'ITC-' . date('Ymd') . sprintf('%04d', rand(1, 9999));
      $insertTicket = "INSERT INTO ticket_tbl (ticket_no, entry_time, is_overtime, user_id, pslot_id, vehicle_id) 
                          VALUES (?, NOW(), 0, ?, ?, ?)";
      $stmtTicket = $conn->prepare($insertTicket);
      $stmtTicket->bind_param("siii", $ticketNo, $userId, $slot['pslot_id'], $vehicle['vehicle_id']);
      $stmtTicket->execute();

      $conn->commit();
      echo json_encode(['success' => true]);
    } catch (Exception $e) {
      $conn->rollback();
      echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
  } elseif ($status === 'available') {
    // Begin transaction
    $conn->begin_transaction();

    try {
      // Get current ticket for this slot
      $ticketQuery = "SELECT t.ticket_id 
                         FROM ticket_tbl t 
                         JOIN parkingslots_tbl ps ON t.pslot_id = ps.pslot_id 
                         WHERE ps.slot_number = ? AND t.exit_time IS NULL";
      $stmtTicket = $conn->prepare($ticketQuery);
      $stmtTicket->bind_param("s", $slotNumber);
      $stmtTicket->execute();
      $ticketResult = $stmtTicket->get_result();
      $ticket = $ticketResult->fetch_assoc();

      // Update ticket with exit time
      if ($ticket) {
        $updateTicket = "UPDATE ticket_tbl SET exit_time = NOW() WHERE ticket_id = ?";
        $stmtUpdateTicket = $conn->prepare($updateTicket);
        $stmtUpdateTicket->bind_param("i", $ticket['ticket_id']);
        $stmtUpdateTicket->execute();
      }

      // Update parking slot
      $updateSlot = "UPDATE parkingslots_tbl SET status = ?, user_id = NULL, vehicle_id = NULL WHERE slot_number = ?";
      $stmtUpdate = $conn->prepare($updateSlot);
      $stmtUpdate->bind_param("ss", $status, $slotNumber);
      $stmtUpdate->execute();

      $conn->commit();
      echo json_encode(['success' => true]);
    } catch (Exception $e) {
      $conn->rollback();
      echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
  }
  exit();
}

function renderParkingSlots($parkingSlots, $prefix)
{
  foreach ($parkingSlots as $slot) {
    if (strpos($slot['slot_number'], $prefix) === 0) {
      $borderClass = $slot['status'] === 'occupied' ? 'occupied' : 'available';
      $modalTarget = $slot['status'] === 'occupied' ? '#infoModal' : '#slotModal';
      echo '<button class="slot ' . $borderClass . '" data-toggle="modal" data-target="' . $modalTarget . '" 
        data-slot-number="' . $slot['slot_number'] . '" 
        data-status="' . $slot['status'] . '" 
        data-vehicle-id="' . $slot['vehicle_id'] . '" 
        data-user-id="' . $slot['user_id'] . '"
        data-fname="' . $slot['fname'] . '"
        data-lname="' . $slot['lname'] . '"
        data-email="' . $slot['email'] . '"
        data-contact-no="' . $slot['contact_no'] . '"></button>';
    }
  }
}

// Filter users who are not present in parkingslots_tbl
$filteredUsers = array_filter($users, function ($user) use ($parkingSlots) {
  foreach ($parkingSlots as $slot) {
    if ($slot['user_id'] == $user['id']) {
      return false;
    }
  }
  return true;
});

// Sort the filtered users by first name and last name
usort($filteredUsers, function ($a, $b) {
  return strcmp($a['fname'] . $a['lname'], $b['fname'] . $b['lname']);
});

adminModal($filteredUsers);

?>
<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/styles/map.css">
  <link rel="stylesheet" href="assets/styles/global.css">
  <title>Map</title>
</head>

<body>
  <?php nav(); ?>
  <div class="p-4">
    <h1>Map</h1>
    <p>Welcome, <?php echo htmlspecialchars($_SESSION['user']['fname']); ?>! <a href="logout.php">Log out</a></p>
    <div class="map-wrapper">
      <div class="motor-parking">
        <?php renderParkingSlots($parkingSlots, 'MP'); ?>
      </div>
      <div class="right-map">
        <div class="top-parking">
          <div class="left-parking">
            <?php renderParkingSlots($parkingSlots, 'LP'); ?>
          </div>
          <div class="entrance">you are here 📍</div>
          <div class="right-parking">
            <?php renderParkingSlots($parkingSlots, 'RP'); ?>
          </div>
        </div>
        <div class="bottom-parking">
          <div class="trike-parking">
            <?php renderParkingSlots($parkingSlots, 'TP'); ?>
          </div>
          <div class="center-parking">
            <?php renderParkingSlots($parkingSlots, 'CP'); ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://code.jquery.com/jquery-3.5.1.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js"></script>
  <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
  <script src="assets/scripts/admin-modal.js"></script>
</body>

</html>