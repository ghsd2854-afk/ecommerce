import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    vus: 5,
    duration: '30s',
    summaryTrendStats: ['avg', 'med', 'p(50)', 'p(95)', 'p(99)'],
};

const BASE = 'http://host.docker.internal:8000/api/v1';

export default function () {
    const products = http.get(`${BASE}/products`);

    check(products, {
        'Products 200': (r) => r.status === 200,
        'ETag exists': (r) => !!r.headers['Etag'],
    });

    const etag = products.headers['Etag'];

    if (etag) {
        const conditional = http.get(`${BASE}/products`, {
            headers: { 'If-None-Match': etag },
        });

        check(conditional, {
            'ETag 304': (r) => r.status === 304,
        });
    }

    const categories = http.get(`${BASE}/products/categories`);
    check(categories, {
        'Categories 200': (r) => r.status === 200,
    });

    const product = http.get(`${BASE}/products/8`);
    check(product, {
        'Product details 200': (r) => r.status === 200,
    });

    sleep(0.2);
}
