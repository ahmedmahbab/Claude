<?php
// api.php - Minimal API for user management and operations logging only
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors in production
ini_set('log_errors', 1);

// Clear any existing output buffers to prevent conflicts
while (ob_get_level()) {
    ob_end_clean();
}

// Start session with error handling
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
} catch (Exception $e) {
    error_log("Session start error: " . $e->getMessage());
}

require_once 'db.php';

// Set JSON response headers
header('Content-Type: application/json');

// CORS: this app is expected to be same-origin. Only allow local origins if a browser sends an Origin header.
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin) {
    $host = parse_url($origin, PHP_URL_HOST);
    if (in_array($host, ['localhost', '127.0.0.1'], true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }
}
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Fail fast with a JSON response if the database is not reachable (common when DB was deleted).
if (!isset($conn) || !($conn instanceof mysqli) || $conn->connect_error) {
    http_response_code(500);
    $err = (isset($conn) && $conn instanceof mysqli) ? $conn->connect_error : 'No DB connection object';
    error_log("API DB connection error: " . $err);
    echo json_encode([
        'success' => false,
        'message' => 'لا يمكن الاتصال بقاعدة البيانات. إذا قمت بحذفها، استورد الملف database/alnoure1_db_ane.sql عبر phpMyAdmin ثم أعد المحاولة.'
    ]);
    exit;
}

// Get JSON input
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);

