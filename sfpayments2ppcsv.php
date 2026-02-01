<?php
/**
 * Suica SF payments history -> PayPay CSV converter for MoneyForward (PHP 8.4)
 *
 * Usage:
 *   php sfpayments2ppcsv.php --in=load.txt --out=save.csv [--expense-only]
 *
 * Options:
 *   --in=FILE           input TSV file (default: ./load.txt)
 *   --out=FILE          output CSV base file (default: ./save.csv)
 *   --expense-only      export only negative amounts (charges are skipped)
 *
 * Split rule:
 *   If total lines (including header) would exceed 100, split into multiple files:
 *   save_001.csv, save_002.csv, ...
 *   Each file has BOM + header.
 */

mb_internal_encoding('UTF-8');
date_default_timezone_set('Asia/Tokyo');

$options = getopt('', ['in::', 'out::', 'expense-only']);
$inPath  = $options['in']  ?? __DIR__ . '/load.txt';
$outPath = $options['out'] ?? __DIR__ . '/save.csv';
$expenseOnly = array_key_exists('expense-only', $options);

// 1ファイル最大行数（ヘッダ含む）
const MAX_LINES_PER_FILE = 100;

if (!is_file($inPath)) {
    fwrite(STDERR, "Input file not found: {$inPath}\n");
    exit(1);
}

$raw = file_get_contents($inPath);
if ($raw === false) {
    fwrite(STDERR, "Failed to read input: {$inPath}\n");
    exit(1);
}

// Try to handle SJIS/CP932 etc.
$enc = mb_detect_encoding($raw, ['UTF-8', 'SJIS-win', 'SJIS', 'CP932', 'EUC-JP', 'ISO-2022-JP'], true);
if ($enc === false) $enc = 'UTF-8';
$tsv = mb_convert_encoding($raw, 'UTF-8', $enc);

// Normalize newlines
$tsv = str_replace(["\r\n", "\r"], "\n", $tsv);
$lines = array_values(array_filter(explode("\n", $tsv), fn($l) => trim($l) !== ''));

if (count($lines) <= 1) {
    fwrite(STDERR, "No data lines found.\n");
    exit(1);
}

/**
 * 最小クォートのCSVを生成する
 * - フィールドに , " \n \r が含まれる場合のみ "..." で囲む
 * - " は "" にエスケープ
 */
$csvLine = function(array $fields): string {
    $outFields = [];
    foreach ($fields as $v) {
        $s = (string)$v;
        $needQuote = (str_contains($s, ',') || str_contains($s, "\n") || str_contains($s, "\r") || str_contains($s, '"'));
        if ($needQuote) {
            $s = str_replace('"', '""', $s);
            $s = '"' . $s . '"';
        }
        $outFields[] = $s;
    }
    return implode(',', $outFields) . "\r\n";
};

/**
 * Trim including full-width spaces.
 */
$trimWide = function(string $s): string {
    $s = preg_replace('/^[\s\x{3000}]+|[\s\x{3000}]+$/u', '', $s);
    return $s ?? '';
};

/**
 * 年の自動判定:
 * - 基本は現在年
 * - {月日} が「今日より未来」なら前年扱い
 */
$resolveYearForMonthDay = function(int $mm, int $dd): int {
    $now = new DateTime('now');
    $currentYear = (int)$now->format('Y');

    $candidate = new DateTime(sprintf('%04d-%02d-%02d 00:00:00', $currentYear, $mm, $dd));
    $today0 = new DateTime($now->format('Y-m-d') . ' 00:00:00');

    return ($candidate > $today0) ? ($currentYear - 1) : $currentYear;
};

/**
 * Parse amount like "+1,000" "-389" "" -> int|null
 */
$parseAmount = function(string $s) use ($trimWide): ?int {
    $s = $trimWide($s);
    if ($s === '') return null;
    $s = str_replace(['\\', '￥', ',', ' '], '', $s);
    if (!preg_match('/^[+-]?\d+$/', $s)) return null;
    return (int)$s;
};

/**
 * 金額をMoneyForward向けに整形
 * - 4桁以上: 3桁区切り（例: 1,234）→ カンマ含むのでCSV側でクォートされる
 * - 3桁以下: そのまま（例: 999）
 */
$fmtAmount = function(int $n): string {
    return ($n >= 1000) ? number_format($n) : (string)$n;
};

/**
 * Build Payee by joining tokens with single ASCII space, skipping empties.
 */
$buildPayee = function(array $tokens) use ($trimWide): string {
    $clean = [];
    foreach ($tokens as $t) {
        $t = $trimWide((string)$t);
        if ($t !== '') $clean[] = $t;
    }
    return implode(' ', $clean);
};

$header = [
    '取引日',
    '出金金額（円）',
    '入金金額（円）',
    '海外出金金額',
    '通貨',
    '変換レート（円）',
    '利用国',
    '取引内容',
    '取引先',
    '取引方法',
    '支払い区分',
    '利用者',
    '取引番号',
];

/**
 * 出力ファイル名を連番付きで生成
 * save.csv -> save_001.csv
 */
