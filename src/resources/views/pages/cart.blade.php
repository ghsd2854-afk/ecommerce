@extends('layouts.app')
@section('title', 'السلة')

@section('content')
<h1>السلة</h1>
<div id="msg"></div>
<div id="cart"></div>
@endsection

@push('scripts')
<script>
function renderCart() {
    const items = Cart.items();
    if (!items.length) {
        $('#cart').innerHTML = '<div class="card empty">السلة فارغة. <a href="/products">تصفّح المنتجات</a></div>';
        return;
    }
    const total = items.reduce((sum, i) => sum + Number(i.price) * i.quantity, 0);
    $('#cart').innerHTML = `
        <div class="table-wrap">
            <table>
                <thead><tr><th>المنتج</th><th>السعر</th><th>الكمية</th><th>المجموع</th><th></th></tr></thead>
                <tbody>
                ${items.map((i, idx) => `
                    <tr>
                        <td><a href="/products/${i.product_id}">${esc(i.name)}</a></td>
                        <td><span class="price">${money(i.price)}</span></td>
                        <td><input type="number" min="1" max="1000" value="${i.quantity}" class="qty" data-idx="${idx}"></td>
                        <td><span class="price">${money(Number(i.price) * i.quantity)}</span></td>
                        <td><button class="btn secondary small" data-remove="${idx}">حذف</button></td>
                    </tr>`).join('')}
                </tbody>
            </table>
        </div>
        <div class="card row" style="margin-top:16px">
            <div>
                <div class="muted">المجموع التقريبي (يحسبه الخادم نهائياً)</div>
                <div class="price" style="font-size:1.4rem">${money(total)}</div>
            </div>
            <span class="spacer"></span>
            <button class="btn secondary" id="clear">تفريغ السلة</button>
            <button class="btn" id="checkout">تأكيد الطلب</button>
        </div>
        <p class="muted" style="font-size:.85rem">
            كل منتجات السلة تُحجز من المستودع بطلب gRPC واحد: إما أن تُحجز كلها أو لا يُنشأ الطلب.
        </p>`;

    document.querySelectorAll('input[data-idx]').forEach((input) => input.addEventListener('change', () => {
        const all = Cart.items();
        all[input.dataset.idx].quantity = Math.max(1, parseInt(input.value, 10) || 1);
        Cart.save(all);
        renderCart();
    }));
    document.querySelectorAll('[data-remove]').forEach((b) => b.addEventListener('click', () => {
        const all = Cart.items();
        all.splice(Number(b.dataset.remove), 1);
        Cart.save(all);
        renderCart();
    }));
    $('#clear').onclick = () => { Cart.clear(); renderCart(); };
    $('#checkout').onclick = () => busy($('#checkout'), checkout);
}

async function checkout() {
    if (!requireLogin()) return;
    $('#msg').innerHTML = '';
    const items = Cart.items().map((i) => ({ product_id: i.product_id, quantity: i.quantity }));
    try {
        const res = await api('/orders', { method: 'POST', body: { items } });
        Cart.clear();
        sessionStorage.setItem('flash', `تم إنشاء الطلب #${res.data.id} وحجز كل الكميات بنجاح.`);
        location.href = '/orders/' + res.data.id;
    } catch (err) {
        const names = Object.fromEntries(Cart.items().map((i) => [i.product_id, i.name]));
        showError('#msg', err, names);
        if (err.status === 409 && err.data.details && err.data.details.shortages) {
            $('#msg').innerHTML += '<div class="alert warn">عدّل الكميات في السلة ثم أعد المحاولة.</div>';
        }
    }
}

renderCart();
</script>
@endpush
