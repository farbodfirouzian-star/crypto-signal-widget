package com.cryptosignal.widget

import android.app.Activity
import android.os.Bundle
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.WorkManager

class MainActivity : Activity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val box = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(40, 55, 40, 40) }
        val title = TextView(this).apply { text = "🤖 CryptoSignal AI"; textSize = 24f }
        val help = TextView(this).apply { text = "\nآدرس API پروژه PHP را وارد کن. مثال:\nhttps://example.com/crypto/index.php?api=1\n\nبعد از ذخیره، اولین تحلیل را اجرا می‌کنیم."; textSize = 15f }
        val input = EditText(this).apply { hint = "https://.../index.php?api=1"; setSingleLine(true); setText(SignalStore.getUrl(this@MainActivity)) }
        val save = Button(this).apply { text = "ذخیره و تحلیل الآن" }
        val status = TextView(this).apply { textSize = 13f }
        save.setOnClickListener {
            val url = input.text.toString().trim()
            if (!url.startsWith("https://")) { status.text = "لطفاً یک آدرس HTTPS معتبر وارد کن."; return@setOnClickListener }
            SignalStore.saveUrl(this, url)
            status.text = "در حال دریافت تحلیل…"
            WorkManager.getInstance(this).enqueue(OneTimeWorkRequestBuilder<SignalWorker>().build())
            status.postDelayed({ status.text = "تحلیل در پس‌زمینه اجرا شد؛ ویجت را روی صفحه اصلی اضافه کن." }, 1200)
            CryptoWidget.schedule(this)
        }
        box.addView(title); box.addView(help); box.addView(input); box.addView(save); box.addView(status)
        setContentView(box)
    }
}