$makeOutName = function(string $basePath, int $seq): string {
    $dir = dirname($basePath);
    $base = basename($basePath);

    $dot = strrpos($base, '.');
    if ($dot === false) {
        $name = $base;
        $ext  = '';
    } else {
        $name = substr($base, 0, $dot);
        $ext  = substr($base, $dot); // includes "."
    }

    return $dir . DIRECTORY_SEPARATOR . sprintf('%s_%03d%s', $name, $seq, $ext);
};

// 同一日の取引時刻を1分ずつ進めるためのカウンタ（出力した行だけカウント）
$dayMinuteCounter = []; // ['YYYYMMDD' => int]

// 出力ファイル制御
$seq = 1;
$linesInCurrentFile = 0; // ヘッダも含めた行数
$out = null;

$openNewFile = function() use (
    &$out, &$linesInCurrentFile, &$seq,
    $makeOutName, $outPath, $csvLine, $header
): void {
    if (is_resource($out)) {
        fclose($out);
    }
    $filePath = $makeOutName($outPath, $seq);
    $seq++;

    $out = fopen($filePath, 'wb');
    if ($out === false) {
        fwrite(STDERR, "Failed to open output: {$filePath}\n");
        exit(1);
    }

    // UTF-8 BOM
    fwrite($out, "\xEF\xBB\xBF");
    // Header
    fwrite($out, $csvLine($header));
    $linesInCurrentFile = 1; // header line count

    fwrite(STDOUT, "Writing: {$filePath}\n");
};

// 最初のファイルを開く
$openNewFile();

$idx = 0; // 取引番号用の通しインデックス（出力行のみ加算）

// Skip header line of TSV
for ($i = 1; $i < count($lines); $i++) {
    $line = $lines[$i];
    $cols = explode("\t", $line);
    $cols = array_pad($cols, 7, '');

    [$md, $type1, $place1, $type2, $place2, $balance, $amountRaw] = $cols;

    $type1 = $trimWide($type1);

    $amount = $parseAmount($amountRaw);
    if ($amount === null || $amount === 0) {
        continue;
    }

    // ★ 追加：マイナスのみ抽出
    if ($expenseOnly && $amount > 0) {
        continue;
    }

    $md = $trimWide($md);

    // date parse: "01/24"
    if (!preg_match('#^(\d{1,2})/(\d{1,2})$#', $md, $m)) {
        continue;
    }
    $mm = str_pad($m[1], 2, '0', STR_PAD_LEFT);
    $dd = str_pad($m[2], 2, '0', STR_PAD_LEFT);

    // 自動年判定（未来日付なら前年）
    $autoYear = $resolveYearForMonthDay((int)$mm, (int)$dd);

    // YYYYMMDD（この後、カウンタキー・取引番号にも使う）
    $ymd = sprintf('%04d%02d%02d', $autoYear, (int)$mm, (int)$dd);

    // 同一日: 10:00:00 を基準に「出力行ごとに -N分」
    if (!isset($dayMinuteCounter[$ymd])) {
        $dayMinuteCounter[$ymd] = 0;
    }
    $offsetMinutes = $dayMinuteCounter[$ymd];
    $dayMinuteCounter[$ymd]++;

    // 10:00:00 から N分引く（60分超は自動で繰り下げ）
    $dt = new DateTime(sprintf('%04d-%02d-%02d 10:00:00', $autoYear, (int)$mm, (int)$dd));
    $dt->modify("-{$offsetMinutes} minutes");

    $tradeDate = $dt->format('Y/m/d H:i:s');

    // {取引先}
    $payee = $buildPayee([$type1, $place1, $type2, $place2]);

    // 金額振り分け
    $isExpense = ($amount < 0);
    $abs = abs($amount);

    $withdraw = $isExpense ? $fmtAmount($abs) : '-';
    $deposit  = !$isExpense ? $fmtAmount($abs) : '-';

    // 区分
    if ($isExpense) {
        $method = 'Suica';
        $content = '支払い';
        $user    = '本人';
    } else {
        $user    = '-';
        $content = 'チャージ';
        if ($type1 === '現金') {
            $method = '財布';
        } elseif ($type1 === 'ｵｰﾄ') {
            $method = 'VIEWカード';
        } else {
            $method = 'カード';
        }
    }

    // {取引番号}: ハイフン無し（YYYYMMDD + index + rand）
    $idx++;
    $rand = bin2hex(random_bytes(4)); // 8 hex
    $tradeNo = $ymd . str_pad((string)$idx, 4, '0', STR_PAD_LEFT) . $rand; // NO hyphen

    $row = [
        $tradeDate,
        $withdraw,
        $deposit,
        '-', // 海外出金金額
        '-', // 通貨
        '-', // 変換レート（円）
        '-', // 利用国
        $content,
        $payee,
        $method,
        '-', // 支払い区分
        $user,
        $tradeNo,
    ];

    // 分割判定：次行を書いたら100行超えるなら新ファイルへ
    if ($linesInCurrentFile + 1 > MAX_LINES_PER_FILE) {
        $openNewFile();
    }

    fwrite($out, $csvLine($row));
    $linesInCurrentFile++;
}

// 最後に閉じる
if (is_resource($out)) {
    fclose($out);
}

fwrite(STDOUT, "OK\n");
