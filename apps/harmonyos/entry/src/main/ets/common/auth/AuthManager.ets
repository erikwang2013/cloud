import { preferences } from '@kit.ArkData';
import { ApiClient } from '../api/ApiClient';

interface TokenPayload {
  access_token: string;
  refresh_token: string;
}

export class AuthManager {
  private static instance: AuthManager;

  static getInstance(): AuthManager {
    if (!AuthManager.instance) {
      AuthManager.instance = new AuthManager();
    }
    return AuthManager.instance;
  }

  async login(login: string, password: string): Promise<void> {
    const resp = await ApiClient.getInstance().post('/auth/login', { login, password });
    if (resp.code !== 0) {
      throw new AuthError(resp.code, resp.message);
    }
    const tokens = resp.data as TokenPayload;
    await this.saveToken(tokens.access_token, tokens.refresh_token);
  }

  async saveToken(accessToken: string, refreshToken: string): Promise<void> {
    const prefs = await preferences.getPreferences(getContext(), 'auth');
    await prefs.put('access_token', accessToken);
    await prefs.put('refresh_token', refreshToken);
    await prefs.flush();
  }

  async getAccessToken(): Promise<string> {
    const prefs = await preferences.getPreferences(getContext(), 'auth');
    return await prefs.get('access_token', '') as string;
  }

  async clearTokens(): Promise<void> {
    const prefs = await preferences.getPreferences(getContext(), 'auth');
    await prefs.delete('access_token');
    await prefs.delete('refresh_token');
    await prefs.flush();
  }
}

export class AuthError extends Error {
  code: number;

  constructor(code: number, message: string) {
    super(message);
    this.code = code;
  }
}
