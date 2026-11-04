/*
 * Shared front-end helpers.
 * The pages never touch the database: everything goes through the REST API (/api/v1)
 * with the JWT saved after login, exactly like the Postman collection.
 */
const API_BASE = '/api/v1';

/* ---------------- session (JWT) ---------------- */
const Auth = {
    get token() { return localStorage.getItem('jwt'); },
    get user() {
        try { return JSON.parse(localStorage.getItem('user') || 'null'); } catch { return null; }
    },
    save(token, user) {
        localStorage.setItem('jwt', token);
        if (user) localStorage.setItem('user', JSON.stringify(user));
    },
    setUser(user) { localStorage.setItem('user', JSON.stringify(user)); },
    clear() { localStorage.removeItem('jwt'); localStorage.removeItem('user'); },
    get loggedIn() { return !!this.token; },
    get isAdmin() { return this.user?.role === 'admin'; },
};

/* ---------------- API client ---------------- */
class ApiError extends Error {
    constructor(status, message, data) {
        super(message);
        this.status = status;
        this.data = data || {};
        this.errors = this.data.errors || null;
    }
}

/**
 * Calls the API. Options: method, body (JSON), form (FormData), auth (send the token, default true).
 * Throws ApiError with the HTTP status and the API's {"message": ...}.
 */
async function api(path, { method = 'GET', body, form, auth = true } = {}) {
    const headers = { Accept: 'application/json' };
    const options = { method, headers };

    if (body !== undefined) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
    }
    if (form) options.body = form;
    if (auth && Auth.token) headers.Authorization = `Bearer ${Auth.token}`;

    let response;
    try {
        response = await fetch(API_BASE + path, options);
    } catch {
        throw new ApiError(0, '');
    }

    let data = null;
    if (response.status !== 204) {
        data = await response.json().catch(() => null);
    }

    if (!response.ok) {
        const error = new ApiError(response.status, data?.message || `HTTP ${response.status}`, data);
        // Expired / logged-out token on a protected call: back to login.
        if (response.status === 401 && auth && Auth.token) {
            Auth.clear();
            if (location.pathname !== '/login') {
                sessionStorage.setItem('flash', 'انتهت الجلسة، الرجاء تسجيل الدخول من جديد.');
                location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search);
            }
        }
        throw error;
    }
    return data;
}

/* ---------------- UI helpers ---------------- */
const $ = (selector, root = document) => root.querySelector(selector);

