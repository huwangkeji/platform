# App-Release 平台 应用内更新 SDK

## Android (Java)

```java
UpdateClient client = new UpdateClient.Builder(context)
    .apiBase("https://your-domain.com/")
    .appCode("your_app_code")
    .apiKey("your_api_key")
    .build();

client.check(new UpdateClient.UpdateCallback() {
    @Override public void onUpdate(JSONObject version) {
        // 在 UI 线程，展示更新提示或调用 client.showUpdateDialog(activity, version)
    }
    @Override public void onNoUpdate() { /* 已是最新 */ }
    @Override public void onError(String msg) { /* 处理错误 */ }
});
```

依赖: OkHttp

```groovy
implementation 'com.squareup.okhttp3:okhttp:4.12.0'
```

## iOS (Swift)

```swift
let client = UpdateClient(
    apiBase: "https://your-domain.com/",
    appCode: "your_app_code",
    apiKey: "your_api_key"
)

client.check { info, error in
    guard let info = info else { return }
    client.showUpdateAlert(on: self, info: info)
}
```

## 说明

- `api/v1/app/update/check` 为平台标准更新检测接口
- Android SDK 使用系统 DownloadManager 下载并自动触发 APK 安装
- iOS SDK 优先使用 `install_url`（itms-services 企业分发），回退到浏览器下载
- 两者均支持 `force_update` 强制更新标记
