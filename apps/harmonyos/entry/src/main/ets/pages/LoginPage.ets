import router from '@ohos.router';
import { AuthManager, AuthError } from '../common/auth/AuthManager';
import { ApiClient } from '../common/api/ApiClient';
import { image } from '@kit.ImageKit';
import { util } from '@kit.ArkTS';
import { display } from '@kit.ArkUI';

interface CaptchaData {
  key: string;
  image: string;
  target_count: number;
}

class CaptchaRequest {
}

@Entry
@Component
struct LoginPage {
  @State email: string = '';
  @State password: string = '';
  @State errorMessage: string = '';
  @State loading: boolean = false;
  @State captchaVisible: boolean = false;
  @State captchaKey: string = '';
  @State targetCount: number = 0;
  @State taps: number[][] = [];
  @State captchaPixelMap: image.PixelMap | undefined = undefined;
  @State imgWidth: number = 0;
  @State imgHeight: number = 0;
  @State imgScale: number = 1;

  private async fetchCaptcha(): Promise<void> {
    const resp = await ApiClient.getInstance().post('/captcha/create', new CaptchaRequest());
    if (resp.code !== 0) {
      throw new AuthError(resp.code, resp.message);
    }
    const captcha = resp.data as CaptchaData;
    const bytes = new util.Base64Helper().decodeSync(captcha.image);
    const source = image.createImageSource(bytes);
    const pixelMap = await source.createPixelMap();
    const info = await pixelMap.getImageInfo();
    this.captchaPixelMap = pixelMap;
    this.imgWidth = info.size.width;
    this.imgHeight = info.size.height;
    this.imgScale = Math.min(1, (display.getDefaultDisplaySync().width - 40) / info.size.width);
    this.captchaKey = captcha.key;
    this.targetCount = captcha.target_count;
    this.taps = [];
    this.captchaVisible = true;
  }

  private async submitLogin(): Promise<void> {
    this.captchaVisible = false;
    this.loading = true;
    this.errorMessage = '';
    try {
      await AuthManager.getInstance()
        .login(this.email, this.password, this.captchaKey, this.taps);
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

  private onCaptchaTouch(event: TouchEvent): void {
    if (event.type !== TouchType.Down) {
      return;
    }
    const touch = event.touches[0];
    const x = Math.round(touch.x / this.imgScale);
    const y = Math.round(touch.y / this.imgScale);
    const next = this.taps.slice();
    next.push([x, y]);
    this.taps = next;
    if (next.length >= this.targetCount) {
      this.submitLogin();
    }
  }

  build() {
    Stack() {
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
          .enabled(!this.loading && !this.captchaVisible)
          .onClick(() => {
            this.doLogin();
          })
      }
      .width('100%')
      .padding(20)

      if (this.captchaVisible && this.captchaPixelMap !== undefined) {
        Column() {
          Text(`Tap ${this.targetCount} targets in order (${this.taps.length}/${this.targetCount})`)
            .fontSize(14)
            .margin({ bottom: 8 })
          Stack() {
            Image(this.captchaPixelMap)
              .width(this.imgWidth * this.imgScale)
              .height(this.imgHeight * this.imgScale)
            ForEach(this.taps, (tap: number[], index: number) => {
              Text(`${index + 1}`)
                .width(24)
                .height(24)
                .borderRadius(12)
                .backgroundColor('#CCFF0000')
                .fontColor(Color.White)
                .textAlign(TextAlign.Center)
                .position({ x: tap[0] * this.imgScale - 12, y: tap[1] * this.imgScale - 12 })
            }, (tap: number[]) => `${tap[0]}-${tap[1]}`)
          }
          .width(this.imgWidth * this.imgScale)
          .height(this.imgHeight * this.imgScale)
          .onTouch((event: TouchEvent) => {
            this.onCaptchaTouch(event);
          })
          .clip(true)
          .margin({ bottom: 8 })
          Row({ space: 16 }) {
            Button('Refresh')
              .fontSize(14)
              .onClick(() => {
                this.fetchCaptcha();
              })
            Button('Cancel')
              .fontSize(14)
              .onClick(() => {
                this.captchaVisible = false;
                this.taps = [];
              })
          }
        }
        .padding(16)
        .backgroundColor(Color.White)
        .borderRadius(12)
        .shadow({ radius: 16, color: '#33000000' })
      }
    }
  }

  private async doLogin(): Promise<void> {
    if (this.email === '' || this.password === '') {
      this.errorMessage = 'Email and password are required';
      return;
    }
    this.loading = true;
    this.errorMessage = '';
    try {
      await this.fetchCaptcha();
    } catch (e) {
      this.errorMessage = e instanceof AuthError ? this.mapError(e.code, e.message) : 'Network error, please try again';
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