function esc(value) {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function money(value) {
    return '$' + Number(value || 0).toFixed(2);
}

function date(value) {
    return value ? new Date(value).toLocaleString('ar-SY', { dateStyle: 'medium', timeStyle: 'short' }) : '';
}

const STATUS_LABELS = {
    pending: 'قيد الانتظار',
    confirmed: 'مؤكد',
    shipped: 'تم الشحن',
    delivered: 'تم التسليم',
    cancelled: 'ملغى',
};
const statusBadge = (s) => `<span class="badge ${esc(s)}">${esc(STATUS_LABELS[s] || s)}</span>`;

/* ---------------- user-friendly Arabic messages ----------------
 * The API answers in English with an HTTP status. The user only sees a clear
 * Arabic sentence (no status codes, no technical text).
 */
const FIELD_NAMES = {
    name: 'الاسم', email: 'البريد الإلكتروني', password: 'كلمة المرور', code: 'رمز التحقق',
    price: 'السعر', category: 'التصنيف', description: 'الوصف', image: 'الصورة',
    items: 'المنتجات', quantity: 'الكمية', product_id: 'المنتج', status: 'الحالة',
    search: 'البحث', sort: 'الترتيب', per_page: 'عدد العناصر بالصفحة',
};

const KNOWN_MESSAGES = {
    'Invalid email or password.': 'البريد الإلكتروني أو كلمة المرور غير صحيحة.',
    'Please verify your email first.': 'يجب تأكيد بريدك الإلكتروني أولاً.',
    'Invalid or expired code.': 'رمز التحقق غير صحيح أو انتهت صلاحيته.',
    'You do not have permission to perform this action.': 'ليس لديك صلاحية لتنفيذ هذه العملية.',
    'You are not allowed to access this order.': 'لا يمكنك عرض هذا الطلب لأنه يخص مستخدماً آخر.',
    'You are not allowed to access this notification.': 'لا يمكنك تعديل إشعار يخص مستخدماً آخر.',
    'Resource not found.': 'العنصر المطلوب غير موجود.',
    'Method not allowed.': 'هذه العملية غير مسموحة.',
    'This product is part of existing orders and cannot be deleted.': 'لا يمكن حذف هذا المنتج لأنه موجود ضمن طلبات سابقة.',
    'Too Many Attempts.': 'محاولات كثيرة جداً. الرجاء الانتظار دقيقة ثم المحاولة مجدداً.',
    'Unauthenticated.': 'انتهت الجلسة، الرجاء تسجيل الدخول من جديد.',
    'All notifications marked as read.': 'تم تعليم كل الإشعارات كمقروءة.',
    'If the account exists and is not verified, a new code was sent.': 'إذا كان الحساب موجوداً وغير مؤكد، فقد أُرسل رمز جديد إلى بريدك.',
    'If the email exists, a 6-digit reset code was sent.': 'إذا كان البريد مسجلاً لدينا، فقد أُرسل إليه رمز من 6 أرقام.',
    'Password has been reset successfully.': 'تم تغيير كلمة المرور بنجاح.',
    'Logged out successfully.': 'تم تسجيل الخروج.',
};

const STATUS_FALLBACK = {
    0: 'تعذّر الاتصال بالخادم. تأكد من تشغيل المشروع ثم حاول مجدداً.',
    401: 'الرجاء تسجيل الدخول للمتابعة.',
    403: 'ليس لديك صلاحية لتنفيذ هذه العملية.',
    404: 'العنصر المطلوب غير موجود.',
    405: 'هذه العملية غير مسموحة.',
    409: 'لا يمكن تنفيذ هذه العملية حالياً.',
    422: 'الرجاء تصحيح البيانات المدخلة.',
    429: 'محاولات كثيرة جداً. الرجاء الانتظار دقيقة ثم المحاولة مجدداً.',
    500: 'حدث خطأ غير متوقع. الرجاء المحاولة لاحقاً.',
    503: 'الخدمة غير متاحة حالياً. الرجاء المحاولة لاحقاً.',
    504: 'الخدمة لم تستجب في الوقت المحدد. الرجاء المحاولة مجدداً.',
};

const fieldName = (f) => FIELD_NAMES[f] || FIELD_NAMES[String(f).split('.').pop()] || f;

/** Translates one Laravel validation message to Arabic (falls back to a generic sentence). */
function translateValidation(message) {
    const rules = [
        [/^The (.+?) field is required\.$/, (m) => `حقل "${fieldName(m[1])}" مطلوب.`],
        [/^The (.+?) field must be at least (\d+) characters\.$/, (m) => `${fieldName(m[1])} يجب ألا يقل عن ${m[2]} أحرف.`],
        [/^The (.+?) field must contain at least one letter\.$/, (m) => `${fieldName(m[1])} يجب أن يحتوي على حرف واحد على الأقل.`],
        [/^The (.+?) field must contain at least one number\.$/, (m) => `${fieldName(m[1])} يجب أن يحتوي على رقم واحد على الأقل.`],
        [/^The (.+?) has already been taken\.$/, (m) => `${fieldName(m[1])} مستخدم مسبقاً.`],
        [/^The (.+?) field must be a valid email address\.$/, () => 'البريد الإلكتروني غير صالح.'],
        [/^The (.+?) field must be (\d+) digits\.$/, (m) => `${fieldName(m[1])} يجب أن يتكون من ${m[2]} أرقام.`],
        [/^The (.+?) field must be at least ([\d.]+)\.$/, (m) => `${fieldName(m[1])} يجب ألا يقل عن ${m[2]}.`],
        [/^The (.+?) field must not be greater than (\d+) kilobytes\.$/, (m) => `حجم ${fieldName(m[1])} يجب ألا يتجاوز ${Math.round(m[2] / 1024)} ميغابايت.`],
        [/^The (.+?) field must not be greater than ([\d.]+)\.$/, (m) => `${fieldName(m[1])} يجب ألا يزيد عن ${m[2]}.`],
        [/^The (.+?) field must not be greater than (\d+) characters\.$/, (m) => `${fieldName(m[1])} طويل جداً.`],
        [/^The (.+?) field must be an image\.$/, (m) => `${fieldName(m[1])} يجب أن تكون صورة.`],
        [/^The (.+?) field must be a file of type: (.+)\.$/, (m) => `${fieldName(m[1])} يجب أن تكون من نوع: ${m[2]}.`],
        [/^The (.+?) field must be a number\.$/, (m) => `${fieldName(m[1])} يجب أن يكون رقماً.`],
        [/^The (.+?) field must be an integer\.$/, (m) => `${fieldName(m[1])} يجب أن يكون عدداً صحيحاً.`],
        [/^The (.+?) field has a duplicate value\.$/, () => 'لا يمكن تكرار نفس المنتج في الطلب.'],
        [/^The selected (.+?) is invalid\.$/, (m) => `قيمة "${fieldName(m[1])}" غير صالحة.`],
        [/^The (.+?) field must have at least (\d+) items?\.$/, () => 'يجب اختيار منتج واحد على الأقل.'],
    ];
    for (const [re, fn] of rules) {
        const m = message.match(re);
        if (m) return fn(m);
    }
    return null;
}

/** Arabic text for a success / info message coming from the API. */
function translateMessage(message) {
    return KNOWN_MESSAGES[message] || message;
}

/** Arabic text of a notification stored by the API. */
function translateNotification(message) {
    let m;
    if ((m = message.match(/^Your order #(\d+) was placed\.$/))) return `تم إنشاء طلبك رقم ${m[1]} بنجاح.`;
    if ((m = message.match(/^Your order #(\d+) is now (\w+)\.$/))) return `تغيّرت حالة طلبك رقم ${m[1]} إلى: ${STATUS_LABELS[m[2]] || m[2]}.`;
    if ((m = message.match(/^New order #(\d+) from (.+)\.$/))) return `طلب جديد رقم ${m[1]} من ${m[2]}.`;
    return message;
}

/**
 * Clear Arabic message for an API error.
 * names: optional {productId: productName} used for "not enough stock" messages.
 */
function friendlyError(error, names = {}) {
    const msg = error.message || '';

    if (error.status === 409 && Array.isArray(error.data.shortages) && error.data.shortages.length) {
        const parts = error.data.shortages.map((s) =>
            `"${names[s.product_id] || 'منتج رقم ' + s.product_id}" (المطلوب ${s.requested}، المتوفر ${s.available})`);
        return { text: `الكمية غير كافية لـ: ${parts.join('، ')}. لم يتم إنشاء الطلب ولم يُحجز أي منتج.` };
    }
    if (msg.startsWith('The inventory service did not respond')) {
        return { text: 'خدمة المستودع لم تستجب في الوقت المحدد، فلم يتم إنشاء الطلب. الرجاء المحاولة مجدداً.' };
    }
    if (msg.startsWith('The inventory service is currently unavailable')) {
        return { text: 'خدمة المستودع غير متاحة حالياً، فلم يتم إنشاء الطلب. الرجاء المحاولة بعد قليل.' };
    }
    if (msg.startsWith('The order could not be saved')) {
        return { text: msg.includes('was returned')
            ? 'تعذّر حفظ الطلب، وأُعيدت الكميات المحجوزة إلى المستودع. الرجاء المحاولة مجدداً.'
            : 'تعذّر حفظ الطلب. الرجاء المحاولة مجدداً.' };
    }
    if (error.status === 422 && error.errors) {
        const list = Object.values(error.errors).flat().map((m) => translateValidation(m) || 'إحدى القيم المدخلة غير صحيحة.');
        return { text: 'الرجاء تصحيح ما يلي:', list: [...new Set(list)] };
    }
    if (KNOWN_MESSAGES[msg]) return { text: KNOWN_MESSAGES[msg] };
    return { text: STATUS_FALLBACK[error.status] || STATUS_FALLBACK[500] };
}

/** HTML of an error box (Arabic text only). */
function errorHtml(error, names = {}) {
    const { text, list } = friendlyError(error, names);
    const items = list ? '<ul>' + list.map((m) => `<li>${esc(m)}</li>`).join('') + '</ul>' : '';
    return `<div class="alert error">${esc(text)}${items}</div>`;
}

function showError(target, error, names = {}) {
    const el = typeof target === 'string' ? $(target) : target;
    if (el) el.innerHTML = errorHtml(error, names);
    else toast(friendlyError(error, names).text, 'error');
}

function showMessage(target, message, type = 'success') {
    const el = typeof target === 'string' ? $(target) : target;
    el.innerHTML = `<div class="alert ${type}">${esc(translateMessage(message))}</div>`;
}

function toast(message, type = '') {
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    el.textContent = message;
    $('#toasts').appendChild(el);
    setTimeout(() => el.remove(), 4500);
}

/** Disable a button while an async action runs. */
async function busy(button, fn) {
    button.disabled = true;
    try { return await fn(); } finally { button.disabled = false; }
}

function requireLogin() {
    if (!Auth.loggedIn) {
        sessionStorage.setItem('flash', 'الرجاء تسجيل الدخول أولاً.');
        location.href = '/login?next=' + encodeURIComponent(location.pathname + location.search);
        return false;
    }
    return true;
}

function pager(container, current, last, onPage) {
    const el = typeof container === 'string' ? $(container) : container;
    el.innerHTML = '';
    if (!last || last <= 1) return;
    for (let p = 1; p <= last; p++) {
        const b = document.createElement('button');
        b.className = 'btn secondary small' + (p === current ? ' current' : '');
        b.textContent = p;
        b.onclick = () => onPage(p);
        el.appendChild(b);
    }
}

function imageHtml(url, alt) {
    return url ? `<img src="${esc(url)}" alt="${esc(alt)}">` : '📦';
}

/* ---------------- cart (kept in the browser until the order is sent) ---------------- */
const Cart = {
    items() {
        try { return JSON.parse(localStorage.getItem('cart') || '[]'); } catch { return []; }
    },
    save(items) { localStorage.setItem('cart', JSON.stringify(items)); renderNav(); },
    add(product, quantity) {
        const items = this.items();
        const found = items.find((i) => i.product_id === product.id);
        if (found) found.quantity += quantity;
        else items.push({ product_id: product.id, name: product.name, price: product.price, quantity });
        this.save(items);
    },
    count() { return this.items().reduce((n, i) => n + i.quantity, 0); },
    clear() { this.save([]); },
};

/* ---------------- navigation bar ---------------- */
let unreadCount = 0;

function renderNav() {
    const nav = $('#nav');
    if (!nav) return;
    const path = location.pathname;
    const link = (href, label, extra = '') =>
        `<a href="${href}" class="${path === href || (href !== '/products' && path.startsWith(href)) ? 'active' : ''}">${label}${extra}</a>`;
    const cartCount = Cart.count();

    let html = link('/products', 'المنتجات');
    html += link('/cart', 'السلة', cartCount ? `<span class="count">${cartCount}</span>` : '');

    if (Auth.loggedIn) {
        const user = Auth.user || {};
        html += link('/orders', Auth.isAdmin ? 'كل الطلبات' : 'طلباتي');
        html += link('/notifications', 'الإشعارات', unreadCount ? `<span class="count">${unreadCount}</span>` : '');
        if (Auth.isAdmin) {
            html += '<span class="sep"></span>';
            html += link('/admin/products', 'إدارة المنتجات');
            html += link('/admin/orders', 'إدارة الطلبات');
        }
        html += '<span class="sep"></span>';
        html += `<span class="who">${esc(user.name || '')} · ${user.role === 'admin' ? 'مسؤول' : 'زبون'}</span>`;
        html += '<button class="linklike" id="logout-btn">تسجيل الخروج</button>';
    } else {
        html += '<span class="sep"></span>';
        html += link('/login', 'تسجيل الدخول');
        html += link('/register', 'حساب جديد');
    }
    nav.innerHTML = html;

    const logout = $('#logout-btn');
    if (logout) {
        logout.onclick = async () => {
            try { await api('/auth/logout', { method: 'POST' }); } catch { /* token may already be invalid */ }
            Auth.clear();
            sessionStorage.setItem('flash', 'تم تسجيل الخروج.');
            location.href = '/login';
        };
    }
}

async function refreshSession() {
    if (!Auth.loggedIn) return;
    try {
        // The user is saved at login, so only the unread count is fetched on each page
        // (it also checks that the token is still valid).
        const [unread, me] = await Promise.all([
            api('/notifications?unread=1&per_page=1'),
            Auth.user ? Promise.resolve(Auth.user) : api('/auth/me'),
        ]);
        Auth.setUser(me);
        unreadCount = unread.total || 0;
    } catch { /* handled by api() */ }
    renderNav();
}

document.addEventListener('DOMContentLoaded', () => {
    renderNav();
    refreshSession();
    const flash = sessionStorage.getItem('flash');
    if (flash) {
        sessionStorage.removeItem('flash');
        showMessage('#flash', flash, 'warn');
    }
});
