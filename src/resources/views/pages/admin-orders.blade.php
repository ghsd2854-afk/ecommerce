@extends('layouts.app')
@section('title', 'إدارة الطلبات')

@section('content')
<h1>إدارة الطلبات</h1>
<div id="role-warning"></div>
<div id="msg"></div>
<div id="orders"><div class="loading">جاري التحميل...</div></div>
<div class="pager" id="pager"></div>
@endsection

@push('scripts')
<script>
const STATUSES = ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'];
let page = 1;

async function loadOrders(p = page) {
    page = p;
    try {
        const res = await api('/orders?page=' + p);
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

if (requireLogin()) {
    if (!Auth.isAdmin) {
        $('#role-warning').innerHTML = '<div class="alert warn">أنت لست مسؤولاً: تظهر طلباتك فقط، وأي تغيير للحالة سيُرفض لأنه ليس من صلاحياتك.</div>';
    }
    loadOrders();
}
</script>
@endpush
