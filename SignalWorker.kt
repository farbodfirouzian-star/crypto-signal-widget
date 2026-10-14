package com.cryptosignal.widget

import android.content.Context
import androidx.glance.appwidget.updateAll
import androidx.work.CoroutineWorker
import androidx.work.WorkerParameters
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext

class SignalWorker(appContext: Context, params: WorkerParameters) : CoroutineWorker(appContext, params) {
    override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
        return@withContext try {
            SignalStore.fetch(applicationContext)
            CryptoWidget.updateAll(applicationContext)
            Result.success()
        } catch (_: Exception) { Result.retry() }
    }
}
