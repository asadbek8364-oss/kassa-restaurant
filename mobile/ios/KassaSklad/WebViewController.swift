import Network
import UIKit
import WebKit

/// «Касса и склад» в приложении: веб-касса с сервера на весь экран, без панелей браузера.
///
/// - Камера: getUserMedia страницы (сканер) разрешается для домена кассы; системный запрос — по NSCameraUsageDescription.
///   `<input type=file capture>` (штрих-код по фото) WKWebView открывает сам.
/// - Офлайн: домен кассы — App-Bound Domain (WKAppBoundDomains), только так WKWebView включает Service Worker,
///   и оболочка кассы открывается без сети. Чеки без связи копятся в localStorage; копию очереди страница
///   присылает сюда (`saveQueue`), она хранится в файле и возвращается странице при следующем запуске.
/// - Нет сети, а касса ещё ни разу не открывалась — экран «Нет связи», касса откроется сама, когда сеть появится.
final class WebViewController: UIViewController, WKNavigationDelegate, WKUIDelegate, WKScriptMessageHandler {
    private var webView: WKWebView!
    private let offlineView = OfflineView()
    private let monitor = NWPathMonitor()
    private let queueBackup = QueueBackup()
    private var loadFailed = false

    /// Адрес кассы из Info.plist (ключ KassaURL); домен должен совпадать с WKAppBoundDomains.
    private let home: URL = {
        let value = Bundle.main.object(forInfoDictionaryKey: "KassaURL") as? String
        return URL(string: value ?? "") ?? URL(string: "https://ablazov.duckdns.org/kassa/")!
    }()

    override func loadView() {
        let config = WKWebViewConfiguration()
        config.websiteDataStore = .default() // постоянное хранилище: вход, черновики, очередь чеков
        config.allowsInlineMediaPlayback = true // видео с камеры сканера внутри страницы, а не на весь экран
        config.mediaTypesRequiringUserActionForPlayback = []
        config.limitsNavigationsToAppBoundDomains = true
        config.applicationNameForUserAgent = "Mobile/15E148 KassaSklad-iOS/\(Self.appVersion)"
        config.userContentController.add(WeakScriptHandler(self), name: "kassa")
        installQueueScript(into: config.userContentController)

        let webView = WKWebView(frame: .zero, configuration: config)
        webView.navigationDelegate = self
        webView.uiDelegate = self
        webView.allowsBackForwardNavigationGestures = true
        webView.isOpaque = false
        webView.backgroundColor = UIColor(named: "LaunchBackground")
        webView.scrollView.contentInsetAdjustmentBehavior = .never // отступы для «чёлки» и полосы «Домой» задаёт CSS (env(safe-area-inset-*))
        #if DEBUG
        if #available(iOS 16.4, *) { webView.isInspectable = true }
        #endif
        self.webView = webView

        let root = UIView()
        root.backgroundColor = UIColor(named: "LaunchBackground")
        for subview in [webView, offlineView] as [UIView] {
            subview.translatesAutoresizingMaskIntoConstraints = false
            root.addSubview(subview)
        }
        offlineView.isHidden = true
        NSLayoutConstraint.activate([
            // Сверху — под строкой состояния, по бокам и снизу — до краёв экрана.
            webView.topAnchor.constraint(equalTo: root.safeAreaLayoutGuide.topAnchor),
            webView.leadingAnchor.constraint(equalTo: root.leadingAnchor),
            webView.trailingAnchor.constraint(equalTo: root.trailingAnchor),
            webView.bottomAnchor.constraint(equalTo: root.bottomAnchor),
            offlineView.topAnchor.constraint(equalTo: root.topAnchor),
            offlineView.leadingAnchor.constraint(equalTo: root.leadingAnchor),
            offlineView.trailingAnchor.constraint(equalTo: root.trailingAnchor),
            offlineView.bottomAnchor.constraint(equalTo: root.bottomAnchor),
        ])
        view = root
    }

    override func viewDidLoad() {
        super.viewDidLoad()
        offlineView.onRetry = { [weak self] in self?.retry() }
        monitor.pathUpdateHandler = { [weak self] path in
            guard path.status == .satisfied else { return }
            DispatchQueue.main.async {
                guard let self, self.loadFailed else { return }
                self.retry()
            }
        }
        monitor.start(queue: DispatchQueue(label: "kassa.network"))
        webView.load(URLRequest(url: home))
    }

    deinit {
        monitor.cancel()
    }

    private static var appVersion: String {
        Bundle.main.object(forInfoDictionaryKey: "CFBundleShortVersionString") as? String ?? "1.0"
    }

    private func isKassa(_ url: URL?) -> Bool {
        url?.scheme == "https" && url?.host == home.host
    }

    private func showOffline(_ message: String = OfflineView.defaultMessage) {
        loadFailed = true
        offlineView.show(message: message)
    }

    private func retry() {
        loadFailed = false
        offlineView.setLoading(true)
        installQueueScript(into: webView.configuration.userContentController)
        webView.load(URLRequest(url: home))
    }