// If JSON decode fails or input is empty, try to get from POST
if (!$input || !is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? '';

// Log API calls for debugging (redact secrets like passwords)
$logInput = $input;
foreach (['password', 'current_password', 'new_password', 'confirm_password', 'csrf_token'] as $secret) {
    unset($logInput[$secret]);
}
error_log("API Call: " . $action . " - " . json_encode($logInput));

// Add more detailed error handling
if (empty($action)) {
    error_log("Empty action received. Raw input length: " . strlen($raw_input) . ", POST keys: " . implode(',', array_keys($_POST)));
    echo json_encode(['success' => false, 'message' => 'No action specified']);
    exit;
}

try {
    switch ($action) {
        case 'get_profile':
        case 'update_profile':
        case 'change_password':
            require_once __DIR__ . '/profile_api.php';
            handleOwnAccount($action, $input);
            break;
        case 'login':
            handleLogin($input);
            break;
        case 'logout':
            handleLogout();
            break;
        case 'check_session':
            handleCheckSession();
            break;
        case 'add_record':
            handleAddRecord($input);
            break;
        case 'filter_records':
            handleFilterRecords($input);
            break;
        case 'get_transaction_by_id':
            handleGetTransactionById($input);
            break;
        case 'get_installment_info':
            handleGetInstallmentInfo($input);
            break;
        case 'update_record':
            handleUpdateRecord($input);
            break;
        case 'delete_record':
            handleDeleteRecord($input);
            break;
        case 'get_unpaid_count':
            handleGetUnpaidCount();
            break;
        case 'get_users':
            handleGetUsers();
            break;
        case 'add_user':
            handleAddUser($input);
            break;
        case 'get_user_details':
            handleGetUserDetails($input);
            break;
        case 'update_user':
            handleUpdateUser($input);
            break;
        case 'delete_user':
            handleDeleteUser($input);
            break;
        case 'toggle_user_status':
            handleToggleUserStatus($input);
            break;
        case 'get_customer_names':
            handleGetCustomerNames();
            break;
        case 'search_customer_names':
            handleSearchCustomerNames($input);
            break;
        default:
            throw new Exception('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage());
    $isAuthError = ($e->getMessage() === 'Authentication required');
    echo json_encode([
        'success' => false,
        'message' => $isAuthError ? 'انتهت الجلسة. يرجى تسجيل الدخول من جديد.' : $e->getMessage(),
        'auth_required' => $isAuthError
    ]);
}

function handleLogin($input) {
    global $conn;
    
    try {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            echo json_encode(['success' => false, 'message' => 'اسم المستخدم وكلمة المرور مطلوبان']);
            exit;
        }
        
        $stmt = $conn->prepare("SELECT * FROM users WHERE username = ? AND is_active = 1");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 1) {
            $user = $result->fetch_assoc();
            // Check if password is hashed (starts with $2y$) or plain text
            $passwordVerified = false;
            $passwordNeedsRehash = false;
            
            if (strpos($user['password'], '$2y$') === 0) {
                // Password is already hashed
                $passwordVerified = password_verify($password, $user['password']);
            } else {
                // Password is plain text (old format)
                $passwordVerified = ($password === $user['password']);
                $passwordNeedsRehash = true;
            }
            
            if ($passwordVerified) {
                // If password is plain text, update it to hashed format
                if ($passwordNeedsRehash) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $update_stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
                    $update_stmt->bind_param("si", $hashed_password, $user['id']);
                    $update_stmt->execute();
                    $update_stmt->close();
                    // Update the user array with the new hashed password
                    $user['password'] = $hashed_password;
                }

                // Prevent session fixation on successful login
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $_SESSION['full_name'] = $user['full_name'];
                
                // Close session before output
                session_write_close();
                
                // Log successful login
                file_put_contents('login_log.txt', date('Y-m-d H:i:s') . " - Login successful: {$user['username']}\n", FILE_APPEND);
                
                echo json_encode([
                    'success' => true,
                    'user' => [
                        'id' => $user['id'],
                        'username' => $user['username'],
                        'full_name' => $user['full_name'],
                        'role' => $user['role']
                    ]
                ]);
                exit;
            } else {
                file_put_contents('login_log.txt', date('Y-m-d H:i:s') . " - Login failed (wrong password): $username\n", FILE_APPEND);
                echo json_encode(['success' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة']);
                exit;
            }
        } else {
            file_put_contents('login_log.txt', date('Y-m-d H:i:s') . " - Login failed (user not found): $username\n", FILE_APPEND);
            echo json_encode(['success' => false, 'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة']);
            exit;
        }
    } catch (Exception $e) {
        error_log("handleLogin error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Login error: ' . $e->getMessage()]);
        exit;
    }
}

function handleLogout() {
    try {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['username'])) {
            file_put_contents('login_log.txt', date('Y-m-d H:i:s') . " - Logout: {$_SESSION['username']}\n", FILE_APPEND);
        }
        
        session_destroy();
        echo json_encode(['success' => true]);
        exit;
    } catch (Exception $e) {
        error_log("handleLogout error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Logout error: ' . $e->getMessage()]);
        exit;
    }
}

function handleCheckSession() {
    requireAuth();
    try {
        // Clear any output buffers
        while (ob_get_level()) {
            ob_end_clean();
        }
        
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if session variables exist and are valid
        if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
            $response = [
                'success' => true,
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'username' => $_SESSION['username'] ?? '',
                    'full_name' => $_SESSION['full_name'] ?? '',
                    'role' => $_SESSION['role'] ?? 'user'
                ]
            ];
            
            // Make sure to close session before output
            session_write_close();
            
            // Clear any headers that might interfere
            if (!headers_sent()) {
                header('Content-Type: application/json');
            }
            
            // Send response
            echo json_encode($response);
            exit; // Important: exit after sending response
        } else {
            echo json_encode(['success' => false, 'message' => 'No active session']);
            exit;
        }
    } catch (Exception $e) {
        error_log("handleCheckSession error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Session error: ' . $e->getMessage()]);
        exit;
    }
}

function requireAuth() {
    global $conn;
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (!empty($_SESSION['user_id'])) {
        $stmt = $conn->prepare("SELECT username, full_name, role FROM users WHERE id = ? AND is_active = 1");
        $stmt->bind_param("i", $_SESSION['user_id']);
        $stmt->execute();
        $activeUser = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$activeUser) {
            $_SESSION = [];
            throw new Exception('Authentication required');
        }
        foreach ($activeUser as $key => $value) $_SESSION[$key] = $value;
    }
    try {
        // Ensure session is started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
            error_log("requireAuth: Authentication failed - no user_id in session");
            throw new Exception('Authentication required');
        }
        
        error_log("requireAuth: Authentication successful for user " . $_SESSION['username']);
    } catch (Exception $e) {
        error_log("requireAuth error: " . $e->getMessage());
        throw $e;
    }
}

function requireAdmin() {
    requireAuth();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        error_log("requireAdmin: Admin privileges required for user " . ($_SESSION['username'] ?? 'unknown'));
        throw new Exception('Admin privileges required');
    }
}

