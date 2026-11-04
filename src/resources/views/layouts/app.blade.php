<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'المتجر') · متجر الإلكترونيات</title>
    <link rel="stylesheet" href="/css/shop.css">
</head>
<body data-page="@yield('page')">
    <header class="topbar">
        <div class="container topbar-inner">
            <a href="/products" class="brand">🛒 متجر الإلكترونيات</a>
            <nav id="nav" class="nav"></nav>
        </div>
    </header>

    <main class="container">
        <div id="flash"></div>
        @yield('content')
    </main>

    <footer class="footer">
        <div class="container">
            الواجهة تتعامل مع البيانات فقط عبر الواجهة البرمجية <code>/api/v1</code> (JWT) · المخزون من خدمة المستودع عبر gRPC
        </div>
    </footer>

    <div id="toasts" class="toasts"></div>

    <script src="/js/shop.js"></script>
    @stack('scripts')
</body>
</html>
