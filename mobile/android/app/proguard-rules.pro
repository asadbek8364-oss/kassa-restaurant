# Методы моста для страницы кассы (window.KassaAndroid) вызываются из JavaScript по имени.
-keepclassmembers class * {
    @android.webkit.JavascriptInterface <methods>;
}
