package com.cryptosignal.widget

import android.content.Context
import org.json.JSONObject
import java.net.HttpURLConnection
import java.net.URL

object SignalStore {
    private const val PREFS = "crypto_signal"
    private const val KEY_URL = "api_url"
    private const val KEY_JSON = "last_json"

    fun getUrl(context: Context): String = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        .getString(KEY_URL, "") ?: ""

    fun saveUrl(context: Context, url: String) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        .edit().putString(KEY_URL, url.trim()).apply()

    fun fetch(context: Context): JSONObject? {
        val url = getUrl(context)
        if (url.isBlank()) return null
        val conn = (URL(url).openConnection() as HttpURLConnection).apply {
            connectTimeout = 12000
            readTimeout = 20000
            requestMethod = "GET"
            setRequestProperty("Accept", "application/json")
            setRequestProperty("User-Agent", "CryptoSignalAndroid/7.0")
        }
        return try {
            val code = conn.responseCode
            if (code !in 200..299) null else {
                val text = conn.inputStream.bufferedReader().use { it.readText() }
                JSONObject(text).also { saveJson(context, it.toString()) }
            }
        } finally { conn.disconnect() }
    }

    fun saveJson(context: Context, json: String) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        .edit().putString(KEY_JSON, json).apply()

    fun lastJson(context: Context): JSONObject? = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        .getString(KEY_JSON, null)?.let { runCatching { JSONObject(it) }.getOrNull() }
}