    /// Копия очереди офлайн-чеков попадает в страницу до её скриптов: window.__kassaQueueBackup.
    private func installQueueScript(into controller: WKUserContentController) {
        controller.removeAllUserScripts()
        let source = "window.__kassaQueueBackup = \(Self.jsString(queueBackup.load()));"
        controller.addUserScript(WKUserScript(source: source, injectionTime: .atDocumentStart, forMainFrameOnly: true))
    }

    private static func jsString(_ text: String) -> String {
        guard let data = try? JSONSerialization.data(withJSONObject: [text]),
              let array = String(data: data, encoding: .utf8) else { return "\"[]\"" }
        return String(array.dropFirst().dropLast()) // ["…"] → "…"
    }

    // MARK: - WKNavigationDelegate

    func webView(
        _ webView: WKWebView,
        decidePolicyFor navigationAction: WKNavigationAction,
        decisionHandler: @escaping (WKNavigationActionPolicy) -> Void
    ) {
        guard let url = navigationAction.request.url else { return decisionHandler(.cancel) }
        if isKassa(url) || ["about", "blob", "data"].contains(url.scheme ?? "") {
            return decisionHandler(.allow)
        }
        // Другие сайты, телефон, почта — в системные приложения, не внутри кассы.
        decisionHandler(.cancel)
        UIApplication.shared.open(url)
    }

    func webView(
        _ webView: WKWebView,
        decidePolicyFor navigationResponse: WKNavigationResponse,
        decisionHandler: @escaping (WKNavigationResponsePolicy) -> Void
    ) {
        // Оболочку при сбое сервера отдаёт Service Worker; сюда попадаем, только если её ещё нет в кэше.
        if navigationResponse.isForMainFrame,
           let http = navigationResponse.response as? HTTPURLResponse, http.statusCode >= 500 {
            decisionHandler(.cancel)
            showOffline("Сервер кассы не отвечает (\(http.statusCode)). Попробуйте чуть позже.")
            return
        }
        decisionHandler(.allow)
    }

    func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) {
        offlineView.setLoading(false)
        if !loadFailed { offlineView.isHidden = true }
    }

    func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) {
        handle(error)
    }

    func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) {
        handle(error)
    }

    private func handle(_ error: Error) {
        let nsError = error as NSError
        if nsError.domain == NSURLErrorDomain && nsError.code == NSURLErrorCancelled { return }
        if nsError.domain == "WebKitErrorDomain" && nsError.code == 102 { return } // переход отменён политикой
        showOffline()
    }

    func webViewWebContentProcessDidTerminate(_ webView: WKWebView) {
        // Процесс страницы выгружен системой (нехватка памяти) — открываем кассу заново.
        retry()
    }

    // MARK: - WKUIDelegate

    /// Камера для сканера: разрешаем странице кассы без лишнего вопроса браузера (системный вопрос iOS остаётся).
    func webView(
        _ webView: WKWebView,
        requestMediaCapturePermissionFor origin: WKSecurityOrigin,
        initiatedByFrame frame: WKFrameInfo,
        type: WKMediaCaptureType,
        decisionHandler: @escaping (WKPermissionDecision) -> Void
    ) {
        decisionHandler(origin.host == home.host && type == .camera ? .grant : .deny)
    }

    func webView(
        _ webView: WKWebView,
        runJavaScriptAlertPanelWithMessage message: String,
        initiatedByFrame frame: WKFrameInfo,
        completionHandler: @escaping () -> Void
    ) {
        let alert = UIAlertController(title: nil, message: message, preferredStyle: .alert)
        alert.addAction(UIAlertAction(title: "OK", style: .default) { _ in completionHandler() })
        present(alert, animated: true)
    }

    // MARK: - WKScriptMessageHandler — мост для страницы (см. NATIVE в public/kassa/app.js)

    func userContentController(_ controller: WKUserContentController, didReceive message: WKScriptMessage) {
        guard message.frameInfo.isMainFrame,
              message.frameInfo.securityOrigin.host == home.host,
              let body = message.body as? [String: Any],
              let action = body["action"] as? String else { return }
        switch action {
        case "openSettings":
            if let url = URL(string: UIApplication.openSettingsURLString) { UIApplication.shared.open(url) }
        case "saveQueue":
            queueBackup.save(body["queue"] as? String ?? "[]")
            installQueueScript(into: controller) // следующая загрузка страницы получит свежую копию
        default:
            break
        }
    }
}

/// Копия очереди офлайн-чеков в файле приложения (запись атомарная).
struct QueueBackup {
    private var url: URL {
        let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        return dir.appendingPathComponent("kassa-offline-queue.json")
    }

    func load() -> String {
        (try? String(contentsOf: url, encoding: .utf8)) ?? "[]"
    }

    func save(_ json: String) {
        try? FileManager.default.createDirectory(at: url.deletingLastPathComponent(), withIntermediateDirectories: true)
        try? Data(json.utf8).write(to: url, options: .atomic)
    }
}

/// WKUserContentController держит обработчик сильной ссылкой — прокладка не даёт контроллеру утечь.
final class WeakScriptHandler: NSObject, WKScriptMessageHandler {
    private weak var target: WKScriptMessageHandler?

    init(_ target: WKScriptMessageHandler) {
        self.target = target
    }

    func userContentController(_ controller: WKUserContentController, didReceive message: WKScriptMessage) {
        target?.userContentController(controller, didReceive: message)
    }
}