function generateOperationId($date, $userId, $username, $sequenceOffset = 0) {
    // New format: userId-YYYY-DD-MM-seq (example: 1-2026-31-01-001).
    // Sequence is computed from matching prefixes only, so legacy IDs
    // do not block inserts.
    global $conn;

    $dateObj = new DateTime($date);
    $datePattern = $dateObj->format('Y-m-d');
    $userIdStr = strval($userId);
    $year = $dateObj->format('Y');
    $day = $dateObj->format('d');
    $month = $dateObj->format('m');
    $prefix = $userIdStr . '-' . $year . '-' . $day . '-' . $month . '-';

    $stmt = $conn->prepare("SELECT `رقم العملية` FROM operations WHERE `التاريخ` = ? AND `المحرر` = ? AND `رقم العملية` LIKE ?");
    if (!$stmt) {
        throw new Exception('Failed to prepare operation id query: ' . $conn->error);
    }

    $likePattern = $prefix . '%';
    $stmt->bind_param("sss", $datePattern, $username, $likePattern);
    $stmt->execute();
    $result = $stmt->get_result();

    $maxSequence = 0;
    while ($row = $result->fetch_assoc()) {
        $existingId = strval($row['رقم العملية'] ?? '');
        if (strpos($existingId, $prefix) !== 0) {
            continue;
        }

        $sequencePart = substr($existingId, strlen($prefix));
        if ($sequencePart !== '' && ctype_digit($sequencePart)) {
            $seq = intval($sequencePart);
            if ($seq > $maxSequence) {
                $maxSequence = $seq;
            }
        }
    }
    $stmt->close();

    $sequence = $maxSequence + 1 + max(0, intval($sequenceOffset));
    $sequencePadded = str_pad(strval($sequence), 3, '0', STR_PAD_LEFT);
    return $prefix . $sequencePadded;
}

function normalizeMatchValue($value) {
    $normalized = trim((string)$value);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($normalized, 'UTF-8');
    }
    return strtolower($normalized);
}

function extractTotalAmountFromDetails($details) {
    $detailsText = (string)$details;
    if (preg_match('/إجمالي المشروع:\s*([0-9]+(?:\.[0-9]+)?)/u', $detailsText, $m)) {
        return floatval($m[1]);
    }
    return null;
}

function getInstallmentGroupInfo($groupId) {
    global $conn;

    $stmt = $conn->prepare("SELECT `إسم الزبون` AS customer, `الخدمة` AS service, `المبلغ` AS amount, `تفصيل` AS details FROM operations WHERE `project_id` = ? ORDER BY `التاريخ` ASC, `رقم العملية` ASC");
    if (!$stmt) {
        throw new Exception('فشل تحميل بيانات التقسيط: ' . $conn->error);
    }

    $stmt->bind_param("s", $groupId);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $stmt->close();
        throw new Exception('رمز المتابعة غير موجود');
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    $first = $rows[0];
    $customer = trim((string)($first['customer'] ?? ''));
    $service = trim((string)($first['service'] ?? ''));

    $totalAmount = null;
    $paidAmount = 0.0;
    $secondExists = false;

    foreach ($rows as $row) {
        $paidAmount += floatval($row['amount'] ?? 0);
        $detailsText = (string)($row['details'] ?? '');

        if ($totalAmount === null) {
            $parsedTotal = extractTotalAmountFromDetails($detailsText);
            if ($parsedTotal !== null && $parsedTotal > 0) {
                $totalAmount = $parsedTotal;
            }
        }

        $hasSecondMarker = function_exists('mb_strpos')
            ? (mb_strpos($detailsText, 'الشطر الثاني', 0, 'UTF-8') !== false)
            : (strpos($detailsText, 'الشطر الثاني') !== false);
        if ($hasSecondMarker) {
            $secondExists = true;
        }
    }

    if ($totalAmount === null || $totalAmount <= 0) {
        // Backward compatibility: old records may not have total amount saved.
        // If both halves already exist, treat total as fully paid amount.
        // If only one installment exists, assume two equal halves.
        if ($secondExists) {
            $totalAmount = $paidAmount;
        } elseif (count($rows) === 1) {
            $totalAmount = $paidAmount * 2;
        } else {
            throw new Exception('لا يمكن تحديد المبلغ الإجمالي من العملية الأولى');
        }
    }

    $remainingAmount = max(0.0, $totalAmount - $paidAmount);

    return [
        'customer' => $customer,
        'service' => $service,
        'total_amount' => round($totalAmount, 2),
        'paid_amount' => round($paidAmount, 2),
        'remaining_amount' => round($remainingAmount, 2),
        'second_exists' => $secondExists
    ];
}

