// Single source of truth for the API base URL (CI overrides this constant to
// point at a test environment; real deployments edit it, same as Flutter's
// ApiClient.baseUrl).
export const BASE_URL: string = 'https://api.example.com/api';
