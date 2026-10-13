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
echo "<title>Check Users</title>";
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

echo "<h1>المستخدمون في قاعدة البيانات</h1>";

// Check database connection
if ($conn->connect_error) {
    echo "<p class='error'>فشل الاتصال بقاعدة البيانات: " . htmlspecialchars($conn->connect_error) . "</p>";
    echo "</body></html>";
    exit;
} else {
    echo "<p class='success'>تم الاتصال بقاعدة البيانات بنجاح</p>";
    
    // Get all users and their status
    $sql = "SELECT id, username, full_name, role, is_active FROM users ORDER BY id";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
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
    } else {
        echo "<p class='error'>لا توجد مستخدمين في قاعدة البيانات</p>";
    }
}

$conn->close();

echo "</body>";
echo "</html>";
?>