function handleAddRecord($input) {
    global $conn;
    requireAuth();
    
    $date = trim((string)($input['date'] ?? ''));
    $customer = trim((string)($input['customer'] ?? ''));
    $amount = floatval($input['amount'] ?? 0);
    $source = trim((string)($input['source'] ?? ''));
    $service = trim((string)($input['service'] ?? ''));
    $details = trim((string)($input['details'] ?? ''));
    $status = trim((string)($input['status'] ?? ''));
    $transactionType = 'بيع';
    $editor = $_SESSION['username'];
    $userId = $_SESSION['user_id'];

    if (empty($date)) {
        $date = date('Y-m-d');
    }

    if (empty($customer) || empty($source) || empty($service) || empty($status)) {
        throw new Exception('جميع الحقول مطلوبة');
    }

    if ($amount <= 0) {
        throw new Exception('المبلغ غير صحيح');
    }

    // الاسترجاع: عملية تُخرج أموالاً، تُسجَّل بالسالب مع ذكر السبب في التفاصيل.
    $isRefund = ($status === 'استرجاع');
    if ($isRefund && $details === '') {
        throw new Exception('يرجى ذكر سبب الاسترجاع في التفاصيل');
    }

    // Split-payment tracking data (uses existing project_id column)
    $project_id = null;
    $supplier_name = null;
    $supplier_cost = 0.0;
    $installment_group_id = trim((string)($input['installment_group_id'] ?? ''));
    $installment_part = trim((string)($input['installment_part'] ?? ''));
    $total_amount = floatval($input['total_amount'] ?? 0);
    
    if (($input['is_installment'] ?? false) && !$isRefund) {
        if ($installment_part === '') {
            $installment_part = 'full';
        }

        if (!in_array($installment_part, ['first', 'second', 'full'], true)) {
            throw new Exception('نوع الشطر غير صالح');
        }

        if ($installment_part === 'first') {
            if ($total_amount <= 0) {
                throw new Exception('المبلغ الإجمالي للمشروع مطلوب');
            }
            if ($amount >= $total_amount) {
                throw new Exception('الشطر الأول يجب أن يكون أقل من المبلغ الإجمالي');
            }
            $project_id = uniqid('proj_');
            $details = $details ? ($details . ' | الشطر الأول') : 'الشطر الأول';
        } else if ($installment_part === 'second') {
            if ($installment_group_id === '') {
                throw new Exception('رمز متابعة الشطر الأول مطلوب');
            }

            $groupInfo = getInstallmentGroupInfo($installment_group_id);

            if (normalizeMatchValue($customer) !== normalizeMatchValue($groupInfo['customer'])) {
                throw new Exception('رمز المتابعة لا يخص نفس العميل');
            }

            if (normalizeMatchValue($service) !== normalizeMatchValue($groupInfo['service'])) {
                throw new Exception('رمز المتابعة لا يخص نفس الخدمة');
            }

            if ($groupInfo['second_exists']) {
                throw new Exception('تم تسجيل الشطر الثاني مسبقًا لهذا الرمز');
            }

            $remainingAmount = floatval($groupInfo['remaining_amount']);
            if ($remainingAmount <= 0) {
                throw new Exception('لا يوجد مبلغ متبقي لهذا الرمز');
            }

            if (abs($amount - $remainingAmount) > 0.01) {
                throw new Exception('مبلغ الشطر الثاني يجب أن يساوي المبلغ المتبقي');
            }

            $project_id = $installment_group_id;
            $total_amount = floatval($groupInfo['total_amount']);
            $details = $details ? ($details . ' | الشطر الثاني') : 'الشطر الثاني';
        } else {
            if ($total_amount <= 0) {
                throw new Exception('المبلغ الإجمالي للمشروع مطلوب');
            }

            if (abs($amount - $total_amount) > 0.01) {
                throw new Exception('في الدفع الكامل يجب أن يساوي المبلغ الإجمالي');
            }

            $details = $details ? ($details . ' | دفع كامل') : 'دفع كامل';
        }

        if ($project_id && strpos($details, "رمز متابعة: {$project_id}") === false) {
            $details = $details ? ($details . " | رمز متابعة: {$project_id}") : "رمز متابعة: {$project_id}";
        }

        if ($total_amount > 0 && strpos($details, 'إجمالي المشروع:') === false) {
            $details = $details ? ($details . ' | إجمالي المشروع: ' . number_format($total_amount, 2, '.', '')) : ('إجمالي المشروع: ' . number_format($total_amount, 2, '.', ''));
        }
    }

    if ($isRefund) {
        $amount = -abs($amount);
    }

    // Retry insert to handle concurrent requests safely.
    $maxAttempts = 5;
    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $operationId = generateOperationId($date, $userId, $editor, $attempt);
        $stmt = $conn->prepare("INSERT INTO operations (`رقم العملية`, `التاريخ`, `إسم الزبون`, `المبلغ`, `الصندوق`, `الخدمة`, `تفصيل`, `حالة الدفع`, `العملية`, `المحرر`, `project_id`, `supplier_name`, `supplier_cost`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if (!$stmt) {
            throw new Exception('Failed to prepare insert statement: ' . $conn->error);
        }

        $stmt->bind_param("sssdssssssssd", $operationId, $date, $customer, $amount, $source, $service, $details, $status, $transactionType, $editor, $project_id, $supplier_name, $supplier_cost);

        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'تم إضافة السجل بنجاح',
                'operation_id' => $operationId,
                'project_id' => $project_id,
                'installment_part' => $installment_part
            ]);
            $stmt->close();
            return;
        }

        $stmtError = $stmt->error;
        $stmtErrno = intval($stmt->errno);
        $stmt->close();

        // Duplicate key can happen when two requests submit at the same instant.
        if ($stmtErrno === 1062) {
            error_log("handleAddRecord duplicate operation id on attempt " . ($attempt + 1) . " for user {$editor}");
            continue;
        }

        throw new Exception('فشل إضافة السجل: ' . $stmtError);
    }

    throw new Exception('تعذر إنشاء رقم عملية فريد. يرجى إعادة المحاولة.');
}
function handleFilterRecords($input) {
    global $conn;
    requireAuth();
    
    $conditions = [];
    $params = [];
    $types = "";
    
    if (!empty($input['id'])) {
        $conditions[] = "`رقم العملية` = ?";
        $params[] = $input['id'];
        $types .= "s";
    }
    
    if (!empty($input['fromDate'])) {
        $conditions[] = "`التاريخ` >= ?";
        $params[] = $input['fromDate'];
        $types .= "s";
    }
    
    if (!empty($input['toDate'])) {
        $conditions[] = "`التاريخ` <= ?";
        $params[] = $input['toDate'];
        $types .= "s";
    }
    
    if (!empty($input['source'])) {
        $conditions[] = "`الصندوق` = ?";
        $params[] = $input['source'];
        $types .= "s";
    }
    
    if (!empty($input['service'])) {
        $conditions[] = "`الخدمة` = ?";
        $params[] = $input['service'];
        $types .= "s";
    }
    
    if (!empty($input['status'])) {
        $conditions[] = "`حالة الدفع` = ?";
        $params[] = $input['status'];
        $types .= "s";
    }
    
    if (!empty($input['customer'])) {
        $conditions[] = "`إسم الزبون` LIKE ?";
        $params[] = '%' . $input['customer'] . '%';
        $types .= "s";
    }
    
    if (!empty($input['transactionType'])) {
        $conditions[] = "`العملية` = ?";
        $params[] = $input['transactionType'];
        $types .= "s";
    }
    
    // Add editor filter - only available to admin users
    if (!empty($input['editor']) && $_SESSION['role'] === 'admin') {
        $conditions[] = "`المحرر` = ?";
        $params[] = $input['editor'];
        $types .= "s";
    }
    
    if (!empty($input['project_id'])) {
        $conditions[] = "`project_id` = ?";
        $params[] = $input['project_id'];
        $types .= "s";
    }
    
    $sql = "SELECT *, (SELECT full_name FROM users WHERE username = operations.`المحرر`) as editor_full_name FROM operations";
    if (!empty($conditions)) {
        $sql .= " WHERE " . implode(" AND ", $conditions);
    }
    // Order by date first because operation id is now a string (new format may not sort chronologically).
    $sql .= " ORDER BY `التاريخ` DESC, `رقم العملية` DESC";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
    } else {
        $result = $conn->query($sql);
    }
    
    $records = [];
    while ($row = $result->fetch_assoc()) {
        $records[] = $row;
    }
    
    echo json_encode($records);
}

