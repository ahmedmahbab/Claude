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
echo "<title>Check Users Status</title>";
echo "<style>";
echo "body { font-family: Arial, sans-serif; margin: 20px; }";
echo "table { border-collapse: collapse; width: 100%; }";
echo "th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }";
echo "th { background-color: #f2f2f2; }";
echo "</style>";
echo "</head>";
echo "<body>";

echo "<h1>حالة المستخدمين في قاعدة البيانات</h1>";

// Get all users and their status
$sql = "SELECT id, username, full_name, role, is_active, created_at FROM users ORDER BY id";
$result = $conn->query($sql);

if ($result->num_rows > 0) {
    echo "<table>";
    echo "<tr><th>ID</th><th>اسم المستخدم</th><th>الاسم الكامل</th><th>الدور</th><th>نشط</th><th>تاريخ الإنشاء</th></tr>";
    while($row = $result->fetch_assoc()) {
        echo "<tr>";
        echo "<td>" . $row["id"] . "</td>";
        echo "<td>" . $row["username"] . "</td>";
        echo "<td>" . $row["full_name"] . "</td>";
        echo "<td>" . $row["role"] . "</td>";
        echo "<td>" . ($row["is_active"] ? "نعم" : "لا") . "</td>";
        echo "<td>" . $row["created_at"] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>لا توجد مستخدمين في قاعدة البيانات.</p>";
}

// Check if operations table exists and get count
$sql = "SELECT COUNT(*) as count FROM operations";
$result = $conn->query($sql);
if ($result) {
    $row = $result->fetch_assoc();
    echo "<h2>إجمالي العمليات في قاعدة البيانات: " . $row["count"] . "</h2>";
} else {
    echo "<h2>جدول العمليات غير موجود أو غير قابل للوصول.</h2>";
}

$conn->close();

echo "</body>";
echo "</html>";
?>
