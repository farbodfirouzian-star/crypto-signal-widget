<?php
declare(strict_types=1);

// Crypto Signal Web — PHP 8+, no Composer required.
// Optional AI keys are read from config.php or environment variables.

function envv(string $key, string $default = ''): string {
    $v = getenv($key);
    return $v === false ? $default : $v;
}

$config = [
    'scan_symbols' => max(10, (int)envv('SCAN_SYMBOLS', '80')),
    'ai_top' => max(0, (int)envv('AI_TOP_CANDIDATES', '12')),
    'timeout' => max(5, (int)envv('HTTP_TIMEOUT', '12')),
    'openai_key' => envv('OPENAI_API_KEY'),
    'openai_model' => envv('OPENAI_MODEL', 'gpt-5.6-mini'),
    'anthropic_key' => envv('ANTHROPIC_API_KEY'),
    'anthropic_model' => envv('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
    'gemini_key' => envv('GEMINI_API_KEY'),
    'gemini_model' => envv('GEMINI_MODEL', 'gemini-2.5-flash'),
    'xai_key' => envv('XAI_API_KEY'),
    'xai_model' => envv('XAI_MODEL', 'grok-4-1-fast-reasoning'),
];

// Shared-hosting fallback: create config.php from config.example.php and fill secrets.
if (is_file(__DIR__ . '/config.php')) {
    $local = require __DIR__ . '/config.php';
    if (is_array($local)) $config = array_merge($config, $local);
}

function http_json(string $url, array $headers = [], ?array $query = null): ?array {
    global $config;
    if ($query) $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: CryptoSignalWeb/1.0'], $headers),
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) return null;
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

function binanceSymbols(int $limit): array {
    $d = http_json('https://api.binance.com/api/v3/ticker/24hr');
    if (!$d) return [];
    $rows=[];
    foreach ($d as $x) {
        $s=$x['symbol']??''; $q=(float)($x['quoteVolume']??0);
        if (str_ends_with($s,'USDT') && $q>0 && !str_ends_with($s,'USDCUSDT') && !str_ends_with($s,'BUSDUSDT')) $rows[]=[$s,$q];
    }
    usort($rows, fn($a,$b)=>$b[1]<=>$a[1]);
    return array_slice(array_column($rows,0),0,$limit);
}

function klines(string $exchange,string $symbol,string $tf): ?array {
    if ($exchange==='binance') {
        $d=http_json('https://api.binance.com/api/v3/klines',[],['symbol'=>$symbol,'interval'=>$tf,'limit'=>120]);
        if (!$d) return null;
        return array_map(fn($x)=>['open'=>(float)$x[1],'high'=>(float)$x[2],'low'=>(float)$x[3],'close'=>(float)$x[4],'volume'=>(float)$x[5]],$d);
    }
    if ($exchange==='bybit') {
        $iv=['15m'=>'15','1h'=>'60','4h'=>'240','1d'=>'D'][$tf];
        $d=http_json('https://api.bybit.com/v5/market/kline',[],['category'=>'linear','symbol'=>$symbol,'interval'=>$iv,'limit'=>120]);
        if (!$d || empty($d['result']['list'])) return null;
        $rows=array_reverse($d['result']['list']);
        return array_map(fn($x)=>['open'=>(float)$x[1],'high'=>(float)$x[2],'low'=>(float)$x[3],'close'=>(float)$x[4],'volume'=>(float)$x[5]],$rows);
    }
    if ($exchange==='okx') {
        $bar=['15m'=>'15m','1h'=>'1H','4h'=>'4H','1d'=>'1D'][$tf];
        $inst=preg_replace('/USDT$/','-USDT-SWAP',$symbol);
        $d=http_json('https://www.okx.com/api/v5/market/candles',[],['instId'=>$inst,'bar'=>$bar,'limit'=>120]);
        if (!$d || empty($d['data'])) return null;
        $rows=array_reverse($d['data']);
        return array_map(fn($x)=>['open'=>(float)$x[1],'high'=>(float)$x[2],'low'=>(float)$x[3],'close'=>(float)$x[4],'volume'=>(float)$x[5]],$rows);
    }
    return null;
}

function ema(array $s,int $n): array { $a=2/($n+1); $out=[]; $prev=null; foreach($s as $v){$prev=$prev===null?$v:($a*$v+(1-$a)*$prev);$out[]=$prev;} return $out; }
function rsi(array $s,int $n=14): array {
    $out=array_fill(0,count($s),50.0); if(count($s)<2)return $out;
    $avgG=0.0;$avgL=0.0;$alpha=1/$n;
    for($i=1;$i<count($s);$i++){$d=$s[$i]-$s[$i-1];$g=max(0,$d);$l=max(0,-$d);$avgG=$alpha*$g+(1-$alpha)*$avgG;$avgL=$alpha*$l+(1-$alpha)*$avgL;$out[$i]=$avgL==0?100:100-(100/(1+$avgG/$avgL));}
    return $out;
}
function atr(array $rows,int $n=14): array {
    $trs=[];$out=array_fill(0,count($rows),0.0);$prev=null;
    foreach($rows as $i=>$r){$tr=$prev===null?($r['high']-$r['low']):max($r['high']-$r['low'],abs($r['high']-$prev),abs($r['low']-$prev));$trs[]=$tr;$prev=$r['close'];}
    $alpha=1/$n;$p=null;foreach($trs as $i=>$v){$p=$p===null?$v:$alpha*$v+(1-$alpha)*$p;$out[$i]=$p;}return $out;
}
function analyze(array $rows): ?array {
    if(count($rows)<60)return null;$cl=array_column($rows,'close');$vol=array_column($rows,'volume');$e20=ema($cl,20);$e50=ema($cl,50);$rr=rsi($cl);$aa=atr($rows);$i=count($rows)-1;$vm=array_sum(array_slice($vol,max(0,$i-19),20))/min(20,count($vol));
    return ['price'=>$cl[$i],'ema20'=>$e20[$i],'ema50'=>$e50[$i],'rsi'=>$rr[$i],'atr'=>$aa[$i],'vol_ratio'=>$vm?($vol[$i]/$vm):1.0];
}
function scoreTf(array $a): array { $L=50;$S=50;if($a['ema20']>$a['ema50']){$L+=14;$S-=14;}else{$S+=14;$L-=14;}if($a['price']>$a['ema20']){$L+=8;$S-=8;}else{$S+=8;$L-=8;}if($a['rsi']>=52&&$a['rsi']<=68)$L+=10;if($a['rsi']>=32&&$a['rsi']<=48)$S+=10;if($a['vol_ratio']>=1.3){if($a['price']>=$a['ema20'])$L+=5;else $S+=5;}return [max(0,min(100,(int)$L)),max(0,min(100,(int)$S))]; }

function technicalScan(string $symbol): ?array {
    $tfs=['15m','1h','4h','1d'];$exs=['binance','bybit','okx'];$res=[];
    foreach($tfs as $tf){foreach($exs as $ex){$rows=klines($ex,$symbol,$tf);$a=$rows?analyze($rows):null;if($a){[$l,$s]=scoreTf($a);$res[]=['tf'=>$tf,'ex'=>$ex,'a'=>$a,'l'=>$l,'s'=>$s];}}}
    if(!$res)return null;$ls=[];$ss=[];foreach($tfs as $tf){$x=array_values(array_filter($res,fn($z)=>$z['tf']===$tf));if($x){$ls[]=array_sum(array_column($x,'l'))/count($x);$ss[]=array_sum(array_column($x,'s'))/count($x);}}
    if(!$ls)return null;$L=array_sum($ls)/count($ls);$S=array_sum($ss)/count($ss);$latest=null;foreach($res as $z)if($z['tf']==='15m'&&$z['ex']==='binance'){$latest=$z['a'];break;}if(!$latest)$latest=$res[0]['a'];
    $side=$L>=$S?'LONG':'SHORT';$score=(int)max($L,$S);$ai=['vote'=>'WAIT','confidence'=>0,'agreement'=>0,'models'=>[]];
    return ['symbol'=>$symbol,'side'=>$side,'score'=>$score,'price'=>$latest['price'],'atr'=>$latest['atr'],'sl'=>0,'tps'=>[0,0,0],'technical_long'=>(int)round($L),'technical_short'=>(int)round($S),'ai'=>$ai,'results'=>$res];
}

function aiPrompt(array $x): string {
    $tf=[];foreach($x['results'] as $z){$tf[$z['tf']][]=['exchange'=>$z['ex'],'price'=>$z['a']['price'],'ema20'=>$z['a']['ema20'],'ema50'=>$z['a']['ema50'],'rsi'=>$z['a']['rsi'],'atr'=>$z['a']['atr'],'vol_ratio'=>$z['a']['vol_ratio']];}
    return 'You are a disciplined crypto market analyst. Return ONLY JSON: {"vote":"LONG|SHORT|WAIT","confidence":0-100,"reason":"short"}. Do not invent data. Snapshot: '.json_encode(['symbol'=>$x['symbol'],'price'=>$x['price'],'technical_long'=>$x['technical_long'],'technical_short'=>$x['technical_short'],'timeframes'=>$tf],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
function parseAi(string $txt): ?array { $txt=trim($txt);$txt=preg_replace('/^```(?:json)?\s*|\s*```$/i','',$txt);$d=json_decode($txt,true);if(!is_array($d))return null;$v=strtoupper((string)($d['vote']??'WAIT'));if(!in_array($v,['LONG','SHORT','WAIT'],true))$v='WAIT';return ['vote'=>$v,'confidence'=>max(0,min(100,(int)($d['confidence']??0))),'reason'=>substr((string)($d['reason']??''),0,240)];}
function aiCall(string $model,array $x): ?array { global $config;$prompt=aiPrompt($x);$key='';$url='';$headers=['Content-Type: application/json'];$payload=[];
    if($model==='OpenAI'&&$config['openai_key']){$key=$config['openai_key'];$url='https://api.openai.com/v1/responses';$headers[]='Authorization: Bearer '.$key;$payload=['model'=>$config['openai_model'],'input'=>[['role'=>'user','content'=>$prompt]],'text'=>['format'=>['type'=>'json_object']]];}
    elseif($model==='Claude'&&$config['anthropic_key']){$key=$config['anthropic_key'];$url='https://api.anthropic.com/v1/messages';$headers=['Content-Type: application/json','x-api-key: '.$key,'anthropic-version: 2023-06-01'];$payload=['model'=>$config['anthropic_model'],'max_tokens'=>300,'system'=>'Return only JSON with vote, confidence, reason.','messages'=>[['role'=>'user','content'=>$prompt]]];}
    elseif($model==='Gemini'&&$config['gemini_key']){$url='https://generativelanguage.googleapis.com/v1beta/models/'.$config['gemini_model'].':generateContent?key='.rawurlencode($config['gemini_key']);$payload=['systemInstruction'=>['parts'=>[['text'=>'Return only JSON with vote, confidence, reason.']]],'contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['responseMimeType'=>'application/json']];}
    elseif($model==='Grok'&&$config['xai_key']){$key=$config['xai_key'];$url='https://api.x.ai/v1/responses';$headers[]='Authorization: Bearer '.$key;$payload=['model'=>$config['xai_model'],'input'=>[['role'=>'user','content'=>$prompt]]];}
    else return null;
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($payload),CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>8,CURLOPT_TIMEOUT=>25]);$body=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if($body===false||$code<200||$code>=300)return null;$d=json_decode($body,true);if(!is_array($d))return null;
    $txt='';if($model==='OpenAI'||$model==='Grok')$txt=(string)($d['output_text']??'');elseif($model==='Claude'){foreach(($d['content']??[]) as $p)if(($p['type']??'')==='text')$txt.=$p['text'];}else $txt=(string)($d['candidates'][0]['content']['parts'][0]['text']??'');return parseAi($txt);
}
function consensus(array $x): array { $rows=[];foreach(['OpenAI','Claude','Gemini','Grok'] as $m){$r=aiCall($m,$x);if($r)$rows[]=['model'=>$m]+$r;}if(!$rows)return ['vote'=>'WAIT','confidence'=>0,'agreement'=>0,'models'=>[]];$weights=['OpenAI'=>1,'Claude'=>1,'Gemini'=>.9,'Grok'=>.9];$tot=['LONG'=>0,'SHORT'=>0,'WAIT'=>0];foreach($rows as $r)$tot[$r['vote']]+=$weights[$r['model']]*$r['confidence'];arsort($tot);$vote=array_key_first($tot);$same=count(array_filter($rows,fn($r)=>$r['vote']===$vote));$conf=0;$w=0;foreach($rows as $r)if($r['vote']===$vote){$conf+=$r['confidence']*$weights[$r['model']];$w+=$weights[$r['model']];}return ['vote'=>$vote,'confidence'=>(int)round($conf/max($w,1)),'agreement'=>(int)round(100*$same/count($rows)),'models'=>$rows];}
function enrich(array $x): array { global $config;$ai=consensus($x);$x['ai']=$ai;if($ai['models']&&$ai['vote']!=='WAIT'&&$ai['agreement']>=50){$x['side']=$ai['vote'];$x['score']=(int)round(.55*$x['score']+.45*$ai['confidence']);}elseif($ai['models']&&$ai['vote']==='WAIT')$x['score']=(int)round($x['score']*.75);$x['score']=max(0,min(100,$x['score']));$p=$x['price'];$a=$x['atr'];if($x['side']==='LONG'){$x['sl']=$p-1.5*$a;$x['tps']=[$p+2*$a,$p+3*$a,$p+4*$a];}else{$x['sl']=$p+1.5*$a;$x['tps']=[$p-2*$a,$p-3*$a,$p-4*$a];}return $x;}
function runScan(): array { global $config;$symbols=binanceSymbols($config['scan_symbols']);$raw=[];foreach($symbols as $s){$x=technicalScan($s);if($x)$raw[]=$x;}usort($raw,fn($a,$b)=>max($b['technical_long'],$b['technical_short'])<=>max($a['technical_long'],$a['technical_short']));$top=array_slice($raw,0,$config['ai_top']);$topMap=[];foreach($top as $x)$topMap[$x['symbol']]=enrich($x);foreach($raw as &$x){if(isset($topMap[$x['symbol']]))$x=$topMap[$x['symbol']];else{$p=$x['price'];$a=$x['atr'];if($x['side']==='LONG'){$x['sl']=$p-1.5*$a;$x['tps']=[$p+2*$a,$p+3*$a,$p+4*$a];}else{$x['sl']=$p+1.5*$a;$x['tps']=[$p-2*$a,$p-3*$a,$p-4*$a];}}}unset($x);usort($raw,fn($a,$b)=>$b['score']<=>$a['score']);return $raw;}
function fmt(float $v): string { return rtrim(rtrim(sprintf('%.8f',$v),'0'),'.'); }

$action=$_GET['action']??'scan';$error='';$data=[];$started=microtime(true);
try { if($action==='scan'||isset($_GET['refresh'])) $data=runScan(); else $data=[]; } catch(Throwable $e){$error=$e->getMessage();}
if (isset($_GET['api']) && $_GET['api'] === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $slim = function(array $x): array {
        return [
            'symbol' => $x['symbol'], 'side' => $x['side'], 'score' => $x['score'],
            'price' => $x['price'], 'sl' => $x['sl'], 'tp1' => $x['tps'][0], 'tp2' => $x['tps'][1],
            'technical_long' => $x['technical_long'], 'technical_short' => $x['technical_short'],
            'ai_vote' => $x['ai']['vote'] ?? 'WAIT', 'ai_confidence' => $x['ai']['confidence'] ?? 0,
            'ai_agreement' => $x['ai']['agreement'] ?? 0,
            'reason' => (string)($x['ai']['models'][0]['reason'] ?? '')
        ];
    };
    $apiLong = array_values(array_map($slim, array_slice(array_values(array_filter($data, fn($x)=>$x['side']==='LONG' && $x['score']>=70)), 0, 3)));
    $apiShort = array_values(array_map($slim, array_slice(array_values(array_filter($data, fn($x)=>$x['side']==='SHORT' && $x['score']>=70)), 0, 3)));
    echo json_encode(['ok'=>true,'updated_at'=>date('c'),'long'=>$apiLong,'short'=>$apiShort], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}
$long=array_values(array_filter($data,fn($x)=>$x['side']==='LONG'&&$x['score']>=70));$short=array_values(array_filter($data,fn($x)=>$x['side']==='SHORT'&&$x['score']>=70));
$long=array_slice($long,0,5);$short=array_slice($short,0,5);$elapsed=round(microtime(true)-$started,1);
function cardHtml(array $x): string { $ai=$x['ai'];$aiText=$ai['models']?'AI '.$ai['vote'].' '.$ai['confidence'].'% | توافق '.$ai['agreement'].'% | '.count($ai['models']).' مدل':'AI فعال نیست';$cls=$x['side']==='LONG'?'long':'short';$icon=$x['side']==='LONG'?'▲':'▼';return '<div class="card '.$cls.'"><div class="top"><b>'.$icon.' '.htmlspecialchars($x['symbol']).'</b><span class="badge">'.$x['score'].'/100</span></div><div class="price">Entry <b>'.fmt($x['price']).'</b></div><div class="grid"><span>SL<br><b>'.fmt($x['sl']).'</b></span><span>TP1<br><b>'.fmt($x['tps'][0]).'</b></span><span>TP2<br><b>'.fmt($x['tps'][1]).'</b></span><span>TP3<br><b>'.fmt($x['tps'][2]).'</b></span></div><div class="meta">TF: L'.$x['technical_long'].' / S'.$x['technical_short'].' · '.$aiText.'</div></div>'; }
?><!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="900"><title>Crypto Signal AI</title><style>
*{box-sizing:border-box}body{margin:0;background:#0b0f14;color:#eef2f7;font-family:Tahoma,Arial,sans-serif}.wrap{max-width:1100px;margin:auto;padding:20px}.head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:18px}.brand h1{margin:0;font-size:24px}.brand p{margin:6px 0 0;color:#8d99a8;font-size:13px}.btn{background:#7c3aed;color:white;text-decoration:none;border-radius:10px;padding:11px 16px;font-weight:bold}.status{background:#111820;border:1px solid #26303b;padding:12px 14px;border-radius:12px;margin-bottom:18px;color:#aeb8c5}.cols{display:grid;grid-template-columns:1fr 1fr;gap:18px}.section{background:#10161e;border:1px solid #202a35;border-radius:16px;padding:14px}.section h2{font-size:18px;margin:4px 0 12px}.card{background:#0c1219;border:1px solid #27313d;border-right:4px solid #64748b;border-radius:13px;padding:14px;margin:10px 0}.card.long{border-right-color:#19c37d}.card.short{border-right-color:#ef4444}.top{display:flex;justify-content:space-between;align-items:center}.top b{font-size:17px}.badge{padding:5px 9px;border-radius:8px;background:#202938;font-size:12px}.price{margin:12px 0;color:#aeb8c5}.grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px}.grid span{background:#141c25;border-radius:9px;padding:9px;font-size:11px;color:#8995a3}.grid b{display:block;color:#eef2f7;margin-top:4px;font-size:12px;direction:ltr;text-align:center}.meta{margin-top:11px;color:#7f8b99;font-size:11px;line-height:1.8}.empty{color:#7f8b99;padding:16px;text-align:center}.foot{margin-top:16px;color:#667281;font-size:11px;line-height:1.8}@media(max-width:760px){.cols{grid-template-columns:1fr}.head{align-items:flex-start}.grid{grid-template-columns:repeat(2,1fr)}}
</style></head><body><div class="wrap"><div class="head"><div class="brand"><h1>🤖 Crypto Signal AI</h1><p>اسکن چندصرافی · چندتایم‌فریم · تحلیل تکنیکال + اجماع AI</p></div><a class="btn" href="?refresh=1">🔄 اسکن جدید</a></div><div class="status">آخرین اسکن: '.date('Y-m-d H:i:s').' · زمان اجرا: '.$elapsed.' ثانیه · '.count($data).' ارز بررسی شد</div>'.($error?'<div class="status" style="color:#ff8b8b">خطا: '.htmlspecialchars($error).'</div>':'').'<div class="cols"><section class="section"><h2>🟢 بهترین LONG ها</h2>'.($long?implode('',array_map('cardHtml',$long)):'<div class="empty">سیگنال لانگ با امتیاز ۷۰+ پیدا نشد.</div>').'</section><section class="section"><h2>🔴 بهترین SHORT ها</h2>'.($short?implode('',array_map('cardHtml',$short)):'<div class="empty">سیگنال شورت با امتیاز ۷۰+ پیدا نشد.</div>').'</section></div><div class="foot">این صفحه برای تحلیل و رتبه‌بندی بازار است و سود یا نتیجه معامله را تضمین نمی‌کند. داده‌های بازار از Binance، Bybit و OKX گرفته می‌شوند. اگر کلیدهای AI تنظیم شوند، اجماع OpenAI / Claude / Gemini / Grok نیز نمایش داده می‌شود.</div></div></body></html>
