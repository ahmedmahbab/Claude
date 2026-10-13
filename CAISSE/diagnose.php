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
    echo "<p class='error'>فشل الاتصال بقاعدة البيانات: " . htmlspecialchars($conn->connect_error) . "</p>";
    echo "</body></html>";
    exit;
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
            echo "<td>" . htmlspecialchars((string)$row["Field"]) . "</td>";
            echo "<td>" . htmlspecialchars((string)$row["Type"]) . "</td>";
            echo "<td>" . htmlspecialchars((string)($row["Default"] ?? "لا شيء")) . "</td>";
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
            echo "<td>" . htmlspecialchars((string)$row["id"]) . "</td>";
            echo "<td>" . htmlspecialchars((string)$row["username"]) . "</td>";
            echo "<td>" . htmlspecialchars((string)$row["full_name"]) . "</td>";
            echo "<td>" . htmlspecialchars((string)$row["role"]) . "</td>";
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
            echo "<td>" . htmlspecialchars((string)$row["Field"]) . "</td>";
            echo "<td>" . htmlspecialchars((string)$row["Type"]) . "</td>";
            echo "<td>" . htmlspecialchars((string)($row["Default"] ?? "لا شيء")) . "</td>";
            echo "<td>" . ($row["Null"] == "YES" ? "نعم" : "لا") . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Check that a record can be inserted (uses the current string operation-id format, then removes it)
    echo "<h3>اختبار إضافة سجل:</h3>";
    $test_date = date('Y-m-d');
    $operationId = 'DIAG-' . uniqid();
    echo "<p class='success'>رقم عملية تجريبي: " . htmlspecialchars($operationId) . "</p>";

    $stmt = $conn->prepare("INSERT INTO operations (`رقم العملية`, `التاريخ`, `إسم الزبون`, `المبلغ`, `الصندوق`, `الخدمة`, `تفصيل`, `حالة الدفع`, `العملية`, `المحرر`) VALUES (?, ?, 'زبون تجريبي', 100.00, 'CAISSE', 'خدمة تجريبية', 'تفاصيل تجريبية', 'دفع', 'بيع', 'admin')");
    if (!$stmt) {
        echo "<p class='error'>فشل في إعداد الاستعلام: " . htmlspecialchars($conn->error) . "</p>";
    } else {
        $stmt->bind_param("ss", $operationId, $test_date);
        if ($stmt->execute()) {
            echo "<p class='success'>تم إدخال سجل تجريبي بنجاح</p>";
            // Delete the test record
            $del = $conn->prepare("DELETE FROM operations WHERE `رقم العملية` = ?");
            $del->bind_param("s", $operationId);
            $del->execute();
        } else {
            echo "<p class='error'>فشل إدخال سجل تجريبي: " . htmlspecialchars($stmt->error) . "</p>";
        }
    }
} else {
    echo "<p class='error'>جدول العمليات غير موجود</p>";
}

$conn->close();

echo "</body>";
echo "</html>";
?>
