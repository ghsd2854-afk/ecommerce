import http from 'k6/http';
import { sleep } from 'k6';

export const options = {
    vus: 5,
    duration: '15s',
};

export default function () {
    const r = http.get('http://host.docker.internal:8000/api/v1/products');
    if (r.status !== 200) {
        console.log(`ERROR STATUS=${r.status} BODY=${String(r.body).substring(0,150)}`);
    }
    sleep(0.2);
}
