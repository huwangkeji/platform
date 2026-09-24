package com.apprelease.sdk;

import android.app.Activity;
import android.app.AlertDialog;
import android.app.DownloadManager;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.database.Cursor;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.widget.Toast;

import androidx.core.content.FileProvider;

import org.json.JSONObject;

import java.io.File;
import java.io.IOException;
import java.util.concurrent.TimeUnit;

import okhttp3.Call;
import okhttp3.Callback;
import okhttp3.OkHttpClient;
import okhttp3.Request;
import okhttp3.Response;

/**
 * App-Release 平台 Android 应用内更新 SDK
 * 功能：检测更新、下载 APK、自动安装
 */
public class UpdateClient {
    private static final String TAG = "UpdateClient";
    private static final long TIMEOUT_MS = 15_000;
    private final OkHttpClient client;
    private final Context ctx;
    private final String apiBase;
    private final String appCode;
    private final String apiKey;
    private final String deviceId;
    private final Handler mainHandler;
    private long downloadId = -1;

    private UpdateClient(Builder b) {
        this.ctx = b.ctx;
        this.apiBase = b.apiBase.endsWith("/") ? b.apiBase : b.apiBase + "/";
        this.appCode = b.appCode;
        this.apiKey = b.apiKey;
        this.deviceId = b.deviceId != null ? b.deviceId : Settings.Secure.getString(ctx.getContentResolver(), Settings.Secure.ANDROID_ID);
        this.mainHandler = new Handler(Looper.getMainLooper());
        this.client = new OkHttpClient.Builder()
                .connectTimeout(TIMEOUT_MS, TimeUnit.MILLISECONDS)
                .readTimeout(TIMEOUT_MS, TimeUnit.MILLISECONDS)
                .build();
    }

    /**
     * 静默检查更新，有新版本时 callback.onUpdate(versionJson)
     */
    public void check(final UpdateCallback callback) {
        String url = apiBase + "api/v1/app/update/check"
                + "?app_code=" + Uri.encode(appCode)
                + "&device_id=" + Uri.encode(deviceId)
                + "&platform=android"
                + "&current_version=" + Uri.encode(getCurrentVersionName(ctx));
        Request req = new Request.Builder()
                .url(url)
                .header("X-Api-Key", apiKey)
                .build();
        client.newCall(req).enqueue(new Callback() {
            @Override public void onFailure(Call call, IOException e) {
                runOnUi(() -> callback.onError(e.getMessage()));
            }
            @Override public void onResponse(Call call, Response resp) throws IOException {
                if (!resp.isSuccessful()) {
                    runOnUi(() -> callback.onError("HTTP " + resp.code()));
                    return;
                }
                String body = resp.body().string();
                try {
                    JSONObject json = new JSONObject(body);
                    if (json.optInt("code", 0) != 0) {
                        runOnUi(() -> callback.onError(json.optString("message", "unknown")));
                        return;
                    }
                    JSONObject data = json.optJSONObject("data");
                    if (data != null && data.has("version_name")) {
                        runOnUi(() -> callback.onUpdate(data));
                    } else {
                        runOnUi(callback::onNoUpdate);
                    }
                } catch (Exception e) {
                    runOnUi(() -> callback.onError(e.getMessage()));
                }
            }
        });
    }

    /**
     * 显示默认更新弹窗并支持下载
     */
    public void showUpdateDialog(final Activity activity, final JSONObject version) {
        String title = version.optString("version_name", "新版本");
        String desc = version.optString("release_notes", "");
        new AlertDialog.Builder(activity)
                .setTitle("发现新版本: " + title)
                .setMessage(desc)
                .setPositiveButton("立即更新", (d, w) -> {
                    String url = version.optString("download_url", "");
                    if (!url.isEmpty()) {
                        startDownload(activity, url, title + ".apk");
                    }
                })
                .setNegativeButton("稍后", null)
                .setCancelable(!version.optBoolean("force_update", false))
                .show();
    }

    /**
     * 使用系统 DownloadManager 下载 APK
     */
    public void startDownload(Activity activity, String url, String fileName) {
        DownloadManager dm = (DownloadManager) activity.getSystemService(Context.DOWNLOAD_SERVICE);
        Uri uri = Uri.parse(url);
        DownloadManager.Request req = new DownloadManager.Request(uri);
        req.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
        req.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName);
        req.setMimeType("application/vnd.android.package-archive");
        downloadId = dm.enqueue(req);

        BroadcastReceiver receiver = new BroadcastReceiver() {
            @Override public void onReceive(Context context, Intent intent) {
                long id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1);
                if (id != downloadId) return;
                DownloadManager.Query q = new DownloadManager.Query();
                q.setFilterById(id);
                try (Cursor c = dm.query(q)) {
                    if (c.moveToFirst()) {
                        int status = c.getInt(c.getColumnIndexOrThrow(DownloadManager.COLUMN_STATUS));
                        if (status == DownloadManager.STATUS_SUCCESSFUL) {
                            String localUri = c.getString(c.getColumnIndexOrThrow(DownloadManager.COLUMN_LOCAL_URI));
                            installApk(activity, Uri.parse(localUri));
                        } else {
                            Toast.makeText(activity, "下载失败", Toast.LENGTH_SHORT).show();
                        }
                    }
                }
                activity.unregisterReceiver(this);
            }
        };
        activity.registerReceiver(receiver, new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE));
    }

    private void installApk(Activity activity, Uri apkUri) {
        Intent intent = new Intent(Intent.ACTION_VIEW);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            intent.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
            File file = new File(apkUri.getPath());
            Uri contentUri = FileProvider.getUriForFile(activity, activity.getPackageName() + ".fileprovider", file);
            intent.setDataAndType(contentUri, "application/vnd.android.package-archive");
        } else {
            intent.setDataAndType(apkUri, "application/vnd.android.package-archive");
        }
        activity.startActivity(intent);
    }

    private void runOnUi(Runnable r) {
        mainHandler.post(r);
    }

    private static String getCurrentVersionName(Context ctx) {
        try {
            return ctx.getPackageManager().getPackageInfo(ctx.getPackageName(), 0).versionName;
        } catch (Exception e) {
            return "1.0.0";
        }
    }

    public interface UpdateCallback {
        void onUpdate(JSONObject version);
        void onNoUpdate();
        void onError(String msg);
    }

    public static class Builder {
        private final Context ctx;
        private String apiBase = "";
        private String appCode = "";
        private String apiKey = "";
        private String deviceId = null;

        public Builder(Context ctx) {
            this.ctx = ctx.getApplicationContext();
        }
        public Builder apiBase(String v) { this.apiBase = v; return this; }
        public Builder appCode(String v) { this.appCode = v; return this; }
        public Builder apiKey(String v) { this.apiKey = v; return this; }
        public Builder deviceId(String v) { this.deviceId = v; return this; }
        public UpdateClient build() { return new UpdateClient(this); }
    }
}
