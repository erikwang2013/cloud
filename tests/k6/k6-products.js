import http from 'k6/http';
import { check, sleep } from 'k6';
import { Trend } from 'k6/metrics';

const productListDuration = new Trend('product_list_duration', true);

export const options = {
  thresholds: {
    http_req_duration: ['p(95)<1000'],
    http_req_failed: ['rate<0.02'],
    product_list_duration: ['p(95)<800'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8787';

// Simulated query parameters for cache-hit diversity
const CATEGORIES = ['1', '2', '3', '', ''];
const REGIONS = ['1', '2', '', '', ''];

function random(arr) {
  return arr[Math.floor(Math.random() * arr.length)];
}

export default function () {
  const page = Math.floor(Math.random() * 3) + 1;
  const category = random(CATEGORIES);
  const region = random(REGIONS);

  const params = { page };
  if (category) params.category_id = category;
  if (region) params.region_id = region;

  const resp = http.get(`${BASE_URL}/api/v1/products`, {
    tags: { name: 'product_list' },
    ...(Object.keys(params).length ? { query: params } : {}),
  });

  check(resp, {
    'status 200': r => r.status === 200,
    'has data': r => !!r.json().data,
  });

  productListDuration.add(resp.timings.duration);
  sleep(1);
}
