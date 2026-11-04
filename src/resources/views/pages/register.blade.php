@extends('layouts.app')
@section('title', 'حساب جديد')

@section('content')
<div class="card narrow">
    <h1>إنشاء حساب جديد</h1>
    <div id="msg"></div>
    <form id="register-form" novalidate>
        <label for="name">الاسم</label>
        <input id="name" required>
        <label for="email">البريد الإلكتروني</label>
        <input id="email" type="email" required>
        <label for="password">كلمة المرور</label>
        <input id="password" type="password" autocomplete="new-password" required>
        <div class="muted" style="font-size:.85rem">8 أحرف على الأقل، تحتوي حروفاً وأرقاماً.</div>
        <button class="btn block" id="submit">إنشاء الحساب</button>
    </form>
    <p class="muted" style="margin-top:16px">لديك حساب؟ <a href="/login">تسجيل الدخول</a></p>
</div>
@endsection

@push('scripts')
<script>
$('#register-form').addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('#submit'), async () => {
        const email = $('#email').value.trim();
        try {
            await api('/auth/register', {
                method: 'POST', auth: false,
                body: { name: $('#name').value.trim(), email, password: $('#password').value },
            });
            sessionStorage.setItem('flash', 'تم إنشاء الحساب. أرسلنا رمزاً من 6 أرقام إلى بريدك.');
            location.href = '/verify-email?email=' + encodeURIComponent(email);
        } catch (err) {
            showError('#msg', err);
        }
    });
});
</script>
@endpush
