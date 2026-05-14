import { preferences } from '@kit.ArkData';

export class AuthManager {
  private static instance: AuthManager;

  static getInstance(): AuthManager {
    if (!AuthManager.instance) {
      AuthManager.instance = new AuthManager();
    }
    return AuthManager.instance;
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
