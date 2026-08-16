// Single source of truth for the API base URL. 部署时在此修改为真实环境地址
// （与 Flutter 的 ApiClient.baseUrl 同模式；CI 仅做结构校验，不做地址注入）。
export const BASE_URL: string = 'https://api.example.com/api';
