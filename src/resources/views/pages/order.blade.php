@extends('layouts.app')
@section('title', 'تفاصيل الطلب')

@section('content')
<div id="page" data-id="{{ $id }}"></div>
<p><a href="/orders">→ العودة إلى الطلبات</a></p>
<div id="msg"></div>
<div id="order"><div class="loading">جاري التحميل...</div></div>
@endsection

@push('scripts')
<script>
const orderId = Number($('#page').dataset.id);

async function loadOrder() {
    try {
        const res = await api('/orders/' + orderId);
        const o = res.data;
        $('#order').innerHTML = `
            <div class="card">
                <div class="row">
                    <h1 style="margin:0">الطلب #${o.id}</h1>
                    ${statusBadge(o.status)}
                    <span class="spacer"></span>
                    <span class="muted">${date(o.created_at)}</span>
                </div>
            </div>
            <div class="table-wrap" style="margin-top:16px"><table>
                <thead><tr><th>المنتج</th><th>سعر الوحدة</th><th>الكمية</th><th>المجموع</th></tr></thead>
                <tbody>${o.items.map((i) => `
                    <tr>
                        <td><a href="/products/${i.product_id}">${esc(i.product_name)}</a></td>
                        <td><span class="price">${money(i.unit_price)}</span></td>
                        <td>${i.quantity}</td>
                        <td><span class="price">${money(Number(i.unit_price) * i.quantity)}</span></td>
                    </tr>`).join('')}
                </tbody>
            </table></div>
            <div class="card row" style="margin-top:16px">
                <strong>المجموع الكلي</strong><span class="spacer"></span>
                <span class="price" style="font-size:1.3rem">${money(o.total)}</span>
            </div>`;
    } catch (err) {
        $('#order').innerHTML = '';
        showError('#msg', err);
    }
}

if (requireLogin()) loadOrder();
</script>
@endpush
