@extends('layouts.app')
@section('title', 'تفاصيل المنتج')

@section('content')
<div id="page" data-id="{{ $id }}"></div>
<p><a href="/products">→ العودة إلى المنتجات</a></p>
<div id="msg"></div>
<div id="product"><div class="loading">جاري التحميل...</div></div>
@endsection

@push('scripts')
<script>
const productId = Number($('#page').dataset.id);
let product = null;

function stockHtml(stock) {
    if (!stock || stock.available_quantity === null) {
        return `<div class="stock-box unknown">⚠ معلومات المخزون غير متاحة حالياً
                <div class="muted" style="font-weight:400;font-size:.85rem">خدمة المستودع لا ترد حالياً، يمكنك تصفح المنتج والمحاولة لاحقاً.</div></div>`;
    }
    if (stock.available_quantity > 0) {
        return `<div class="stock-box in">✔ متوفر: ${stock.available_quantity} قطعة
                <div class="muted" style="font-weight:400;font-size:.85rem">الكمية من خدمة المستودع (gRPC · GetStock)</div></div>`;
    }
    return `<div class="stock-box out">✖ نفدت الكمية</div>`;
}

async function loadProduct() {
    try {
        const res = await api('/products/' + productId);
        product = res.data;
        document.title = product.name + ' · متجر الإلكترونيات';
        const stock = product.stock;
        const max = stock && stock.available_quantity ? stock.available_quantity : 1000;
        $('#product').innerHTML = `
            <div class="card details">
                <div class="thumb">${imageHtml(product.image_url, product.name)}</div>
                <div>
                    <span class="tag">${esc(product.category)}</span>
                    <h1 style="margin-top:8px">${esc(product.name)}</h1>
                    <div class="price" style="font-size:1.5rem">${money(product.price)}</div>
                    <p>${esc(product.description || '')}</p>
                    ${stockHtml(stock)}
                    <label for="qty">الكمية</label>
                    <div class="row">
                        <input id="qty" class="qty" type="number" min="1" max="${max}" value="1">
                        <button class="btn" id="order-now">اطلب الآن</button>
                        <button class="btn secondary" id="add-cart">أضف إلى السلة</button>
                    </div>
                    <p class="muted" style="font-size:.85rem;margin-top:10px">
                        عند الطلب يحجز التطبيق الكمية من خدمة المستودع بطلب gRPC واحد (ReserveStock) قبل حفظ الطلب.
                    </p>
                </div>
            </div>`;
        $('#add-cart').onclick = () => {
            Cart.add(product, quantity());
            toast('أُضيف إلى السلة', 'success');
        };
        $('#order-now').onclick = () => busy($('#order-now'), orderNow);
    } catch (err) {
        $('#product').innerHTML = '';
        showError('#msg', err);
    }
}

function quantity() {
    return Math.max(1, parseInt($('#qty').value, 10) || 1);
}

async function orderNow() {
    if (!requireLogin()) return;
    $('#msg').innerHTML = '';
    try {
        const res = await api('/orders', { method: 'POST', body: { items: [{ product_id: productId, quantity: quantity() }] } });
        sessionStorage.setItem('flash', `تم إنشاء الطلب #${res.data.id} بنجاح.`);
        location.href = '/orders/' + res.data.id;
    } catch (err) {
        showError('#msg', err, { [productId]: product.name });   // not enough stock, inventory down / too slow...
        if ([409, 503, 504, 500].includes(err.status)) loadProduct();
    }
}

loadProduct();
</script>
@endpush
