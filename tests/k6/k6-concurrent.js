import http from 'k6/http';
import { check, sleep } from 'k6';

const VUS = parseInt(__ENV.VUS) || 100;
const DURATION = __ENV.DURATION || '60s';

export const options = {
  stages: [
    { duration: '10s', target: Math.min(VUS, 50) },
    { duration: '20s', target: VUS },
    { duration: DURATION, target: VUS },
    { duration: '10s', target: 0 },
  ],
  thresholds: {
    http_req_duration: ['p(95)<2000', 'p(99)<5000'],
    http_req_failed: ['rate<0.05'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8787';

export default function () {
  // Mix of read-heavy endpoints (simulating real traffic)
  const reqs = [
    { method: 'GET', path: '/health', tag: 'health' },
    { method: 'GET', path: '/api/v1/products', tag: 'products' },
    { method: 'GET', path: '/api/v1/regions', tag: 'regions' },
    { method: 'GET', path: '/api/v1/domain/tlds', tag: 'tlds' },
    { method: 'GET', path: '/api/v1/help', tag: 'help' },
    { method: 'GET', path: '/api/v1/status', tag: 'status' },
    { method: 'GET', path: '/api/v1/products?page=1', tag: 'products_p1' },
    { method: 'GET', path: '/api/v1/products?page=1&category_id=1', tag: 'products_filtered' },
  ];

  const req = reqs[Math.floor(Math.random() * reqs.length)];

  const resp = http.request(req.method, `${BASE_URL}${req.path}`, undefined, {
    tags: { name: req.tag },
  });

  check(resp, { [`${req.tag} OK`]: r => r.status === 200 || r.status === 404 });

  sleep(0.5 + Math.random() * 2);
}