function handleGetTransactionById($input) {
    global $conn;
    requireAuth();
    
    $id = $input['id'] ?? '';
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'معرف العملية مطلوب']);
        return;
    }
    
    $stmt = $conn->prepare("SELECT * FROM operations WHERE `رقم العملية` = ?");
    $stmt->bind_param("s", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        echo json_encode(['success' => true, 'data' => $result->fetch_assoc()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'السجل غير موجود']);
    }
}

function handleGetInstallmentInfo($input) {
    requireAuth();

    $groupId = trim((string)($input['installment_group_id'] ?? ''));
    if ($groupId === '') {
        echo json_encode(['success' => false, 'message' => 'رمز المتابعة مطلوب']);
        return;
    }

    $info = getInstallmentGroupInfo($groupId);
    echo json_encode([
        'success' => true,
        'installment_group_id' => $groupId,
        'customer' => $info['customer'],
        'service' => $info['service'],
        'total_amount' => $info['total_amount'],
        'paid_amount' => $info['paid_amount'],
        'remaining_amount' => $info['remaining_amount'],
        'second_exists' => $info['second_exists']
    ]);
}

function handleUpdateRecord($input) {
    global $conn;
    requireAuth();
    
    error_log("handleUpdateRecord: Starting function");
    error_log("handleUpdateRecord: Input data: " . json_encode($input));
    
    $id = trim((string)($input['id'] ?? ''));
    $date = $input['date'] ?? '';
    $customer = $input['customer'] ?? '';
    $amount = floatval($input['amount'] ?? 0);
    $source = $input['source'] ?? '';
    $service = $input['service'] ?? '';
    $details = $input['details'] ?? '';
    $status = $input['status'] ?? '';
    $transactionType = 'بيع';
    
    error_log("handleUpdateRecord: Parsed values - ID: $id, Date: $date, Customer: $customer, Amount: $amount, Source: $source, Service: $service, Status: $status, TransactionType: $transactionType");

    if ($id === '') {
        throw new Exception('معرف العملية مطلوب');
    }

    if (empty($date)) {
        $date = date('Y-m-d');
    }

    if (trim($customer) === '' || trim($source) === '' || trim($service) === '' || trim($status) === '') {
        throw new Exception('جميع الحقول مطلوبة');
    }

    if ($amount <= 0) {
        throw new Exception('المبلغ غير صحيح');
    }

    // الاسترجاع: يُخزَّن بالسالب مع وجوب ذكر السبب في التفاصيل.
    if ($status === 'استرجاع') {
        if (trim($details) === '') {
            throw new Exception('يرجى ذكر سبب الاسترجاع في التفاصيل');
        }
        $amount = -abs($amount);
    }

    // Handle project total amount update
    if (isset($input['new_total_amount']) && $input['new_total_amount'] > 0) {
        $new_total = floatval($input['new_total_amount']);
        $details = preg_replace('/المبلغ الإجمالي: \d+\.?\d*/', "المبلغ الإجمالي: $new_total", $details);
        error_log("handleUpdateRecord: Updated project total amount to: $new_total");
    }
    
    error_log("handleUpdateRecord: Details before update: $details");
    
    // Check if database connection is still valid
    if (!$conn || $conn->connect_error) {
        error_log("handleUpdateRecord: Database connection error: " . ($conn ? $conn->connect_error : "Connection is null"));
        throw new Exception('فشل الاتصال بقاعدة البيانات');
    }
    
    error_log("handleUpdateRecord: Preparing SQL statement");
    $stmt = $conn->prepare("UPDATE operations SET `التاريخ` = ?, `إسم الزبون` = ?, `المبلغ` = ?, `الصندوق` = ?, `الخدمة` = ?, `تفصيل` = ?, `حالة الدفع` = ?, `العملية` = ? WHERE `رقم العملية` = ?");
    
    if (!$stmt) {
        error_log("handleUpdateRecord: Failed to prepare statement: " . $conn->error);
        throw new Exception('فشل في إعداد الاستعلام: ' . $conn->error);
    }
    
    error_log("handleUpdateRecord: Binding parameters");
    // Operation ID may be numeric (legacy) or string (new format); always bind as string.
    $bind_result = $stmt->bind_param("ssdssssss", $date, $customer, $amount, $source, $service, $details, $status, $transactionType, $id);
    
    if (!$bind_result) {
        error_log("handleUpdateRecord: Failed to bind parameters: " . $stmt->error);
        throw new Exception('فشل في ربط المعاملات: ' . $stmt->error);
    }
    
    error_log("handleUpdateRecord: Executing statement");
    $execute_result = $stmt->execute();
    
    if ($execute_result) {
        // If no rows were affected, it may mean either "no changes" or "record not found".
        if ($stmt->affected_rows === 0) {
            $check = $conn->prepare("SELECT 1 FROM operations WHERE `رقم العملية` = ? LIMIT 1");
            if ($check) {
                $check->bind_param("s", $id);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if (!$exists) {
                    throw new Exception('السجل غير موجود');
                }
            }
        }

        error_log("handleUpdateRecord: Update successful");
        echo json_encode(['success' => true, 'message' => 'تم تحديث السجل بنجاح']);
    } else {
        error_log("handleUpdateRecord: Update failed: " . $stmt->error);
        throw new Exception('فشل تحديث السجل: ' . $stmt->error);
    }
    
    error_log("handleUpdateRecord: Function completed");
}

