<?php
session_start();

require 'vendor/autoload.php'; // Autoload Composer packages

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\ErrorCorrectionLevel;
use Mpdf\Mpdf; // Import mPDF
use PHPMailer\PHPMailer\PHPMailer; // Import PHPMailer classes

// Check if booking details are set in session
if (!isset($_SESSION['booking_ids']) || !isset($_SESSION['payment_id'])) {
    header("Location: userhome.php");
    exit();
}

$booking_ids = $_SESSION['booking_ids'];
$payment_id = $_SESSION['payment_id'];

// Fetch booking details from the database
include 'db_connection.php';

$bookings = [];
$total_amount = 0;
$boat_name = '';
$arrival_time = '';
$user_id = 0;
$schedule_details = '';
$user_email = '';
$user_name = '';

// Fetch payment details
$stmt = $conn->prepare("SELECT amount, payment_method, payment_status FROM Payments WHERE payment_id = ?");
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$payment_result = $stmt->get_result();
$payment_data = $payment_result->fetch_assoc();
$stmt->close();

$total_amount = $payment_data['amount'];
$payment_method = $payment_data['payment_method'];
$payment_status = $payment_data['payment_status'];

// Fetch booking details
foreach ($booking_ids as $booking_id) {
    $stmt = $conn->prepare("
    SELECT B.booking_id, U.name, U.email, B.user_id, BO.boat_name, S.seat_number, 
           B.schedule_id, SCH.arrival_time, SCH.departure_time, RS.location AS start_stop, RE.location AS end_stop
    FROM Seat_Bookings B
    JOIN Users U ON B.user_id = U.user_id
    JOIN Boats BO ON B.boat_id = BO.boat_id
    JOIN Schedules SCH ON B.schedule_id = SCH.schedule_id
    JOIN Seats S ON B.seat_id = S.seat_id
    JOIN Route_Stops RS ON B.start_stop_id = RS.stop_id
    JOIN Route_Stops RE ON B.end_stop_id = RE.stop_id
    WHERE B.booking_id = ?");

    $stmt->bind_param("i", $booking_id);
    $stmt->execute();
    $booking_result = $stmt->get_result();
    $booking_data = $booking_result->fetch_assoc();
    $stmt->close();

    if ($booking_data) {
        $bookings[] = $booking_data;
        $user_id = $booking_data['user_id'];
        $user_email = $booking_data['email'];
        $user_name = $booking_data['name'];
        $boat_name = $booking_data['boat_name'];
        $arrival_time = $booking_data['arrival_time'];
        $departure_time = $booking_data['departure_time'];

        if (empty($schedule_details)) {
            $schedule_details = $booking_data['start_stop'] . " to " . $booking_data['end_stop'] . " (" . $booking_data['departure_time'] . " to " . $booking_data['arrival_time'] . ")";
        }
    }
}

$qrData = json_encode([
    'user_id' => $user_id,
    'booking_ids' => $booking_ids,
    'payment_id' => $payment_id
]);

$qrResult = Builder::create()
    ->writer(new PngWriter())
    ->data($qrData)
    ->encoding(new \Endroid\QrCode\Encoding\Encoding('UTF-8'))
    ->errorCorrectionLevel(ErrorCorrectionLevel::High)
    ->size(100) // Set QR code size to be smaller
    ->margin(5) // Set smaller margin for the QR code
    ->build();

$qrImageData = $qrResult->getString();

function numberToAlphabetic($num) {
    $result = '';
    while ($num > 0) {
        $mod = ($num - 1) % 26;
        $result = chr(65 + $mod) . $result;
        $num = intval(($num - $mod) / 26);
    }
    return $result;
}

$system_date = date('Y-m-d');
$conn->close();

function generatePDF($user_email, $user_name, $boat_name, $schedule_details, $arrival_time, $total_amount, $system_date, $qrImageData, $booking_ids) {
    $mpdf = new Mpdf([
        'mode' => 'utf-8',
        'format' => 'A5', // Set a smaller page format (A5 size for ticket style)
        'default_font_size' => 12,
        'default_font' => 'Arial',
        'margin_left' => 10,
        'margin_right' => 20,
        'margin_top' => 10,
        'margin_bottom' => 10,
        'margin_header' => 0,
        'margin_footer' => 0
    ]);

    // Include CSS styles
    $css = '
    body {
        font-family: Arial, sans-serif;
        color: #000;
        background-color: #f5f5f5;
        margin: 0; 
        padding: 0; 
        display: flex; 
        justify-content: center; 
        align-items: center; 
        height: 100vh; 
    }
    .ticket-container {
        width: 400px; // Set the width of the ticket
        background-color: #ffffff; 
        border-radius: 10px; 
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2); 
        padding: 10px; 
        border: 2px solid #6c757d; 
    }
    .ticket-header {
        font-weight: bold;
        text-align: center;
        margin-bottom: 5px;
    }
    .ticket-header h2 {
        color: #ff5722; 
        font-weight: bold;
        font-size: 1.3em; 
        margin-bottom: 3px; 
    }
    .ticket-header p {
        font-size: 0.9em; 
        color: #6c757d; 
    }
    .ticket-details {
        margin-bottom: 10px; 
    }
    .ticket-details h4 {
        color: #ff5722; 
        margin-bottom: 3px;
        font-weight: bold; 
        border-bottom: 1px solid #ff5722; 
        padding-bottom: 2px; 
        font-size: 1em; 
    }
    .ticket-details p {
        margin: 0;
        color: #000; 
        font-size: 0.8em; 
    }
    .qr-code {
        text-align: center;
        margin-top: 5px; 
        padding: 5px; 
        background-color: #f8f9fa; 
        border-radius: 10px; 
        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); 
    }
    .qr-code img {
        width: 100px; // Set smaller QR code size
        height: 100px; // Set smaller QR code height
    }
    .footer {
        text-align: center;
        margin-top: 5px; 
        font-size: 0.8em;
        color: #666;
    }
    ';

    // Write the CSS to the PDF
    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);

    ob_start();
    ?>
    <div class="ticket-container">
        <div class="ticket-header">
            <h2>Waterway Reservation</h2>
            <p>Your Journey Awaits!</p>
        </div>

        <div class="ticket-details">
            <h4>User Details</h4>
            <p><strong>Name:</strong> <?= htmlspecialchars($user_name) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($user_email) ?></p>
        </div>

        <div class="ticket-details">
            <h4>Booking Details</h4>
            <p><strong>Boat Name:</strong> <?= htmlspecialchars($boat_name) ?></p>
            <p><strong>Schedule:</strong> <?= htmlspecialchars($schedule_details) ?></p>
            <p><strong>Arrival Time:</strong> <?= htmlspecialchars($arrival_time) ?></p>
            <p><strong>Total Amount:</strong> ₹<?= htmlspecialchars($total_amount) ?></p>
            <p><strong>Booking ID(s):</strong> 
                <?php
                $alphabetic_ids = array_map('numberToAlphabetic', $booking_ids);
                echo htmlspecialchars(implode(', ', $alphabetic_ids));
                ?>
            </p>
            <p><strong>Booking Date:</strong> <?= htmlspecialchars($system_date) ?></p>
        </div>
        
        <div class="qr-code">
            <img src="data:image/png;base64,<?= base64_encode($qrImageData) ?>" alt="QR Code">
        </div>
        
        <div class="footer">
            Thank you for choosing Waterway Reservations!
        </div>
    </div>
    <?php
    $html = ob_get_contents();
    ob_end_clean();
    
    $mpdf->WriteHTML($html, \Mpdf\HTMLParserMode::HTML_BODY);
    $pdfContent = $mpdf->Output('', 'S'); // Save PDF to string

    // PHPMailer setup
    $mail = new PHPMailer();
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'deepakjoshy17@gmail.com';
    $mail->Password   = 'qqzq rwul sjoh flnp';
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // Email content
    $mail->setFrom('no-reply@waterway.com', 'Waterway Reservations');
    $mail->addAddress($user_email); // Add recipient
    $mail->Subject = "Your Ticket for Waterway Reservation";
    $mail->Body = "Thank you for your booking. Please find your ticket attached.";
    $mail->addStringAttachment($pdfContent, 'ticket.pdf'); // Attach PDF content

    // Send the email
    if (!$mail->send()) {
        return false; // Return false if email could not be sent
    }
    return true; // Return true if email was sent successfully
}


