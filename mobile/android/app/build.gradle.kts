plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
}

android {
    namespace = "org.duckdns.ablazov.kassa"
    compileSdk = 36

    defaultConfig {
        applicationId = "org.duckdns.ablazov.kassa"
        minSdk = 26
        targetSdk = 36
        versionCode = 1
        versionName = "1.0"

        // Адрес кассы. Если сервер переедет — поменяйте здесь и соберите приложение заново.
        buildConfigField("String", "KASSA_URL", "\"https://ablazov.duckdns.org/kassa/\"")
    }

    buildFeatures {
        buildConfig = true
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            proguardFiles(getDefaultProguardFile("proguard-android-optimize.txt"), "proguard-rules.pro")
        }
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17)
    }
}

dependencies {
    implementation("androidx.core:core-ktx:1.17.0")
    implementation("androidx.activity:activity-ktx:1.11.0")
}