function handleDeleteRecord($input) {
    global $conn;
    requireAdmin();
    
    $id = $input['id'] ?? '';
    
    if (empty($id)) {
        echo json_encode(['success' => false, 'message' => 'معرف العملية مطلوب']);
        return;
    }
    
    $stmt = $conn->prepare("DELETE FROM operations WHERE `رقم العملية` = ?");
    $stmt->bind_param("s", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'تم حذف السجل بنجاح']);
    } else {
        throw new Exception('فشل حذف السجل: ' . $conn->error);
    }
}

function handleGetUnpaidCount() {
    global $conn;
    requireAuth();
    
    $current_month = date('Y-m');
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM operations WHERE `حالة الدفع` = 'لم يدفع' AND `التاريخ` LIKE ?");
    $month_pattern = $current_month . '%';
    $stmt->bind_param("s", $month_pattern);
    $stmt->execute();
    $result = $stmt->get_result();
    
    echo json_encode($result->fetch_assoc());
}

// User management functions
function handleGetUsers() {
    global $conn;
    requireAdmin();
    
    $result = $conn->query("SELECT id, username, full_name, role, is_active, created_at FROM users ORDER BY id");
    $users = [];
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    echo json_encode(['success' => true, 'users' => $users]);
}

function handleAddUser($input) {
    global $conn;
    requireAdmin();
    
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';
    $full_name = $input['full_name'] ?? '';
    $role = $input['role'] ?? 'user';
    
    if (empty($username) || empty($password) || empty($full_name)) {
        throw new Exception('جميع الحقول مطلوبة');
    }
    
    // Check if username already exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new Exception('اسم المستخدم موجود بالفعل');
    }
    
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("INSERT INTO users (username, password, full_name, role) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("ssss", $username, $hashed_password, $full_name, $role);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'تم إضافة المستخدم بنجاح']);
    } else {
        throw new Exception('فشل إضافة المستخدم: ' . $conn->error);
    }
}

