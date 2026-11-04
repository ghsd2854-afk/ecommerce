@extends('layouts.app')
@section('title', 'نسيت كلمة المرور')

@section('content')
<div class="card narrow">
    <h1>استعادة كلمة المرور</h1>
    <div id="msg"></div>

    <form id="step1" novalidate>
        <p class="muted">1) أدخل بريدك وسنرسل لك رمزاً من 6 أرقام.</p>
        <label for="email">البريد الإلكتروني</label>
        <input id="email" type="email" required>
        <button class="btn block" id="send">إرسال الرمز</button>
    </form>

    <form id="step2" class="hidden" novalidate>
        <p class="muted">2) أدخل الرمز وكلمة المرور الجديدة.</p>
        <label for="code">رمز التحقق</label>
        <input id="code" inputmode="numeric" maxlength="6" style="letter-spacing:6px;text-align:center;direction:ltr" required>
        <label for="password">كلمة المرور الجديدة</label>
        <input id="password" type="password" autocomplete="new-password" required>
        <button class="btn block" id="reset">تغيير كلمة المرور</button>
    </form>
</div>
@endsection

@push('scripts')
<script>
$('#step1').addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('#send'), async () => {
        try {
            const res = await api('/auth/forgot-password', { method: 'POST', auth: false, body: { email: $('#email').value.trim() } });
            showMessage('#msg', res.message);
            $('#step2').classList.remove('hidden');
        } catch (err) {
            showError('#msg', err);
        }
    });
});

$('#step2').addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('#reset'), async () => {
        try {
            const email = $('#email').value.trim();
            await api('/auth/reset-password', {
                method: 'POST', auth: false,
                body: { email, code: $('#code').value.trim(), password: $('#password').value },
            });
            sessionStorage.setItem('flash', 'تم تغيير كلمة المرور. سجّل الدخول بكلمة المرور الجديدة.');
            location.href = '/login?email=' + encodeURIComponent(email);
        } catch (err) {
            showError('#msg', err);
        }
    });
});
</script>
@endpush
