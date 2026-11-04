@extends('layouts.app')
@section('title', 'المنتجات')

@section('content')
<div class="row" style="margin-bottom:12px">
    <h1 style="margin:0">المنتجات</h1>
    <span class="spacer"></span>
    <span class="muted" id="total"></span>
</div>

<form class="filters card" id="filters">
    <div>
        <label for="search">بحث بالاسم</label>
        <input id="search" placeholder="مثلاً: mouse">
    </div>
    <div>
        <label for="category">التصنيف</label>
        <select id="category"><option value="">كل التصنيفات</option></select>
    </div>
    <div>
        <label for="sort">الترتيب</label>
        <select id="sort">
            <option value="newest">الأحدث</option>
            <option value="price_asc">السعر: من الأقل</option>
            <option value="price_desc">السعر: من الأعلى</option>
            <option value="name">الاسم</option>
        </select>
    </div>
    <div>
        <label for="per_page">بالصفحة</label>
        <select id="per_page">
            <option>4</option><option>8</option><option selected>12</option><option>24</option>
        </select>
    </div>
    <button class="btn">تطبيق</button>
</form>

<div id="msg"></div>
<div class="grid" id="grid"><div class="loading">جاري التحميل...</div></div>
<div class="pager" id="pager"></div>
@endsection

@push('scripts')
<script>
let currentPage = 1;

async function loadCategories() {
    try {
        const res = await api('/products/categories');
        $('#category').innerHTML += res.data.map((c) => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
    } catch (err) { showError('#msg', err); }
}

async function loadProducts(page = 1) {
    currentPage = page;
    const q = new URLSearchParams({ page, per_page: $('#per_page').value, sort: $('#sort').value });
    if ($('#search').value.trim()) q.set('search', $('#search').value.trim());
    if ($('#category').value) q.set('category', $('#category').value);

    $('#msg').innerHTML = '';
    try {
        const res = await api('/products?' + q.toString());
        $('#total').textContent = `${res.meta.total} منتج · صفحة ${res.meta.current_page} من ${res.meta.last_page}`;
        $('#grid').innerHTML = res.data.length ? res.data.map((p) => `
            <a class="product-card" href="/products/${p.id}">
                <div class="thumb">${imageHtml(p.image_url, p.name)}</div>
                <div class="body">
                    <span class="name">${esc(p.name)}</span>
                    <span><span class="tag">${esc(p.category)}</span></span>
                    <span class="muted" style="font-size:.9rem">${esc(p.description || '')}</span>
                    <span class="spacer"></span>
                    <span class="price">${money(p.price)}</span>
                </div>
            </a>`).join('') : '<div class="empty">لا توجد منتجات مطابقة.</div>';
        pager('#pager', res.meta.current_page, res.meta.last_page, loadProducts);
    } catch (err) {
        $('#grid').innerHTML = '';
        showError('#msg', err);
    }
}

$('#filters').addEventListener('submit', (e) => { e.preventDefault(); loadProducts(1); });
['category', 'sort', 'per_page'].forEach((id) => $('#' + id).addEventListener('change', () => loadProducts(1)));

loadCategories();
loadProducts();
</script>
@endpush
