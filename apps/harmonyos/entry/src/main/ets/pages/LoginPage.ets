import router from '@ohos.router';

@Entry
@Component
struct LoginPage {
  @State email: string = '';
  @State password: string = '';

  build() {
    Column() {
      Text('Login')
        .fontSize(24)
        .fontWeight(FontWeight.Bold)
        .margin({ bottom: 20 })

      TextInput({ placeholder: 'Email', text: this.email })
        .onChange((value: string) => { this.email = value; })
        .margin({ bottom: 10 })

      TextInput({ placeholder: 'Password', text: this.password })
        .type(InputType.Password)
        .onChange((value: string) => { this.password = value; })
        .margin({ bottom: 20 })

      Button('Login')
        .onClick(() => {
          // Call API
        })
    }
    .width('100%')
    .padding(20)
  }
}
