import { BASE_URL } from '../config/AppConfig';
import { CryptoService } from '../crypto/CryptoService';
import { preferences } from '@kit.ArkData';
import http from '@ohos.net.http';

export class ApiClient {
  private static instance: ApiClient;

  static getInstance(): ApiClient {
    if (!ApiClient.instance) {
      ApiClient.instance = new ApiClient();
    }
    return ApiClient.instance;
  }

  async get(path: string, params?: Record<string, string>): Promise<ApiResponse> {
    return this.request(http.RequestMethod.GET, path, params);
  }

  async post(path: string, body?: object): Promise<ApiResponse> {
    return this.request(http.RequestMethod.POST, path, undefined, body);
  }

  private async request(
    method: http.RequestMethod,
    path: string,
    params?: Record<string, string>,
    body?: object
  ): Promise<ApiResponse> {
    const token = await this.getToken();
    const query = this.buildQuery(params);
    const url = BASE_URL + path + query;

    const header: Record<string, string> = {
      'Content-Type': 'application/json',
      'Authorization': token ? `Bearer ${token}` : '',
      'Accept-Language': 'zh-CN',
    };
    // 服务端 EncryptionMiddleware 仅当收到 X-Encrypted 头时解密请求体并加密响应
    const encrypted = body !== undefined;
    if (encrypted) {
      header['X-Encrypted'] = '1';
    }

    const request = http.createHttp();
    const response = await request.request(url, {
      method: method,
      header: header,
      extraData: encrypted
        ? JSON.stringify({ payload: await CryptoService.encrypt(JSON.stringify(body)) })
        : JSON.stringify(body),
      expectDataType: http.HttpDataType.STRING,
    });

    request.destroy();

    const parsed = JSON.parse(response.result as string) as RawResponse;
    if (encrypted && parsed.payload !== undefined) {
      return JSON.parse(await CryptoService.decrypt(parsed.payload)) as ApiResponse;
    }
    return parsed as ApiResponse;
  }

  private buildQuery(params?: Record<string, string>): string {
    if (!params || Object.keys(params).length === 0) {
      return '';
    }
    const parts: string[] = [];
    Object.keys(params).forEach((key: string) => {
      parts.push(`${encodeURIComponent(key)}=${encodeURIComponent(params[key])}`);
    });
    return '?' + parts.join('&');
  }

  private async getToken(): Promise<string> {
    const prefs = await preferences.getPreferences(getContext(), 'auth');
    return await prefs.get('access_token', '') as string;
  }
}

export interface ApiResponse {
  code: number;
  message: string;
  data?: object;
  meta?: object;
}

// 加密响应信封 { payload, request_id } 与未加密响应结构的并集
interface RawResponse {
  code?: number;
  message?: string;
  data?: object;
  meta?: object;
  payload?: string;
}
