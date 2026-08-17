// Single source of truth for the API base URL. 部署时在此修改为真实环境地址
// （与 Flutter 的 ApiClient.baseUrl 同模式；CI 仅做结构校验，不做地址注入）。
export const BASE_URL: string = 'https://api.example.com/api';

// AES-256-GCM 传输加密共享密钥（base64, 32 字节），与服务端 ENCRYPTION_MASTER_KEY 一致。
// 开发密钥取自 service/.env；生产环境必须通过安全渠道分发独立密钥。
export const ENCRYPTION_MASTER_KEY: string = 'y1Odnbg50NHEvvLHGb8aexKqy8AQ7izm0x+yoEgrYCU=';
