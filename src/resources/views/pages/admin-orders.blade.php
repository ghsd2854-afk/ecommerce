@extends('layouts.app')
@section('title', 'إدارة الطلبات')

@section('content')
<h1>إدارة الطلبات</h1>
<div id="role-warning"></div>
<div id="msg"></div>
<div class="card" id="report" style="display:none;margin-bottom:16px"></div>
<div style="margin-bottom:12px">
    <label>تصفية حسب الحالة:
        <select id="status-filter" style="min-width:160px"><option value="">الكل</option></select>
    </label>
</div>
<div id="orders"><div class="loading">جاري التحميل...</div></div>
<div class="pager" id="pager"></div>
@endsection

@push('scripts')
<script>
const STATUSES = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];
let page = 1;
let statusFilter = '';

// Sales report of the last 30 days (admin only, cached on the server for a short time).
async function loadReport() {
    try {
        const r = (await api('/orders/stats?days=30')).data;
        $('#report').style.display = '';
        $('#report').innerHTML = `<strong>آخر 30 يوماً:</strong> ${r.orders.toLocaleString('ar-SY')} طلب،
            إيرادات <span class="price">${money(r.revenue)}</span>
            <span class="muted">(بدون الطلبات الملغاة)</span>`;
    } catch { /* the report is optional */ }
}

async function loadOrders(p = page) {
    page = p;
    try {
        const res = await api('/orders?page=' + p + (statusFilter ? '&status=' + statusFilter : ''));
        if (!res.data.length) {
            $('#orders').innerHTML = '<div class="card empty">لا توجد طلبات.</div>';
            return;
        }
        $('#orders').innerHTML = `
            <div class="table-wrap"><table>
                <thead><tr><th>#</th><th>المستخدم</th><th>المنتجات</th><th>المجموع</th><th>الحالة الحالية</th><th>تغيير الحالة</th><th></th></tr></thead>
                <tbody>${res.data.map((o) => `
                    <tr>
                        <td><a href="/orders/${o.id}">#${o.id}</a></td>
                        <td>${o.user_id}</td>
                        <td>${(o.items || []).map((i) => `${esc(i.product_name)} × ${i.quantity}`).join('<br>')}</td>
                        <td><span class="price">${money(o.total)}</span></td>
                        <td>${statusBadge(o.status)}</td>
                        <td>
                            <select data-status="${o.id}" style="min-width:140px">
                                ${STATUSES.map((s) => `<option value="${s}" ${s === o.status ? 'selected' : ''}>${STATUS_LABELS[s]}</option>`).join('')}
                            </select>
                        </td>
                        <td><button class="btn small" data-save="${o.id}">حفظ</button></td>
                    </tr>`).join('')}
                </tbody>
            </table></div>`;
        pager('#pager', res.meta.current_page, res.meta.last_page, loadOrders);

        document.querySelectorAll('[data-save]').forEach((b) => b.onclick = () => busy(b, async () => {
            const id = b.dataset.save;
            const status = document.querySelector(`[data-status="${id}"]`).value;
            $('#msg').innerHTML = '';
            try {
                await api(`/orders/${id}/status`, { method: 'PATCH', body: { status } });
                toast(`الطلب #${id}: ${STATUS_LABELS[status]} (وصل إشعار للزبون)`, 'success');
                loadOrders();
            } catch (err) { showError('#msg', err); }   // refused for customers
        }));
    } catch (err) {
        $('#orders').innerHTML = '';
        showError('#msg', err);
    }
}

$('#status-filter').innerHTML += STATUSES.map((s) => `<option value="${s}">${STATUS_LABELS[s]}</option>`).join('');
$('#status-filter').onchange = (e) => { statusFilter = e.target.value; loadOrders(1); };

if (requireLogin()) {
    if (Auth.isAdmin) loadReport();
    if (!Auth.isAdmin) {
        $('#role-warning').innerHTML = '<div class="alert warn">أنت لست مسؤولاً: تظهر طلباتك فقط، وأي تغيير للحالة سيُرفض لأنه ليس من صلاحياتك.</div>';
    }
    loadOrders();
}
</script>
@endpush
