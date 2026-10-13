<?php
function accountQuery($sql, $types, $values) {
    global $conn;
    $stmt = $conn->prepare($sql);
    if (!$stmt) throw new Exception('تعذر حفظ البيانات.');
    $stmt->bind_param($types, ...$values);
    if (!$stmt->execute()) {
        $duplicate = $stmt->errno === 1062;
        $stmt->close();
        throw new Exception($duplicate ? 'اسم المستخدم موجود بالفعل' : 'تعذر حفظ البيانات.');
    }
    return $stmt;
}
function validateAccountName($name, $username) {
    if (!is_string($name) || !is_string($username)) throw new Exception('بيانات غير صحيحة');
    $name = preg_replace('/\\s+/u', ' ', trim($name));
    $username = trim($username);
    if (!$name || !$username || preg_match_all('/./us', $name) > 255 ||
        preg_match_all('/./us', $username) > 50 ||
        !preg_match('/^[\\p{L}\\p{M}\\p{N}_.-]+$/u', $username)) {
        throw new Exception('أدخل الاسم الكامل (حتى 255 حرفاً) واسم المستخدم (حتى 50 حرفاً) بحروف أو أرقام أو . أو _ أو - دون مسافات.');
    }
    $parts = explode(' ', $name);
    $first = preg_quote($parts[0], '~');
    $last = preg_quote($parts[count($parts) - 1], '~');
    if (!preg_match("~(?:$first|$last)~iu", $username)) {
        throw new Exception('يجب أن يحتوي اسم المستخدم على الاسم الأول أو اللقب الأخير كما كتبته في الاسم الكامل.');
    }
    return [$name, $username];
}
function handleOwnAccount($action, $input) {
    global $conn;
    requireAuth();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new Exception('طلب غير صالح');
    if (empty($_SESSION['account_csrf'])) $_SESSION['account_csrf'] = bin2hex(random_bytes(32));
    if ($action === 'get_profile') {
        echo json_encode(['success' => true, 'csrf_token' => $_SESSION['account_csrf'], 'user' => [
            'full_name' => $_SESSION['full_name'], 'username' => $_SESSION['username']
        ]]);
        return;
    }
    if (!is_string($input['csrf_token'] ?? null) || !hash_equals($_SESSION['account_csrf'], $input['csrf_token'])) {
        throw new Exception('يرجى تحديث الصفحة ثم المحاولة مجدداً.');
    }
    $id = (int) $_SESSION['user_id'];
    if (!$conn->begin_transaction()) throw new Exception('تعذر حفظ البيانات.');
    try {
        $stmt = accountQuery('SELECT username, password FROM users WHERE id = ? AND is_active = 1 FOR UPDATE', 'i', [$id]);
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) throw new Exception('Authentication required');
        if ($action === 'change_password') {
            $current = $input['current_password'] ?? null;
            $new = $input['new_password'] ?? null;
            $confirm = $input['confirm_password'] ?? null;
            if (!is_string($current) || !is_string($new) || !is_string($confirm) || $current === '' || $new === '') {
                throw new Exception('يرجى ملء جميع حقول كلمة المرور.');
            }
            $info = password_get_info($user['password']);
            $valid = !empty($info['algo']) ? password_verify($current, $user['password']) : hash_equals($user['password'], $current);
            if (!$valid) throw new Exception('كلمة المرور الحالية غير صحيحة.');
            if ($new !== $confirm) throw new Exception('تأكيد كلمة المرور غير مطابق.');
            if (preg_match_all('/./us', $new) < 8 || strlen($new) > 72 || strpos($new, "\0") !== false) {
                throw new Exception('كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل وألا تتجاوز 72 بايت.');
            }
            $hash = password_hash($new, PASSWORD_DEFAULT);
            accountQuery('UPDATE users SET password = ? WHERE id = ?', 'si', [$hash, $id])->close();
        } else {
            list($name, $username) = validateAccountName($input['full_name'] ?? null, $input['username'] ?? null);
            $stmt = accountQuery('SELECT id FROM users WHERE username = ? AND id <> ?', 'si', [$username, $id]);
            $exists = $stmt->get_result()->num_rows > 0;
            $stmt->close();
            if ($exists) throw new Exception('اسم المستخدم موجود بالفعل');
            accountQuery('UPDATE users SET full_name = ?, username = ? WHERE id = ?', 'ssi', [$name, $username, $id])->close();
            if ($username !== $user['username']) {
                accountQuery('UPDATE operations SET `المحرر` = ? WHERE `المحرر` = ?', 'ss', [$username, $user['username']])->close();
            }
        }
        if (!$conn->commit()) throw new Exception('تعذر حفظ البيانات.');
    } catch (Exception $e) {
        $conn->rollback();
        if ((int)$e->getCode() === 1062) throw new Exception('اسم المستخدم موجود بالفعل');
        throw $e;
    }
    if ($action === 'update_profile') {
        $_SESSION['full_name'] = $name;
        $_SESSION['username'] = $username;
    }
    echo json_encode(['success' => true, 'message' => 'تم حفظ التغييرات بنجاح.']);
}
