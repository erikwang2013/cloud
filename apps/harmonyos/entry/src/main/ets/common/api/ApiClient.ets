import http from '@ohos.net.http';
import { preferences } from '@kit.ArkData';

const BASE_URL = 'https://api.example.com/api/v1';

export class ApiClient {
  private static instance: ApiClient;

  static getInstance(): ApiClient {
    if (!ApiClient.instance) {
      ApiClient.instance = new ApiClient();
    }
    return ApiClient.instance;
  }

  async get(path: string, params?: Record<string, string>): Promise<ApiResponse> {
    return this.request('GET', path, params);
  }

  async post(path: string, body?: object): Promise<ApiResponse> {
    return this.request('POST', path, undefined, body);
  }

  private async request(
    method: http.RequestMethod,
    path: string,
    params?: Record<string, string>,
    body?: object
  ): Promise<ApiResponse> {
    const token = await this.getToken();

    const request = http.createHttp();
    const response = await request.request(BASE_URL + path, {
      method: method,
      header: {
        'Content-Type': 'application/json',
        'Authorization': token ? `Bearer ${token}` : '',
        'Accept-Language': 'zh-CN',
      },
      extraData: body ? JSON.stringify(body) : undefined,
    });

    request.destroy();

    const data = JSON.parse(response.result as string);
    return {
      code: data.code,
      message: data.message,
      data: data.data,
      meta: data.meta,
    };
  }

  private async getToken(): Promise<string> {
    const prefs = await preferences.getPreferences(getContext(), 'auth');
    return await prefs.get('access_token', '') as string;
  }
}

interface ApiResponse {
  code: number;
  message: string;
  data?: object;
  meta?: object;
}
