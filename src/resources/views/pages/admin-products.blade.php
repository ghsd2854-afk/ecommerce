@extends('layouts.app')
@section('title', 'إدارة المنتجات')

@section('content')
<h1>إدارة المنتجات</h1>
<div id="role-warning"></div>
<div id="msg"></div>

<div class="admin-layout">
    <form class="card" id="product-form" novalidate>
        <h2 id="form-title">إضافة منتج</h2>
        <input type="hidden" id="product-id">
        <label for="name">الاسم</label>
        <input id="name" required>
        <label for="price">السعر ($)</label>
        <input id="price" type="number" step="0.01" min="0.01" required>
        <label for="category">التصنيف</label>
        <input id="category" list="categories" required>
        <datalist id="categories"></datalist>
        <label for="description">الوصف</label>
        <textarea id="description"></textarea>
        <label for="image">الصورة (jpg / png / webp، حتى 2MB)</label>
        <input id="image" type="file" accept="image/jpeg,image/png,image/webp">
        <div class="row" style="margin-top:16px">
            <button class="btn" id="save">حفظ</button>
            <button class="btn secondary hidden" type="button" id="cancel-edit">إلغاء التعديل</button>
        </div>
        <p class="muted" style="font-size:.8rem;margin-top:12px">
            المنتج الجديد مخزونه 0 في خدمة المستودع حتى تُضاف له كمية:
            <code>docker compose exec inventory node scripts/set-stock.js &lt;id&gt; &lt;qty&gt;</code>
        </p>
    </form>

    <div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>#</th><th></th><th>الاسم</th><th>التصنيف</th><th>السعر</th><th></th></tr></thead>
                <tbody id="rows"><tr><td colspan="6" class="loading">جاري التحميل...</td></tr></tbody>
            </table>
        </div>
        <div class="pager" id="pager"></div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let page = 1;
let products = [];

async function loadList(p = page) {
    page = p;
    try {
        const res = await api(`/products?page=${p}&per_page=10`);
        products = res.data;
        $('#rows').innerHTML = products.map((x) => `
            <tr>
                <td>${x.id}</td>
                <td style="width:56px"><div class="thumb" style="width:48px;border-radius:8px;font-size:1.2rem">${imageHtml(x.image_url, x.name)}</div></td>
                <td><a href="/products/${x.id}">${esc(x.name)}</a></td>
                <td><span class="tag">${esc(x.category)}</span></td>
                <td><span class="price">${money(x.price)}</span></td>
                <td style="white-space:nowrap">
                    <button class="btn secondary small" data-edit="${x.id}">تعديل</button>
                    <button class="btn danger small" data-delete="${x.id}">حذف</button>
                </td>
            </tr>`).join('') || '<tr><td colspan="6" class="empty">لا توجد منتجات.</td></tr>';
        pager('#pager', res.meta.current_page, res.meta.last_page, loadList);

        document.querySelectorAll('[data-edit]').forEach((b) => b.onclick = () => startEdit(Number(b.dataset.edit)));
        document.querySelectorAll('[data-delete]').forEach((b) => b.onclick = () => remove(Number(b.dataset.delete), b));

        const cats = await api('/products/categories');
        $('#categories').innerHTML = cats.data.map((c) => `<option value="${esc(c)}">`).join('');
    } catch (err) { showError('#msg', err); }
}

function startEdit(id) {
    const p = products.find((x) => x.id === id);
    $('#product-id').value = p.id;
    $('#name').value = p.name;
    $('#price').value = p.price;
    $('#category').value = p.category;
    $('#description').value = p.description || '';
    $('#image').value = '';
    $('#form-title').textContent = `تعديل المنتج #${p.id}`;
    $('#cancel-edit').classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function resetForm() {
    $('#product-form').reset();
    $('#product-id').value = '';
    $('#form-title').textContent = 'إضافة منتج';
    $('#cancel-edit').classList.add('hidden');
}

$('#cancel-edit').onclick = resetForm;

$('#product-form').addEventListener('submit', (e) => {
    e.preventDefault();
    busy($('#save'), async () => {
        const id = $('#product-id').value;
        const form = new FormData();
        form.append('name', $('#name').value.trim());
        form.append('price', $('#price').value);
        form.append('category', $('#category').value.trim());
        form.append('description', $('#description').value.trim());
        if ($('#image').files[0]) form.append('image', $('#image').files[0]);

        $('#msg').innerHTML = '';
        try {
            // Files need multipart/form-data, so updates use POST /products/{id} (the API accepts POST/PUT/PATCH)
            const res = await api(id ? `/products/${id}` : '/products', { method: 'POST', form });
            toast(id ? `تم تعديل المنتج #${res.data.id}` : `تمت إضافة المنتج #${res.data.id}`, 'success');
            resetForm();
            loadList(id ? page : 1);
        } catch (err) { showError('#msg', err); }
    });
});

async function remove(id, button) {
    if (!confirm(`حذف المنتج #${id}؟`)) return;
    await busy(button, async () => {
        try {
            await api(`/products/${id}`, { method: 'DELETE' });
            toast(`تم حذف المنتج #${id}`, 'success');
            loadList();
        } catch (err) { showError('#msg', err); }   // refused if the product is used in orders
    });
}

if (requireLogin()) {
    if (!Auth.isAdmin) {
        $('#role-warning').innerHTML = '<div class="alert warn">أنت لست مسؤولاً. الصفحة معروضة للتجربة فقط: أي إضافة أو تعديل أو حذف سيُرفض لأنه ليس من صلاحياتك.</div>';
    }
    loadList();
}
</script>
@endpush
