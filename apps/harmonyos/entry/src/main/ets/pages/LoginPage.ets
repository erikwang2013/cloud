import router from '@ohos.router';
import { AuthManager, AuthError } from '../common/auth/AuthManager';

@Entry
@Component
struct LoginPage {
  @State email: string = '';
  @State password: string = '';
  @State errorMessage: string = '';
  @State loading: boolean = false;

  build() {
    Column() {
      Text('CloudPlatform')
        .fontSize(24)
        .fontWeight(FontWeight.Bold)
        .margin({ bottom: 20 })

      TextInput({ placeholder: 'Email', text: this.email })
        .onChange((value: string) => {
          this.email = value;
        })
        .margin({ bottom: 10 })

      TextInput({ placeholder: 'Password', text: this.password })
        .type(InputType.Password)
        .onChange((value: string) => {
          this.password = value;
        })
        .margin({ bottom: 20 })

      if (this.errorMessage !== '') {
        Text(this.errorMessage)
          .fontColor(Color.Red)
          .margin({ bottom: 10 })
      }

      Button(this.loading ? 'Logging in...' : 'Login')
        .enabled(!this.loading)
        .onClick(() => {
          this.doLogin();
        })
    }
    .width('100%')
    .padding(20)
  }

  private async doLogin(): Promise<void> {
    if (this.email === '' || this.password === '') {
      this.errorMessage = 'Email and password are required';
      return;
    }
    this.loading = true;
    this.errorMessage = '';
    try {
      await AuthManager.getInstance().login(this.email, this.password);
      router.replaceUrl({ url: 'pages/Home' });
    } catch (e) {
      if (e instanceof AuthError) {
        this.errorMessage = this.mapError(e.code, e.message);
      } else {
        this.errorMessage = 'Network error, please try again';
      }
    } finally {
      this.loading = false;
    }
  }

  private mapError(code: number, fallback: string): string {
    if (code === 401 || code === 422) {
      return 'Invalid credentials';
    }
    if (code === 423) {
      return 'Account temporarily locked';
    }
    return fallback;
  }
}
