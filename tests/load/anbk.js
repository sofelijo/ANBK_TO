import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    scenarios: {
        concurrent_students: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '30s', target: Number(__ENV.TARGET_VUS || 50) },
                { duration: '2m', target: Number(__ENV.TARGET_VUS || 50) },
                { duration: '30s', target: 0 },
            ],
        },
    },
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<1000'],
    },
};

const baseUrl = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');
const password = __ENV.LOAD_PASSWORD || 'load-test-only';

export default function () {
    const suffix = String(__VU).padStart(4, '0');
    const email = `loadtest+${suffix}@anbk.invalid`;
    const loginPage = http.get(`${baseUrl}/login`);
    const tokenCookie = loginPage.cookies['XSRF-TOKEN']?.[0]?.value;

    check(loginPage, { 'login page available': (response) => response.status === 200 });
    if (!tokenCookie) return;

    const headers = {
        'Content-Type': 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(tokenCookie),
        Accept: 'application/json',
        Referer: `${baseUrl}/login`,
    };
    const login = http.post(`${baseUrl}/login`, JSON.stringify({ email, password }), { headers });
    check(login, { 'student logged in': (response) => response.status === 200 });

    const dashboard = http.get(`${baseUrl}/dashboard`);
    const assessments = http.get(`${baseUrl}/assessments`);
    check(dashboard, { 'dashboard available': (response) => response.status === 200 });
    check(assessments, { 'assessment list available': (response) => response.status === 200 });

    if (__ENV.ASSESSMENT_ID) {
        const start = http.post(`${baseUrl}/assessments/${__ENV.ASSESSMENT_ID}/start`, null, { headers });
        check(start, { 'attempt can start or resume': (response) => response.status === 200 });
    }

    sleep(1);
}
