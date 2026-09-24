import Foundation
import UIKit

/**
 * App-Release 平台 iOS 应用内更新 SDK
 * 功能：检测更新、跳转 App Store / 企业分发页 / itms-services 安装
 */
public class UpdateClient {
    private let apiBase: String
    private let appCode: String
    private let apiKey: String
    private let deviceId: String
    private let currentVersion: String
    private let session = URLSession.shared

    public init(apiBase: String, appCode: String, apiKey: String, deviceId: String? = nil, currentVersion: String? = nil) {
        self.apiBase = apiBase.hasSuffix("/") ? apiBase : apiBase + "/"
        self.appCode = appCode
        self.apiKey = apiKey
        self.deviceId = deviceId ?? UIDevice.current.identifierForVendor?.uuidString ?? ""
        self.currentVersion = currentVersion ?? (Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String) ?? "1.0.0"
    }

    /**
     * 检查更新，返回新版本信息或 nil
     */
    public func check(completion: @escaping (UpdateInfo?, Error?) -> Void) {
        var comps = URLComponents(string: apiBase + "api/v1/app/update/check")!
        comps.queryItems = [
            URLQueryItem(name: "app_code", value: appCode),
            URLQueryItem(name: "device_id", value: deviceId),
            URLQueryItem(name: "platform", value: "ios"),
            URLQueryItem(name: "current_version", value: currentVersion)
        ]
        var req = URLRequest(url: comps.url!, timeoutInterval: 15)
        req.setValue(apiKey, forHTTPHeaderField: "X-Api-Key")
        session.dataTask(with: req) { data, resp, err in
            DispatchQueue.main.async {
                if let err = err { completion(nil, err); return }
                guard let data = data,
                      let json = try? JSONSerialization.jsonObject(with: data) as? [String: Any] else {
                    completion(nil, NSError(domain: "UpdateClient", code: -1, userInfo: [NSLocalizedDescriptionKey: "Parse failed"]))
                    return
                }
                if (json["code"] as? Int ?? 0) != 0 {
                    let msg = json["message"] as? String ?? "unknown"
                    completion(nil, NSError(domain: "UpdateClient", code: -2, userInfo: [NSLocalizedDescriptionKey: msg]))
                    return
                }
                guard let d = json["data"] as? [String: Any],
                      let ver = d["version_name"] as? String else {
                    completion(nil, nil) // 无更新
                    return
                }
                let info = UpdateInfo(
                    versionName: ver,
                    downloadUrl: d["download_url"] as? String ?? "",
                    releaseNotes: d["release_notes"] as? String ?? "",
                    forceUpdate: d["force_update"] as? Bool ?? false,
                    installUrl: d["install_url"] as? String
                )
                completion(info, nil)
            }
        }.resume()
    }

    /**
     * 显示默认更新弹窗
     */
    public func showUpdateAlert(on vc: UIViewController, info: UpdateInfo) {
        let alert = UIAlertController(
            title: "发现新版本 \(info.versionName)",
            message: info.releaseNotes.isEmpty ? nil : info.releaseNotes,
            preferredStyle: .alert
        )
        alert.addAction(UIAlertAction(title: "立即更新", style: .default) { _ in
            self.openDownload(info: info)
        })
        if !info.forceUpdate {
            alert.addAction(UIAlertAction(title: "稍后", style: .cancel))
        }
        vc.present(alert, animated: true)
    }

    /**
     * 打开下载/安装链接
     * 优先 installUrl（itms-services）> downloadUrl（浏览器直链）
     */
    public func openDownload(info: UpdateInfo) {
        let urlStr = info.installUrl ?? info.downloadUrl
        guard let url = URL(string: urlStr), UIApplication.shared.canOpenURL(url) else { return }
        UIApplication.shared.open(url, options: [:])
    }
}

public struct UpdateInfo {
    public let versionName: String
    public let downloadUrl: String
    public let releaseNotes: String
    public let forceUpdate: Bool
    public let installUrl: String?
}
