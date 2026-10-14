package com.cryptosignal.widget

import android.content.Context
import androidx.glance.GlanceId
import androidx.glance.GlanceModifier
import androidx.glance.appwidget.GlanceAppWidget
import androidx.glance.appwidget.GlanceAppWidgetReceiver
import androidx.glance.background
import androidx.glance.layout.Column
import androidx.glance.layout.Row
import androidx.glance.layout.Spacer
import androidx.glance.layout.fillMaxWidth
import androidx.glance.layout.height
import androidx.glance.layout.padding
import androidx.glance.layout.width
import androidx.glance.text.Text
import androidx.glance.text.TextStyle
import androidx.glance.unit.ColorProvider
import androidx.glance.unit.dp
import androidx.glance.unit.sp
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import java.util.concurrent.TimeUnit
import org.json.JSONObject

class CryptoWidget : GlanceAppWidget() {
    override suspend fun provideGlance(context: Context, id: GlanceId) {
        provideContent {
            val data = SignalStore.lastJson(context)
            Column(modifier = GlanceModifier.fillMaxWidth().background(ColorProvider(android.graphics.Color.rgb(14,18,24))).padding(14.dp)) {
                Text("🤖 CryptoSignal AI", style = TextStyle(fontSize = 17.sp, color = ColorProvider(android.graphics.Color.WHITE)))
                Spacer(GlanceModifier.height(6.dp))
                if (data == null) {
                    Text(if (SignalStore.getUrl(context).isBlank()) "آدرس API را در برنامه وارد کن" else "در انتظار اولین تحلیل…", style = TextStyle(fontSize = 12.sp, color = ColorProvider(android.graphics.Color.LTGRAY)))
                } else {
                    Text("🟢 LONG", style = TextStyle(fontSize = 13.sp, color = ColorProvider(android.graphics.Color.rgb(40,200,130))))
                    showRows(data.optJSONArray("long"))
                    Spacer(GlanceModifier.height(5.dp))
                    Text("🔴 SHORT", style = TextStyle(fontSize = 13.sp, color = ColorProvider(android.graphics.Color.rgb(240,90,90))))
                    showRows(data.optJSONArray("short"))
                    Spacer(GlanceModifier.height(5.dp))
                    Text("آخرین به‌روزرسانی: ${data.optString("updated_at", "-").replace('T',' ').take(16)}", style = TextStyle(fontSize = 9.sp, color = ColorProvider(android.graphics.Color.GRAY)))
                }
            }
        }
    }

    private fun androidx.glance.layout.ColumnScope.showRows(arr: org.json.JSONArray?) {
        if (arr == null || arr.length() == 0) { Text("مورد قوی پیدا نشد", style = TextStyle(fontSize = 10.sp, color = ColorProvider(android.graphics.Color.GRAY))); return }
        for (i in 0 until minOf(3, arr.length())) {
            val x = arr.getJSONObject(i)
            Row(modifier = GlanceModifier.fillMaxWidth().padding(vertical = 3.dp)) {
                Text("${x.optString("symbol")}  ${x.optInt("score")}/100", style = TextStyle(fontSize = 11.sp, color = ColorProvider(android.graphics.Color.WHITE)))
                Spacer(GlanceModifier.width(8.dp))
                Text("${fmt(x.optDouble("price"))}  → ${fmt(x.optDouble("tp1"))}", style = TextStyle(fontSize = 10.sp, color = ColorProvider(android.graphics.Color.LTGRAY)))
            }
        }
    }
    private fun fmt(v: Double): String = if (v == 0.0) "-" else when { v >= 1000 -> "%.0f".format(v); v >= 1 -> "%.2f".format(v); else -> "%.8f".format(v).trimEnd('0').trimEnd('.') }

    companion object {
        suspend fun updateAll(context: Context) = androidx.glance.appwidget.GlanceAppWidgetManager(context).getGlanceIds(CryptoWidget::class.java).forEach { CryptoWidget().update(context, it) }
        fun schedule(context: Context) {
            val req = PeriodicWorkRequestBuilder<SignalWorker>(6, TimeUnit.HOURS).build()
            WorkManager.getInstance(context).enqueueUniquePeriodicWork("crypto-signal-refresh", ExistingPeriodicWorkPolicy.UPDATE, req)
        }
    }
}

class CryptoWidgetReceiver : GlanceAppWidgetReceiver() {
    override val glanceAppWidget: GlanceAppWidget = CryptoWidget()
    override fun onEnabled(context: Context) { super.onEnabled(context); CryptoWidget.schedule(context) }
}
