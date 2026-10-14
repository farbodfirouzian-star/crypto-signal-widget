# CryptoSignal AI Widget v7

ویجت اندروید برای نمایش روزانه بهترین LONG و SHORTها با داده Binance/Bybit/OKX و موتور تحلیل تکنیکال؛ در صورت تنظیم کلیدهای AI در backend، اجماع OpenAI/Claude/Gemini/Grok نیز وارد امتیاز می‌شود.

## نصب با فقط گوشی
1. این پروژه را در یک GitHub Repository آپلود کن.
2. GitHub Actions خودکار APK Debug را می‌سازد.
3. در Actions → Build Android APK → Run workflow.
4. Artifact با نام `CryptoSignalWidget-debug-apk` را دانلود و APK را نصب کن.

## Backend
محتویات پوشه `backend` را روی هاست PHP 8.1+ آپلود کن. سپس در اپ آدرس زیر را وارد کن:
`https://YOUR-DOMAIN/path/index.php?api=1`

کلیدهای AI فقط روی backend قرار می‌گیرند و نباید داخل APK باشند.
