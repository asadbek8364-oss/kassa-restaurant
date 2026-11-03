package org.duckdns.ablazov.kassa

import android.Manifest
import android.annotation.SuppressLint
import android.content.ActivityNotFoundException
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.net.ConnectivityManager
import android.net.Network
import android.net.Uri
import android.os.Bundle
import android.provider.Settings
import android.view.View
import android.view.ViewGroup
import android.webkit.CookieManager
import android.webkit.JavascriptInterface
import android.webkit.PermissionRequest
import android.webkit.RenderProcessGoneDetail
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebSettings
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.ProgressBar
import android.widget.TextView
import android.widget.Toast
import androidx.activity.ComponentActivity
import androidx.activity.OnBackPressedCallback
import androidx.activity.enableEdgeToEdge
import androidx.activity.result.contract.ActivityResultContracts
import androidx.core.content.ContextCompat
import androidx.core.content.FileProvider
import androidx.core.content.edit
import androidx.core.net.toUri
import androidx.core.view.ViewCompat
import androidx.core.view.isVisible
import androidx.core.view.WindowInsetsCompat
import java.io.File

/**
 * «Касса и склад» в приложении: веб-касса с сервера на весь экран, без панелей браузера.
 *
 * - Камера: getUserMedia страницы получает доступ после системного разрешения CAMERA;
 *   <input type=file capture> (штрих-код по фото) открывает камеру телефона.
 * - Офлайн: оболочку страницы кэширует её Service Worker, чеки без связи копятся в localStorage.
 *   Копию очереди мост синхронно сохраняет в SharedPreferences — она переживёт закрытие приложения
 *   сразу после продажи (WebView пишет localStorage на диск с задержкой).
 * - Нет сети при запуске и страница ещё не в кэше — экран «Нет связи», касса откроется сама, когда сеть появится.
 */
class MainActivity : ComponentActivity() {

    private lateinit var web: WebView
    private lateinit var offline: View
    private lateinit var offlineText: TextView
    private lateinit var progress: ProgressBar

    private val home: Uri by lazy { BuildConfig.KASSA_URL.toUri() }
    private val prefs by lazy { getSharedPreferences("kassa", Context.MODE_PRIVATE) }

    private var loadFailed = false
    private var pendingPermission: PermissionRequest? = null
    private var fileCallback: ValueCallback<Array<Uri>>? = null
    private var photoUri: Uri? = null
    private var networkCallback: ConnectivityManager.NetworkCallback? = null
    private var webDestroyed = false