// Check if the user clicked to send the PDF
if (isset($_GET['action']) && $_GET['action'] === 'send_email') {
    if (generatePDF($user_email, $user_name, $boat_name, $schedule_details, $arrival_time, $total_amount, $system_date, $qrImageData, $booking_ids)) {
        echo "<script>alert('Your ticket has been sent to your email.');</script>";
    } else {
        echo "<script>alert('There was an error sending your ticket. Please try again later.');</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="ie=edge" />
    <title>Your Ticket</title>
    <link rel="stylesheet" href="fontawesome-5.5/css/all.min.css" />
    <link rel="stylesheet" href="slick/slick.css">
    <link rel="stylesheet" href="slick/slick-theme.css">
    <link rel="stylesheet" href="magnific-popup/magnific-popup.css">
    <link rel="stylesheet" href="css/bootstrap.min.css" />
    <link rel="stylesheet" href="css/tooplate-infinite-loop.css" />
    <link href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css" rel="stylesheet">
    <style>
  
  body {
            background-color: #f5f5f5; /* Light gray background */
            font-family: 'Arial', sans-serif;
            color: #000; /* Black text */
        }
    .ticket-container {
    max-width: 400px; /* Smaller card width */
    margin: 40px auto;
    background-color: #ffffff; /* White background for the ticket */
    border-radius: 10px; /* Rounded corners */
    box-shadow: 0 8px 20px rgba(0, 0, 0, 0.2); /* Darker gray shadow */
    padding: 10px; /* Reduced padding for smaller height */
    border: 2px solid #6c757d; /* Gray border */
}

        .ticket-header {
            font-weight: bold;
            text-align: center;
            margin-bottom: 5px; /* Reduced margin */
        }
        .ticket-header h2 {
            color: #ff5722; /* Gray color for the header */
            font-weight: bold;
            font-size: 1.3em; /* Adjusted font size */
            margin-bottom: 3px; /* Added spacing */
        }
        .ticket-header p {
            font-size: 0.9em; /* Normal font size */
            color: #6c757d; /* Gray color for the header */
        }
        .ticket-details {
            margin-bottom: 10px; /* Reduced margin */
        }
        .ticket-details h4 {
            color: #ff5722; /* Orange theme color */
            margin-bottom: 3px;
            font-weight: bold; /* Bold headers */
            border-bottom: 1px solid #ff5722; /* Underline for emphasis */
            padding-bottom: 2px; /* Padding for spacing */
            font-size: 1em; /* Adjusted font size */
        }
        .ticket-details p {
            margin: 0;
            color: #000; /* Black for detail text */
            font-size: 0.8em; /* Smaller font size */
        }
        .qr-code {
            text-align: center;
            margin-top: 5px; /* Reduced margin */
            padding: 5px; /* Padding around the QR code section */
            background-color: #f8f9fa; /* Light gray background for QR section */
            border-radius: 10px; /* Rounded corners */
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1); /* Subtle shadow */
        }
        .qr-code img {
            width: 80px; /* Adjusted QR code size */
            height: 80px;
        }
        .footer {
            text-align: center;
            margin-top: 5px; /* Reduced margin */
            font-size: 0.8em;
            color: #666;
        }
        .cancel-button, .home-button, .email-button {
            display: block;
            width: 160px; /* Adjusted button width */
            margin: 5px auto; /* Centered buttons */
            padding: 6px 0; /* Padding for buttons */
            text-align: center;
            color: #fff;
            border: none;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9em; /* Normal font size */
            transition: background-color 0.3s ease;
        }
        .cancel-button {
            background-color: #f8d7da; /* Light red background for cancel */
            color: #721c24; /* Dark red text for contrast */
        }
        .cancel-button:hover {
            background-color: #f5c6cb; /* Darker shade on hover */
            color:black;
        }
        .home-button {
            background-color:  #ff5722; /* Light gray background for home */
            color: white; /* Black text */
        }
        .home-button:hover {
            background-color: #d6d8db; /* Darker shade on hover */
            color:black;
        }
        .email-button {
            background-color: #e2e3e5; /* Light gray background for home */
            color: #000; /* Black text */
        }
        .email-button:hover {
            background-color: #d6d8db; /* Darker shade on hover */
        }
    </style>
</head>
<body>
<div class="container">
    <div class="ticket-container">
        <div class="ticket-header">
            <h2>Waterway Reservation</h2>
            <p>Your Journey Awaits!</p>
        </div>

        <div class="ticket-details">
            <h4>User Details</h4>
            <p><strong>Name:</strong> <?= htmlspecialchars($user_name) ?></p>
            <p><strong>Email:</strong> <?= htmlspecialchars($user_email) ?></p>
        </div>

        <div class="ticket-details">
            <h4>Booking Details</h4>
            <p><strong>Boat Name:</strong> <?= htmlspecialchars($boat_name) ?></p>
            <p><strong>Schedule:</strong> <?= htmlspecialchars($schedule_details) ?></p>
            <p><strong>Seat Number(s):</strong> <?= htmlspecialchars(implode(', ', array_column($bookings, 'seat_number'))) ?></p>
            <p><strong>Arrival Time:</strong> <?= htmlspecialchars($arrival_time) ?></p>
            <p><strong>Total Amount:</strong> ₹<?= htmlspecialchars($total_amount) ?></p>
            <p><strong>Booking ID(s):</strong> 
                <?php
                $alphabetic_ids = array_map('numberToAlphabetic', $booking_ids);
                echo htmlspecialchars(implode(', ', $alphabetic_ids));
                ?>
            </p>
            <p><strong>Booking Date:</strong> <?= htmlspecialchars($system_date) ?></p>
        </div>
        
        <div class="qr-code">
            <img src="data:image/png;base64,<?= base64_encode($qrImageData) ?>" alt="QR Code">
        </div>
        
        <div class="footer">
            <form action="" method="get">
                <input type="hidden" name="action" value="send_email">
                <button type="submit" class="email-button">Email</button>
            </form>
            <a href="cancel_booking.php" class="cancel-button">Cancel Booking</a>
            <a href="userhome.php" class="home-button">Home</a>
        </div>
    </div>
</div>
<script src="js/bootstrap.bundle.min.js"></script>
</body>
</html>