function handleGetUserDetails($input) {
    global $conn;
    requireAdmin();
    
    $id = intval($input['id'] ?? 0);
    
    $stmt = $conn->prepare("SELECT id, username, full_name, role, is_active FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 1) {
        echo json_encode(['success' => true, 'user' => $result->fetch_assoc()]);
    } else {
        echo json_encode(['success' => false, 'message' => 'المستخدم غير موجود']);
    }
}

function handleUpdateUser($input) {
    global $conn;
    requireAdmin();
    
    $id = intval($input['id'] ?? 0);
    $username = $input['username'] ?? '';
    $full_name = $input['full_name'] ?? '';
    $role = $input['role'] ?? 'user';
    $password = $input['password'] ?? '';
    
    if (empty($username) || empty($full_name)) {
        throw new Exception('اسم المستخدم والاسم الكامل مطلوبان');
    }
    
    // Check if username is taken by another user
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
    $stmt->bind_param("si", $username, $id);
    $stmt->execute();
    if ($stmt->get_result()->num_rows > 0) {
        throw new Exception('اسم المستخدم موجود بالفعل');
    }
    
    if (!empty($password)) {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET username = ?, password = ?, full_name = ?, role = ? WHERE id = ?");
        $stmt->bind_param("ssssi", $username, $hashed_password, $full_name, $role, $id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET username = ?, full_name = ?, role = ? WHERE id = ?");
        $stmt->bind_param("sssi", $username, $full_name, $role, $id);
    }
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'تم تحديث المستخدم بنجاح']);
    } else {
        throw new Exception('فشل تحديث المستخدم: ' . $conn->error);
    }
}

