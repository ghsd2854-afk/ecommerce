import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    vus: 5,
    duration: '30s',
};

const BASE = __ENV.BASE_URL || 'http://host.docker.internal:8000/api/v1';
const TOKEN = __ENV.AUTH_TOKEN || '';

const headers = TOKEN
    ? { Authorization: `Bearer ${TOKEN}`, Accept: 'application/json' }
    : { Accept: 'application/json' };

export default function () {

    // 1. Products List
    const products = http.get(`${BASE}/products`, { headers });

    check(products, {
        'Products: HTTP 200': r => r.status === 200,
        'Products: ETag exists': r => !!r.headers['Etag'] || !!r.headers['ETag'],
    });

    // 2. Conditional ETag Request
    const etag = products.headers['Etag'] || products.headers['ETag'];

    if (etag) {
        const conditional = http.get(`${BASE}/products`, {
            headers: {
                ...headers,
                'If-None-Match': etag,
            },
        });

        check(conditional, {
            'ETag: HTTP 304 or 200': r => r.status === 304 || r.status === 200,
        });
    }

    // 3. Product Categories
    const categories = http.get(`${BASE}/products/categories`, { headers });

    check(categories, {
        'Categories: HTTP 200': r => r.status === 200,
    });

    // 4. Product Details
    const product = http.get(`${BASE}/products/8`, { headers });

    check(product, {
        'Product Details: HTTP 200': r => r.status === 200,
    });

    // 5. Users Ping
    const ping = http.get(`${BASE}/users/ping`, { headers });

    check(ping, {
        'Users Ping: HTTP 200': r => r.status === 200,
    });

    // Authenticated GET endpoints
    if (TOKEN) {

        // 6. Current User
        const me = http.get(`${BASE}/auth/me`, { headers });

        check(me, {
            'Auth Me: HTTP 200': r => r.status === 200,
        });

        // 7. Orders
        const orders = http.get(`${BASE}/orders`, { headers });

        check(orders, {
            'Orders: HTTP 200': r => r.status === 200,
        });

        // 8. Notifications
        const notifications = http.get(`${BASE}/notifications`, { headers });

        check(notifications, {
            'Notifications: HTTP 200': r => r.status === 200,
        });
    }

    sleep(0.2);
}
