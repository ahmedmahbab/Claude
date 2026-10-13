<?php
session_start();
if (!isset($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'admin')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    exit;
}

require_once 'db.php';

header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>";
echo "<html lang='ar' dir='rtl'>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<title>Diagnose System Issues</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; margin: 20px; }";
echo "table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }";
echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
echo "th { background-color: #f2f2f2; }";
echo ".error { color: red; }";
echo ".success { color: green; }";
echo "</style>";
echo "</head>";
echo "<body>";

echo "<h1>تشخيص مشاكل النظام</h1>";

// Check database connection
echo "<h2>1. حالة الاتصال بقاعدة البيانات</h2>";
if ($conn->connect_error) {
    echo "<p class='error'>فشل الاتصال بقاعدة البيانات: " . $conn->connect_error . "</p>";
} else {
    echo "<p class='success'>تم الاتصال بقاعدة البيانات بنجاح</p>";
}

// Check users table
echo "<h2>2. جدول المستخدمين</h2>";
$sql = "SHOW TABLES LIKE 'users'";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    echo "<p class='success'>جدول المستخدمين موجود</p>";
    
    // Get user structure
    $sql = "DESCRIBE users";
    $result = $conn->query($sql);
    if ($result) {
        echo "<table>";
        echo "<tr><th>الحقل</th><th>النوع</th><th>القيمة الافتراضية</th><th>فارغ</th></tr>";
        while($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row["Field"] . "</td>";
            echo "<td>" . $row["Type"] . "</td>";
            echo "<td>" . ($row["Default"] ?? "لا شيء") . "</td>";
            echo "<td>" . ($row["Null"] == "YES" ? "نعم" : "لا") . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Get all users and their status
    $sql = "SELECT id, username, full_name, role, is_active FROM users ORDER BY id";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        echo "<h3>المستخدمون في قاعدة البيانات:</h3>";
        echo "<table>";
        echo "<tr><th>ID</th><th>اسم المستخدم</th><th>الاسم الكامل</th><th>الدور</th><th>نشط</th></tr>";
        while($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row["id"] . "</td>";
            echo "<td>" . $row["username"] . "</td>";
            echo "<td>" . $row["full_name"] . "</td>";
            echo "<td>" . $row["role"] . "</td>";
            echo "<td>" . ($row["is_active"] ? "نعم" : "لا") . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} else {
    echo "<p class='error'>جدول المستخدمين غير موجود</p>";
}

// Check operations table
echo "<h2>3. جدول العمليات</h2>";
$sql = "SHOW TABLES LIKE 'operations'";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    echo "<p class='success'>جدول العمليات موجود</p>";
    
    // Get operations structure
    $sql = "DESCRIBE operations";
    $result = $conn->query($sql);
    if ($result) {
        echo "<table>";
        echo "<tr><th>الحقل</th><th>النوع</th><th>القيمة الافتراضية</th><th>فارغ</th></tr>";
        while($row = $result->fetch_assoc()) {
            echo "<tr>";
            echo "<td>" . $row["Field"] . "</td>";
            echo "<td>" . $row["Type"] . "</td>";
            echo "<td>" . ($row["Default"] ?? "لا شيء") . "</td>";
            echo "<td>" . ($row["Null"] == "YES" ? "نعم" : "لا") . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Check if we can insert a test record
    echo "<h3>اختبار إضافة سجل:</h3>";
    $test_date = date('Y-m-d');
    $test_user_id = 1; // Assuming user ID 1 exists
    
    // First, let's try to generate an operation ID like the system does
    $dateObj = new DateTime($test_date);
    $dayOfWeek = $dateObj->format('w');
    if ($dayOfWeek == 0) $dayOfWeek = 7;
    $day = $dateObj->format('d');
    $month = $dateObj->format('m');
    $year = $dateObj->format('y');
    $userIdPadded = str_pad($test_user_id, 3, '0', STR_PAD_LEFT);
    $test_username = 'admin'; // Using 'admin' as test username
    
    // Find the last operation ID for this user today
    $datePattern = $dateObj->format('Y-m-d');
    $stmt = $conn->prepare("SELECT MAX(`رقم العملية`) as max_id FROM operations WHERE `التاريخ` = ? AND `المحرر` = ?");
    $stmt->bind_param("ss", $datePattern, $test_username);
    
    if ($stmt->execute()) {
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $lastId = $row['max_id'];
        $operationNumber = 1;
        
        if ($lastId) {
            $lastOperationNumber = intval(substr($lastId, -4));
            $operationNumber = $lastOperationNumber + 1;
        }
        
        $operationNumberPadded = str_pad($operationNumber, 4, '0', STR_PAD_LEFT);
        $finalId = $dayOfWeek . $day . $month . $year . $userIdPadded . $operationNumberPadded;
        $operationId = intval($finalId);
        
        echo "<p class='success'>تم إنشاء رقم عملية تجريبي: " . $operationId . "</p>";
        
        // Now try to insert a test record
        $stmt = $conn->prepare("INSERT INTO operations (`رقم العملية`, `التاريخ`, `إسم الزبون`, `المبلغ`, `الصندوق`, `الخدمة`, `تفصيل`, `حالة الدفع`, `العملية`, `المحرر`) VALUES (?, ?, 'زبون تجريبي', 100.00, 'CAISSE', 'خدمة تجريبية', 'تفاصيل تجريبية', 'دفع', 'بيع', 'admin')");
        $stmt->bind_param("is", $operationId, $test_date);
        
        if ($stmt->execute()) {
            echo "<p class='success'>تم إدخال سجل تجريبي بنجاح</p>";
            // Delete the test record
            $stmt = $conn->prepare("DELETE FROM operations WHERE `رقم العملية` = ?");
            $stmt->bind_param("i", $operationId);
            $stmt->execute();
        } else {
            echo "<p class='error'>فشل إدخال سجل تجريبي: " . $stmt->error . "</p>";
        }
    } else {
        echo "<p class='error'>فشل في إعداد الاستعلام: " . $stmt->error . "</p>";
    }
} else {
    echo "<p class='error'>جدول العمليات غير موجود</p>";
}

$conn->close();

echo "</body>";
echo "</html>";
?>
