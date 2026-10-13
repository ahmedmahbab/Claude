'use strict';
let csrfToken = '';
async function accountRequest(action, params = {}) {
    const response = await fetch('api.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action, ...params, csrf_token: csrfToken})
    });
    const result = await response.json();
    if (result.auth_required) {
        window.location.replace('index.html');
        throw new Error('انتهت الجلسة. يرجى تسجيل الدخول مجدداً.');
    }
    if (!response.ok || !result.success) throw new Error(result.message || 'تعذر حفظ التغييرات.');
    return result;
}
function showMessage(id, message, success = false) {
    const box = document.getElementById(id);
    box.textContent = message;
    box.className = 'message ' + (success ? 'success' : 'error');
    box.hidden = false;
}
async function submitAccount(event, action, messageId) {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button');
    const values = Object.fromEntries(new FormData(form));
    if (action === 'change_password' && values.new_password !== values.confirm_password) {
        showMessage(messageId, 'تأكيد كلمة المرور غير مطابق.');
        document.getElementById('confirmPassword').focus();
        return;
    }
    button.disabled = true;
    document.getElementById(messageId).hidden = true;
    try {
        const result = await accountRequest(action, values);
        if (action === 'change_password') form.reset();
        else {
            document.getElementById('fullName').value = values.full_name.trim().replace(/\s+/gu, ' ');
            document.getElementById('accountUsername').value = values.username.trim();
        }
        showMessage(messageId, result.message, true);
    } catch (error) {
        showMessage(messageId, error instanceof TypeError ? 'تعذر الاتصال بالخادم. يرجى المحاولة مجدداً.' : error.message);
    } finally {
        button.disabled = false;
    }
}
document.getElementById('profileForm').addEventListener('submit', event => submitAccount(event, 'update_profile', 'profileMessage'));
document.getElementById('passwordForm').addEventListener('submit', event => submitAccount(event, 'change_password', 'passwordMessage'));
(async () => {
    try {
        const result = await accountRequest('get_profile');
        csrfToken = result.csrf_token;
        document.getElementById('fullName').value = result.user.full_name;
        document.getElementById('accountUsername').value = result.user.username;
        document.getElementById('account').hidden = false;
        document.getElementById('loading').hidden = true;
    } catch (error) {
        document.getElementById('loading').textContent = 'تعذر تحميل الحساب. حدّث الصفحة للمحاولة مجدداً. ' + error.message;
    }
})();
