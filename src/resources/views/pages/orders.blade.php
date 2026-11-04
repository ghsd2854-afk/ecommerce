@extends('layouts.app')
@section('title', 'الطلبات')

@section('content')
<h1 id="heading">طلباتي</h1>
<div id="msg"></div>
<div id="orders"><div class="loading">جاري التحميل...</div></div>
<div class="pager" id="pager"></div>
@endsection

@push('scripts')
<script>
async function loadOrders(page = 1) {
    try {
        const res = await api('/orders?page=' + page);
        if (Auth.isAdmin) $('#heading').textContent = 'كل الطلبات (مسؤول)';
        if (!res.data.length) {
            $('#orders').innerHTML = '<div class="card empty">لا توجد طلبات بعد. <a href="/products">ابدأ التسوق</a></div>';
            return;
        }
        $('#orders').innerHTML = `
            <div class="table-wrap"><table>
                <thead><tr><th>رقم الطلب</th>${Auth.isAdmin ? '<th>المستخدم</th>' : ''}<th>المنتجات</th><th>المجموع</th><th>الحالة</th><th>التاريخ</th><th></th></tr></thead>
                <tbody>${res.data.map((o) => `
                    <tr>
                        <td>#${o.id}</td>
                        ${Auth.isAdmin ? `<td>${o.user_id}</td>` : ''}
                        <td>${(o.items || []).map((i) => `${esc(i.product_name)} × ${i.quantity}`).join('<br>')}</td>
                        <td><span class="price">${money(o.total)}</span></td>
                        <td>${statusBadge(o.status)}</td>
                        <td class="muted">${date(o.created_at)}</td>
                        <td><a class="btn secondary small" href="/orders/${o.id}">عرض</a></td>
                    </tr>`).join('')}
                </tbody>
            </table></div>`;
        pager('#pager', res.meta.current_page, res.meta.last_page, loadOrders);
    } catch (err) {
        $('#orders').innerHTML = '';
        showError('#msg', err);
    }
}

if (requireLogin()) loadOrders();
</script>
@endpush