    /** Разрешение камеры для сканера в странице (getUserMedia). */
    private val cameraForScanner = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        val request = pendingPermission ?: return@registerForActivityResult
        pendingPermission = null
        if (granted) request.grant(arrayOf(PermissionRequest.RESOURCE_VIDEO_CAPTURE)) else request.deny()
    }

    /** Разрешение камеры перед снимком: при объявленном CAMERA без него системная камера не запустится. */
    private val cameraForPhoto = registerForActivityResult(ActivityResultContracts.RequestPermission()) { granted ->
        if (granted) launchCamera() else finishFileChooser(null)
    }

    private val takePhoto = registerForActivityResult(ActivityResultContracts.TakePicture()) { saved ->
        val uri = photoUri
        photoUri = null
        finishFileChooser(if (saved && uri != null) arrayOf(uri) else null)
    }

    private val pickFile = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { result ->
        finishFileChooser(WebChromeClient.FileChooserParams.parseResult(result.resultCode, result.data))
    }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        enableEdgeToEdge()
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        web = findViewById(R.id.web)
        offline = findViewById(R.id.offline)
        offlineText = findViewById(R.id.offline_text)
        progress = findViewById(R.id.progress)
        findViewById<Button>(R.id.retry).setOnClickListener { retry() }

        // Страница не заходит под строку состояния, панель навигации и клавиатуру.
        val root = findViewById<View>(R.id.root)
        ViewCompat.setOnApplyWindowInsetsListener(root) { view, insets ->
            val bars = insets.getInsets(WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout())
            val ime = insets.getInsets(WindowInsetsCompat.Type.ime())
            view.setPadding(bars.left, bars.top, bars.right, maxOf(bars.bottom, ime.bottom))
            WindowInsetsCompat.CONSUMED
        }

        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG)
        web.setBackgroundColor(ContextCompat.getColor(this, R.color.bg))
        with(web.settings) {
            javaScriptEnabled = true
            domStorageEnabled = true // localStorage: вход, черновики, очередь чеков без связи
            mediaPlaybackRequiresUserGesture = false // видео с камеры сканера стартует сразу
            cacheMode = WebSettings.LOAD_DEFAULT
            allowFileAccess = false
            allowContentAccess = false
            setSupportMultipleWindows(false)
            userAgentString = "$userAgentString KassaSklad-Android/${BuildConfig.VERSION_NAME}"
        }
        CookieManager.getInstance().setAcceptCookie(true)
        // Мост доступен только странице кассы: переходы на другие сайты открываются в браузере (см. shouldOverrideUrlLoading).
        web.addJavascriptInterface(Bridge(), "KassaAndroid")
        web.webViewClient = Client()
        web.webChromeClient = Chrome()

        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() = back()
        })

        watchNetwork()
        if (savedInstanceState == null || web.restoreState(savedInstanceState) == null) {
            web.loadUrl(home.toString())
        }
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        if (!webDestroyed) web.saveState(outState)
    }

    override fun onResume() {
        super.onResume()
        if (!webDestroyed) web.onResume()
    }

    override fun onPause() {
        if (!webDestroyed) web.onPause()
        CookieManager.getInstance().flush()
        super.onPause()
    }

    override fun onDestroy() {
        networkCallback?.let { getSystemService(ConnectivityManager::class.java)?.unregisterNetworkCallback(it) }
        destroyWeb()
        super.onDestroy()
    }

    private fun destroyWeb() {
        if (webDestroyed) return
        webDestroyed = true
        (web.parent as? ViewGroup)?.removeView(web)
        web.destroy()
    }

    /** «Назад»: сначала страница закрывает лист, сканер или экран (window.kassaBack), иначе сворачиваемся. */
    private fun back() {
        if (offline.isVisible) {
            moveTaskToBack(true)
            return
        }
        web.evaluateJavascript("(function(){try{return !!(window.kassaBack&&window.kassaBack());}catch(e){return false;}})()") { handled ->
            if (handled != "true") {
                if (web.canGoBack()) web.goBack() else moveTaskToBack(true)
            }
        }
    }

    private fun isKassa(url: Uri?): Boolean = url != null && url.scheme == "https" && url.host == home.host

    private fun showOffline(message: String) {
        loadFailed = true
        offlineText.text = message
        progress.visibility = View.INVISIBLE
        offline.visibility = View.VISIBLE
    }

    private fun retry() {
        loadFailed = false
        progress.visibility = View.VISIBLE
        val current = web.url
        if (current != null && isKassa(current.toUri())) web.reload() else web.loadUrl(home.toString())
    }

    /** Сеть появилась — если касса не открылась, пробуем снова без нажатия кнопки. */
    private fun watchNetwork() {
        val cm = getSystemService(ConnectivityManager::class.java) ?: return
        val callback = object : ConnectivityManager.NetworkCallback() {
            override fun onAvailable(network: Network) {
                runOnUiThread { if (loadFailed && !isFinishing) retry() }
            }
        }
        cm.registerDefaultNetworkCallback(callback)
        networkCallback = callback
    }

    private fun hasCamera(): Boolean =
        ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) == PackageManager.PERMISSION_GRANTED

    private fun launchCamera() {
        try {
            val dir = File(cacheDir, "photos").apply { mkdirs() }
            dir.listFiles()?.forEach { it.delete() } // старые снимки больше не нужны
            val file = File.createTempFile("barcode_", ".jpg", dir)
            val uri = FileProvider.getUriForFile(this, "$packageName.files", file)
            photoUri = uri
            takePhoto.launch(uri)
        } catch (e: ActivityNotFoundException) {
            Toast.makeText(this, R.string.no_camera_app, Toast.LENGTH_LONG).show()
            finishFileChooser(null)
        } catch (e: Exception) {
            finishFileChooser(null)
        }
    }

    private fun finishFileChooser(result: Array<Uri>?) {
        fileCallback?.onReceiveValue(result)
        fileCallback = null
    }

    private inner class Client : WebViewClient() {
        override fun shouldOverrideUrlLoading(view: WebView, request: WebResourceRequest): Boolean {
            if (isKassa(request.url)) return false
            // Другие сайты, телефон, почта — в системные приложения, не внутри кассы.
            try {
                startActivity(Intent(Intent.ACTION_VIEW, request.url))
            } catch (_: ActivityNotFoundException) {
            }
            return true
        }

        override fun onPageFinished(view: WebView, url: String) {
            progress.visibility = View.INVISIBLE
            if (!loadFailed) offline.visibility = View.GONE
        }

        override fun onReceivedError(view: WebView, request: WebResourceRequest, error: WebResourceError) {
            if (request.isForMainFrame) showOffline(getString(R.string.offline_text))
        }

        override fun onReceivedHttpError(view: WebView, request: WebResourceRequest, response: WebResourceResponse) {
            // Оболочку при сбое сервера отдаёт Service Worker; сюда попадаем, только если её ещё нет в кэше.
            if (request.isForMainFrame && response.statusCode >= 500) {
                showOffline(getString(R.string.offline_server, response.statusCode.toString()))
            }
        }

        override fun onRenderProcessGone(view: WebView, detail: RenderProcessGoneDetail): Boolean {
            // Процесс страницы упал (нехватка памяти) — пересоздаём экран вместо падения приложения.
            destroyWeb()
            recreate()
            return true
        }
    }

    private inner class Chrome : WebChromeClient() {
        override fun onPermissionRequest(request: PermissionRequest) {
            val wantsCamera = PermissionRequest.RESOURCE_VIDEO_CAPTURE in request.resources
            if (!wantsCamera || !isKassa(request.origin)) {
                request.deny()
                return
            }
            if (hasCamera()) {
                request.grant(arrayOf(PermissionRequest.RESOURCE_VIDEO_CAPTURE))
            } else {
                pendingPermission?.deny()
                pendingPermission = request
                cameraForScanner.launch(Manifest.permission.CAMERA)
            }
        }

        override fun onPermissionRequestCanceled(request: PermissionRequest) {
            if (pendingPermission == request) pendingPermission = null
        }

        override fun onShowFileChooser(view: WebView, callback: ValueCallback<Array<Uri>>, params: FileChooserParams): Boolean {
            fileCallback?.onReceiveValue(null)
            fileCallback = callback
            val canShoot = packageManager.hasSystemFeature(PackageManager.FEATURE_CAMERA_ANY)
            if (params.isCaptureEnabled && canShoot) {
                if (hasCamera()) launchCamera() else cameraForPhoto.launch(Manifest.permission.CAMERA)
                return true
            }
            return try {
                pickFile.launch(params.createIntent())
                true
            } catch (_: ActivityNotFoundException) {
                fileCallback = null
                false
            }
        }
    }

    /** window.KassaAndroid — мост для страницы кассы (см. NATIVE в public/kassa/app.js). */
    private inner class Bridge {
        @JavascriptInterface
        fun platform(): String = "android"

        @JavascriptInterface
        fun version(): String = BuildConfig.VERSION_NAME

        /** Камера запрещена навсегда — открыть настройки приложения, чтобы разрешить её. */
        @JavascriptInterface
        fun openAppSettings() {
            runOnUiThread {
                startActivity(Intent(Settings.ACTION_APPLICATION_DETAILS_SETTINGS, Uri.fromParts("package", packageName, null)))
            }
        }

        /** Копия очереди офлайн-чеков; commit() пишет на диск сразу, до возврата в страницу. */
        @JavascriptInterface
        fun saveQueue(json: String) {
            prefs.edit(commit = true) { putString(KEY_QUEUE, json) }
        }

        @JavascriptInterface
        fun loadQueue(): String = prefs.getString(KEY_QUEUE, "[]") ?: "[]"
    }

    private companion object {
        const val KEY_QUEUE = "offline_queue"
    }
}
