<?
$name = "";
$plate_number = "";

// Check if the form is submitted
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  // Capture input values
  $name = htmlspecialchars($_POST['name']);
  $plate_number = htmlspecialchars($_POST['pnumber']);

  // Alert the inputted values using JavaScript
  echo "<script>alert('Name: $name\\nPlate Number: $plate_number');</script>";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="./assets/styles/global.css" />
  <title>CPMS — Admin Login</title>
</head>

<body>
  <div class="wrapper">
    <form action="" method="post">
      <div class="form-header">
        <img src="./assets/images/logo.jpg" alt="cpms-logo" width="60" height="60" />
        <div>
          <h3>CPMS — Login</h3>
          <p>Enter your name and license plate number</p>
        </div>
      </div>
      <div class="form-group">
        <label for="name">Name</label>
        <input type="text" id="name" name="name" required />
      </div>
      <div class="form-group">
        <label for="pnumber">Plate Number</label>
        <input
          type="text"
          id="pnumber"
          name="pnumber"
          placeholder="ABC-123 or ABC-1234"
          pattern="([A-Z]{3}-\d{3,4})|(\d{4}-[A-Z]{3})"
          title="Format: ABC-123 or ABC-1234" />
      </div>
      <button type="submit">Login</button>
    </form>
  </div>
</body>

</html>