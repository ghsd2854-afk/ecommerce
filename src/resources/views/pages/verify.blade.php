@extends('layouts.app')
@section('title', 'تأكيد البريد')

@section('content')
<div class="card narrow">
    <h1>تأكيد البريد الإلكتروني</h1>
    <p class="muted">أدخل الرمز المكوّن من 6 أرقام المرسل إلى بريدك (صالح 10 دقائق).</p>
    <div id="msg"></div>
    <form id="verify-form" novalidate>
        <label for="email">البريد الإلكتروني</label>
        <input id="email" type="email" required>
        <label for="code">رمز التحقق</label>
        <input id="code" inputmode="numeric" maxlength="6" placeholder="123456" style="letter-spacing:6px;text-align:center;direction:ltr" required>
        <button class="btn block" id="submit">تأكيد</button>
    </form>
    <button class="btn secondary block" id="resend">إعادة إرسال الرمز</button>
</div>
@endsection

@push('scripts')
<script>
$('#email').value = new URLSearchParams(location.search).get('email') || '';

$('#verify-form').addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('#submit'), async () => {
        try {
            const res = await api('/auth/verify-email', {
                method: 'POST', auth: false,
                body: { email: $('#email').value.trim(), code: $('#code').value.trim() },
            });
            Auth.save(res.access_token, res.user);
            sessionStorage.setItem('flash', 'تم تأكيد بريدك وتسجيل دخولك.');
            location.href = '/products';
        } catch (err) {
            showError('#msg', err);
        }
    });
});

$('#resend').addEventListener('click', () => busy($('#resend'), async () => {
    try {
        const res = await api('/auth/resend-otp', { method: 'POST', auth: false, body: { email: $('#email').value.trim() } });
        showMessage('#msg', res.message);
    } catch (err) {
        showError('#msg', err);
    }
}));
</script>
@endpush
