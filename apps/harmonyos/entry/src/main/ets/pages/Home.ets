import router from '@ohos.router';
import { AuthManager } from '../common/auth/AuthManager';

@Entry
@Component
struct Home {
  @State loggedInAs: string = '';

  aboutToAppear(): void {
    AuthManager.getInstance().getAccessToken().then((token: string) => {
      this.loggedInAs = token ? 'Logged in' : 'Not logged in';
    });
  }

  build() {
    Column() {
      Text('CloudPlatform Home')
        .fontSize(24)
        .fontWeight(FontWeight.Bold)
        .margin({ bottom: 20 })

      Text(this.loggedInAs)
        .fontSize(16)
        .margin({ bottom: 20 })

      Button('Logout')
        .onClick(() => {
          AuthManager.getInstance().clearTokens().then(() => {
            router.replaceUrl({ url: 'pages/LoginPage' });
          });
        })
    }
    .width('100%')
    .padding(20)
  }
}