function handleDeleteUser($input) {
    global $conn;
    requireAdmin();
    
    $id = intval($input['id'] ?? 0);
    
    // Prevent admin from deleting themselves
    if ($id == $_SESSION['user_id']) {
        throw new Exception('لا يمكنك حذف حسابك الخاص');
    }
    
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'تم حذف المستخدم بنجاح']);
    } else {
        throw new Exception('فشل حذف المستخدم: ' . $conn->error);
    }
}

function handleToggleUserStatus($input) {
    global $conn;
    requireAdmin();
    
    $id = intval($input['id'] ?? 0);
    
    // Prevent admin from deactivating themselves
    if ($id == $_SESSION['user_id']) {
        throw new Exception('لا يمكنك تعطيل حسابك الخاص');
    }
    
    $stmt = $conn->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?");
    $stmt->bind_param("i", $id);
    
    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'تم تحديث حالة المستخدم بنجاح']);
    } else {
        throw new Exception('فشل تحديث حالة المستخدم: ' . $conn->error);
    }
}

function handleGetCustomerNames() {
    global $conn;
    requireAuth();
    
    $stmt = $conn->prepare("SELECT DISTINCT `إسم الزبون` FROM operations WHERE `إسم الزبون` IS NOT NULL AND `إسم الزبون` != '' ORDER BY `إسم الزبون`");
    $stmt->execute();
    $result = $stmt->get_result();
    
    $customerNames = [];
    while ($row = $result->fetch_assoc()) {
        $customerNames[] = $row['إسم الزبون'];
    }
    
    echo json_encode(['success' => true, 'customerNames' => $customerNames]);
}

function handleSearchCustomerNames($input) {
    global $conn;
    requireAuth();
    
    $searchTerm = $input['search'] ?? '';
    
    if (empty($searchTerm)) {
        // If no search term, return all customer names (like the existing function)
        $stmt = $conn->prepare("SELECT DISTINCT `إسم الزبون` FROM operations WHERE `إسم الزبون` IS NOT NULL AND `إسم الزبون` != '' ORDER BY `إسم الزبون`");
        $stmt->execute();
    } else {
        // Search for customer names that contain the search term
        $searchTerm = '%' . $searchTerm . '%';
        $stmt = $conn->prepare("SELECT DISTINCT `إسم الزبون` FROM operations WHERE `إسم الزبون` IS NOT NULL AND `إسم الزبون` != '' AND `إسم الزبون` LIKE ? ORDER BY `إسم الزبون`");
        $stmt->bind_param("s", $searchTerm);
        $stmt->execute();
    }
    
    $result = $stmt->get_result();
    
    $customerNames = [];
    while ($row = $result->fetch_assoc()) {
        $customerNames[] = $row['إسم الزبون'];
    }
    
    echo json_encode(['success' => true, 'customerNames' => $customerNames]);
}
?>

