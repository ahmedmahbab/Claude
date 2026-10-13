<?php
// db.php

// استبدل هذه القيم بمعلومات قاعدة البيانات الخاصة بك
$servername = "localhost"; // أو عنوان IP لخادم قاعدة البيانات
$username = "root";      // اسم مستخدم قاعدة البيانات
$password = "";          // كلمة مرور قاعدة البيانات
$dbname = "alnoure1_db_ane";   // اسم قاعدة البيانات

// إنشاء الاتصال
$conn = new mysqli($servername, $username, $password, $dbname);

// التحقق من الاتصال
if ($conn->connect_error) {
  // Don't hard-exit here; let callers (api.php/diagnose.php) decide how to respond.
  error_log("DB connection failed: " . $conn->connect_error);
} else {
  // تعيين ترميز الاتصال إلى UTF-8
  $conn->set_charset("utf8mb4");
}
?>
