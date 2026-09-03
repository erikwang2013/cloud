import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  thresholds: {
    http_req_duration: ['p(95)<500'],
    http_req_failed: ['rate<0.01'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8787';

export default function () {
  // Health check
  const health = http.get(`${BASE_URL}/health`);
  check(health, { 'health status 200': r => r.status === 200 });

  // Products
  const products = http.get(`${BASE_URL}/api/v1/products`, {
  });
  check(products, { 'products 200': r => r.status === 200 });

  // Regions
  const regions = http.get(`${BASE_URL}/api/v1/regions`, {
  });
  check(regions, { 'regions 200': r => r.status === 200 });

  // TLDs
  const tlds = http.get(`${BASE_URL}/api/v1/domain/tlds`, {
  });
  check(tlds, { 'tlds 200': r => r.status === 200 });

  // Help
  const help = http.get(`${BASE_URL}/api/v1/help`, {
  });
  check(help, { 'help 200': r => r.status === 200 });

  // Status
  const status = http.get(`${BASE_URL}/api/v1/status`, {
  });
  check(status, { 'status 200': r => r.status === 200 });

  sleep(1);
}
