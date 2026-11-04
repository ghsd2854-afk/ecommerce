@extends('layouts.app')
@section('title', 'تسجيل الدخول')

@section('content')
<div class="card narrow">
    <h1>تسجيل الدخول</h1>
    <div id="msg"></div>
    <form id="login-form" novalidate>
        <label for="email">البريد الإلكتروني</label>
        <input id="email" type="email" autocomplete="username" required>
        <label for="password">كلمة المرور</label>
        <input id="password" type="password" autocomplete="current-password" required>
        <button class="btn block" id="submit">دخول</button>
    </form>
    <p class="muted" style="margin-top:16px">
        <a href="/forgot-password">نسيت كلمة المرور؟</a> · <a href="/register">إنشاء حساب جديد</a>
    </p>
    <details class="muted" style="margin-top:8px">
        <summary>حسابات تجريبية</summary>
        <div style="direction:ltr;text-align:left;font-size:.85rem">
            admin@shop.test / Admin12345<br>
            customer@shop.test / Customer12345<br>
            customer2@shop.test / Customer12345
        </div>
    </details>
</div>
@endsection

@push('scripts')
<script>
const params = new URLSearchParams(location.search);
$('#email').value = params.get('email') || '';

$('#login-form').addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('#submit'), async () => {
        try {
            const res = await api('/auth/login', {
                method: 'POST', auth: false,
                body: { email: $('#email').value.trim(), password: $('#password').value },
            });
            Auth.save(res.access_token, res.user);
            location.href = params.get('next') || '/products';
        } catch (err) {
            if (err.status === 403) {
                // Account exists but the email is not verified yet
                $('#msg').innerHTML = errorHtml(err) +
                    `<div class="alert warn">أكّد بريدك أولاً: <a href="/verify-email?email=${encodeURIComponent($('#email').value.trim())}">إدخال رمز التحقق</a></div>`;
            } else {
                showError('#msg', err);
            }
        }
    });
});
</script>
@endpush
