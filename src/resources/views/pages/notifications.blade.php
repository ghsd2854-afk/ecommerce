@extends('layouts.app')
@section('title', 'الإشعارات')

@section('content')
<div class="row" style="margin-bottom:16px">
    <h1 style="margin:0">الإشعارات</h1>
    <span class="spacer"></span>
    <label class="row" style="margin:0;font-weight:400"><input type="checkbox" id="only-unread" style="width:auto"> غير المقروءة فقط</label>
    <button class="btn secondary small" id="read-all">تعليم الكل كمقروء</button>
</div>
<div id="msg"></div>
<div class="card" style="padding:0" id="list"><div class="loading" style="padding:20px">جاري التحميل...</div></div>
<div class="pager" id="pager"></div>
@endsection

@push('scripts')
<script>
async function loadNotifications(page = 1) {
    const q = new URLSearchParams({ page });
    if ($('#only-unread').checked) q.set('unread', 1);
    try {
        const res = await api('/notifications?' + q.toString());
        $('#list').innerHTML = res.data.length ? res.data.map((n) => `
            <div class="notif ${n.read_at ? 'read' : 'unread'}">
                <span class="dot"></span>
                <div style="flex:1">
                    <div>${esc(translateNotification(n.message))}</div>
                    <div class="muted" style="font-size:.8rem">${date(n.created_at)}</div>
                </div>
                ${n.read_at ? '' : `<button class="btn secondary small" data-read="${n.id}">تعليم كمقروء</button>`}
            </div>`).join('') : '<div class="empty">لا توجد إشعارات.</div>';
        document.querySelectorAll('[data-read]').forEach((b) => b.addEventListener('click', () => busy(b, async () => {
            try {
                await api(`/notifications/${b.dataset.read}/read`, { method: 'PATCH' });
                await loadNotifications(page);
                refreshSession();
            } catch (err) { showError('#msg', err); }
        })));
        pager('#pager', res.current_page, res.last_page, loadNotifications);
    } catch (err) {
        $('#list').innerHTML = '';
        showError('#msg', err);
    }
}

$('#read-all').onclick = () => busy($('#read-all'), async () => {
    try {
        const res = await api('/notifications/read-all', { method: 'POST' });
        toast(translateMessage(res.message), 'success');
        await loadNotifications();
        refreshSession();
    } catch (err) { showError('#msg', err); }
});
$('#only-unread').onchange = () => loadNotifications(1);

if (requireLogin()) loadNotifications();
</script>
@endpush
