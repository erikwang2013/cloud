import { BASE_URL } from '../config/AppConfig';
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

    const request = http.createHttp();
    const response = await request.request(url, {
      method: method,
      header: {
        'Content-Type': 'application/json',
        'X-Api-Version': 'v1',
        'Authorization': token ? `Bearer ${token}` : '',
        'Accept-Language': 'zh-CN',
      },
      extraData: body ? JSON.stringify(body) : undefined,
      expectDataType: http.HttpDataType.STRING,
    });

    request.destroy();

    return JSON.parse(response.result as string) as ApiResponse;
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
