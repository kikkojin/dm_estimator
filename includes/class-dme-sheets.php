<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Googleスプレッドシートから公開データを取得・正規化するクラス。
 */
class DME_Sheets
{
    /**
     * 参照するスプレッドシートID一覧。
     * キーはシステム内の種別キー。
     */
    const SHEET_BOOKS = [
        'a4_offset' => '1FYBMdP2KGsnwvFHrghbnag8VQiY7Cm88S34av_YR2y0',
        'envelope_print' => '11bYdJlCXIxFiiYv7ZvQ7G0hsf1__1rexb4VnbtHxtgk',
        'booklet' => '1lJH6a_y54qtyAhk0a5pfESa8esYDj7yYed71hB5EzgA',
        'leaflet' => '1mmAl1kyBUPM_FgozIeIU-bfqBkalkLBVQqINNe2APIA',
        'pressure_dm' => '1aVMLpbXPjfay6eNWSilua5vwui26NeA0vafJVoUVMR8',
        'postcard' => '1Lvc1XwdrCX1obxPsHiGfhwegK4iAekPzXAcnnW1BSVY',
        'dm_work' => '1LwT3hGk5f1T-FFLlv7mxfJkHS2mxVTN-stW__5mxOn4',
        'paper_weight' => '1aGhlED9eQy_QdDk5pMFKs7GR2Lz7yJABzZCGi49Bnjw',
        'postage' => '18eAZhTocuD45Rq2LoL4cRUnJVKRLq6Bl0WRE3AAllTM',
    ];

    /**
     * ブックごとの固定シート名（必要に応じて filter で上書き）。
     *
     * @var array
     */
    const SHEET_TITLES = [];

    /**
     * フロント向けカタログを返す。
     *
     * @return array
     */
    public static function build_front_catalog()
    {
        $all = self::get_all_books();

        $print_books = ['a4_offset', 'envelope_print', 'booklet', 'leaflet', 'pressure_dm', 'postcard'];
        $print_catalog = [];

        foreach ($print_books as $book_key) {
            if (empty($all[$book_key]['sheets'])) {
                continue;
            }
            foreach ($all[$book_key]['sheets'] as $sheet_data) {
                $print_catalog[] = [
                    'bookKey' => $book_key,
                    'bookLabel' => $all[$book_key]['label'],
                    'sheetName' => $sheet_data['sheet_name'],
                    'conditions' => $sheet_data['conditions'],
                    'headers' => $sheet_data['headers'],
                    'matrix' => $sheet_data['matrix'],
                    'quantities' => $sheet_data['quantities'],
                ];
            }
        }

        $catalog = [
            'printCatalog' => $print_catalog,
            'workFees' => isset($all['dm_work']) ? $all['dm_work']['sheets'] : [],
            'paperWeights' => isset($all['paper_weight']) ? $all['paper_weight']['sheets'] : [],
            'postage' => isset($all['postage']) ? $all['postage']['sheets'] : [],
            'updatedAt' => gmdate('c'),
        ];

        self::debug_log('build_front_catalog result summary', [
            'printCatalog_count' => count($catalog['printCatalog']),
            'workFees_count' => count($catalog['workFees']),
            'paperWeights_count' => count($catalog['paperWeights']),
            'postage_count' => count($catalog['postage']),
        ]);

        return $catalog;
    }

    /**
     * 全ブック読み込み。
     *
     * @return array
     */
    public static function get_all_books()
    {
        $cache_key = 'dme_sheet_books_v2';
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            self::debug_log('Cache hit');
            return $cached;
        }
        self::debug_log('Cache miss; loading sheets');

        $output = [];
        foreach (self::SHEET_BOOKS as $book_key => $spreadsheet_id) {
            $output[$book_key] = [
                'label' => $book_key,
                'sheets' => self::load_book_sheets($spreadsheet_id, $book_key),
            ];
        }

        set_transient($cache_key, $output, 30 * MINUTE_IN_SECONDS);
        return $output;
    }

    /**
     * ブック内シート一覧と内容取得。
     *
     * @param string $spreadsheet_id スプレッドシートID。
     * @param string $book_key       ブックキー。
     * @return array
     */
    private static function load_book_sheets($spreadsheet_id, $book_key = '')
    {
        $worksheets_url = sprintf(
            'https://spreadsheets.google.com/feeds/worksheets/%s/public/full?alt=json',
            rawurlencode($spreadsheet_id)
        );
        self::debug_log('Worksheet discovery request', [
            'book_key' => $book_key,
            'spreadsheet_id' => $spreadsheet_id,
            'worksheets_url' => $worksheets_url,
        ]);

        $worksheets_res = wp_remote_get($worksheets_url, ['timeout' => 15]);
        if (is_wp_error($worksheets_res)) {
            self::debug_log('Worksheet discovery failed', [
                'book_key' => $book_key,
                'spreadsheet_id' => $spreadsheet_id,
                'worksheets_url' => $worksheets_url,
                'error' => $worksheets_res->get_error_message(),
            ]);
        } else {
            $worksheets_status = (int) wp_remote_retrieve_response_code($worksheets_res);
            $worksheets_body = (string) wp_remote_retrieve_body($worksheets_res);
            $worksheets_json = json_decode($worksheets_body, true);
            $has_feed_entry = is_array($worksheets_json)
                && !empty($worksheets_json['feed']['entry'])
                && is_array($worksheets_json['feed']['entry']);
            $feed_sheet_names = [];
            if ($has_feed_entry) {
                foreach ($worksheets_json['feed']['entry'] as $entry) {
                    if (!empty($entry['title']['$t'])) {
                        $feed_sheet_names[] = (string) $entry['title']['$t'];
                    }
                }
            }

            self::debug_log('Worksheet discovery response', [
                'book_key' => $book_key,
                'spreadsheet_id' => $spreadsheet_id,
                'worksheets_url' => $worksheets_url,
                'http_status' => $worksheets_status,
                'body_head_500' => mb_substr($worksheets_body, 0, 500),
                'has_feed_entry' => $has_feed_entry,
                'sheet_names_from_feed' => $feed_sheet_names,
            ]);
        }

        $sheet_names = self::get_sheet_names($spreadsheet_id);
        self::debug_log('Resolved sheet names', [
            'book_key' => $book_key,
            'spreadsheet_id' => $spreadsheet_id,
            'sheet_names' => $sheet_names,
        ]);
        if (empty($sheet_names)) {
            self::debug_log('No sheet names discovered', [
                'book_key' => $book_key,
                'spreadsheet_id' => $spreadsheet_id,
            ]);
            return [];
        }

        $sheets = [];
        $fetched_sheet_names = [];
        $normalized_count = 0;
        foreach ($sheet_names as $sheet_name) {
            $sheet_name = trim((string) $sheet_name);
            if ($sheet_name === '') {
                continue;
            }
            $fetched_sheet_names[] = $sheet_name;

            $csv_url = sprintf(
                'https://docs.google.com/spreadsheets/d/%s/gviz/tq?tqx=out:csv&sheet=%s',
                rawurlencode($spreadsheet_id),
                rawurlencode($sheet_name)
            );
            self::debug_log('CSV request URL', [
                'book_key' => $book_key,
                'spreadsheet_id' => $spreadsheet_id,
                'sheet_name' => $sheet_name,
                'csv_url' => $csv_url,
            ]);

            $csv_res = wp_remote_get($csv_url, ['timeout' => 15]);
            if (is_wp_error($csv_res)) {
                self::debug_log('CSV request failed', [
                    'book_key' => $book_key,
                    'spreadsheet_id' => $spreadsheet_id,
                    'sheet_name' => $sheet_name,
                    'csv_url' => $csv_url,
                    'error' => $csv_res->get_error_message(),
                ]);
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($csv_res);
            $body = (string) wp_remote_retrieve_body($csv_res);
            self::debug_log('CSV response', [
                'book_key' => $book_key,
                'spreadsheet_id' => $spreadsheet_id,
                'sheet_name' => $sheet_name,
                'csv_url' => $csv_url,
                'http_status' => $status,
                'body_head_500' => mb_substr($body, 0, 500),
            ]);

            if ($status < 200 || $status >= 300) {
                continue;
            }

            $rows = self::parse_csv($body);
            $row_blocks = self::split_row_blocks($rows);
            $total_blocks = count($row_blocks);
            self::debug_log('CSV parse summary', [
                'book_key' => $book_key,
                'spreadsheet_id' => $spreadsheet_id,
                'sheet_name' => $sheet_name,
                'parse_csv_rows' => count($rows),
                'split_row_blocks_count' => $total_blocks,
            ]);

            foreach ($row_blocks as $block_index => $row_block) {
                $normalized = self::normalize_price_table($sheet_name, $row_block);
                self::debug_log('Normalization result', [
                    'book_key' => $book_key,
                    'spreadsheet_id' => $spreadsheet_id,
                    'sheet_name' => $sheet_name,
                    'block_index' => $block_index,
                    'normalized_is_valid' => !empty($normalized),
                ]);
                if (empty($normalized)) {
                    continue;
                }
                $normalized_count++;

                if ($total_blocks > 1) {
                    $normalized['sheet_name'] = sprintf('%s [%d]', $sheet_name, $block_index + 1);
                }
                $sheets[] = $normalized;
            }
        }

        self::debug_log('Book load summary', [
            'book_key' => $book_key,
            'spreadsheet_id' => $spreadsheet_id,
            'fetched_sheet_names' => $fetched_sheet_names,
            'normalized_valid_count' => $normalized_count,
        ]);

        return $sheets;
    }

    /**
     * スプレッドシートからシート名一覧を取得する。
     * 優先順位:
     * 1) 固定シート名（定数/フィルター）
     * 2) Google Sheets API v4（APIキー指定時）
     *
     * @param string $spreadsheet_id スプレッドシートID。
     * @return array
     */
    private static function get_sheet_names($spreadsheet_id)
    {
        $static_map = apply_filters('dme_sheet_titles_map', self::SHEET_TITLES);
        if (isset($static_map[$spreadsheet_id]) && is_array($static_map[$spreadsheet_id]) && !empty($static_map[$spreadsheet_id])) {
            self::debug_log('Using static sheet names', [
                'spreadsheet_id' => $spreadsheet_id,
                'sheet_names' => $static_map[$spreadsheet_id],
            ]);
            return array_values($static_map[$spreadsheet_id]);
        }

        $api_key = self::get_google_api_key();
        if ($api_key === '') {
            self::debug_log('Google API key is empty; cannot discover sheet names via v4', [
                'spreadsheet_id' => $spreadsheet_id,
            ]);
            return [];
        }

        $v4_url = sprintf(
            'https://sheets.googleapis.com/v4/spreadsheets/%s?fields=sheets.properties.title&key=%s',
            rawurlencode($spreadsheet_id),
            rawurlencode($api_key)
        );
        self::debug_log('V4 sheet-list request URL', [
            'spreadsheet_id' => $spreadsheet_id,
            'url' => $v4_url,
        ]);

        $res = wp_remote_get($v4_url, ['timeout' => 15]);
        if (is_wp_error($res)) {
            self::debug_log('V4 sheet-list request failed', [
                'spreadsheet_id' => $spreadsheet_id,
                'error' => $res->get_error_message(),
            ]);
            return [];
        }

        $status = (int) wp_remote_retrieve_response_code($res);
        $body = (string) wp_remote_retrieve_body($res);
        self::debug_log('V4 sheet-list response', [
            'spreadsheet_id' => $spreadsheet_id,
            'http_status' => $status,
            'body_head_300' => mb_substr($body, 0, 300),
        ]);

        if ($status < 200 || $status >= 300) {
            return [];
        }

        $json = json_decode($body, true);
        if (!is_array($json) || empty($json['sheets']) || !is_array($json['sheets'])) {
            return [];
        }

        $sheet_names = [];
        foreach ($json['sheets'] as $sheet) {
            if (!empty($sheet['properties']['title'])) {
                $sheet_names[] = (string) $sheet['properties']['title'];
            }
        }

        self::debug_log('V4 discovered sheet names', [
            'spreadsheet_id' => $spreadsheet_id,
            'sheet_names' => $sheet_names,
        ]);

        return $sheet_names;
    }

    /**
     * Google APIキーを取得。
     *
     * @return string
     */
    private static function get_google_api_key()
    {
        if (defined('DME_GOOGLE_API_KEY') && DME_GOOGLE_API_KEY) {
            return (string) DME_GOOGLE_API_KEY;
        }

        $option = get_option('dme_google_api_key');
        return is_string($option) ? trim($option) : '';
    }

    /**
     * debug.log 用の出力ヘルパー。
     *
     * @param string $message メッセージ。
     * @param array  $context 補足情報。
     * @return void
     */
    private static function debug_log($message, $context = [])
    {
        if (!(defined('WP_DEBUG') && WP_DEBUG)) {
            return;
        }

        $prefix = '[DME_Sheets] ';
        $line = $prefix . $message;
        if (!empty($context)) {
            $json = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (is_string($json) && $json !== '') {
                $line .= ' ' . $json;
            }
        }

        error_log($line);
    }

    /**
     * CSVを配列化。
     *
     * @param string $csv CSV文字列。
     * @return array
     */
    private static function parse_csv($csv)
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\n|\r/', (string) $csv);
        $rows = [];

        foreach ($lines as $line) {
            if ($line === '') {
                $rows[] = [];
                continue;
            }
            $rows[] = str_getcsv($line);
        }

        return $rows;
    }

    /**
     * 空行で分割した行ブロックを返す。
     *
     * @param array $rows CSV行配列。
     * @return array
     */
    private static function split_row_blocks($rows)
    {
        $blocks = [];
        $current = [];

        foreach ($rows as $row) {
            $is_empty = true;
            if (is_array($row)) {
                foreach ($row as $cell) {
                    if (trim((string) $cell) !== '') {
                        $is_empty = false;
                        break;
                    }
                }
            }

            if ($is_empty) {
                if (!empty($current)) {
                    $blocks[] = $current;
                    $current = [];
                }
                continue;
            }

            $current[] = $row;
        }

        if (!empty($current)) {
            $blocks[] = $current;
        }

        return $blocks;
    }

    /**
     * 価格表を正規化。
     * 1行目: 見出し（1列目は部数ラベル想定）
     * 2行目以降: 1列目に部数、2列目以降に単価/金額。
     *
     * @param string $sheet_name シート名。
     * @param array  $rows      行配列。
     * @return array
     */
    private static function normalize_price_table($sheet_name, $rows)
    {
        if (count($rows) < 2 || !isset($rows[0])) {
            return [];
        }

        $header_row = $rows[0];
        $conditions = self::parse_conditions_from_sheet_name($sheet_name);

        // 2列目以降を仕様ヘッダーとして利用（色数・ページ数など）。
        $headers = [];
        for ($i = 1; $i < count($header_row); $i++) {
            $label = trim((string) $header_row[$i]);
            if ($label !== '') {
                $headers[] = $label;
            }
        }

        $matrix = [];
        $quantities = [];

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            if (!isset($row[0])) {
                continue;
            }

            $qty = (int) preg_replace('/[^0-9]/', '', (string) $row[0]);
            if ($qty <= 0) {
                continue;
            }

            $quantities[] = $qty;
            $matrix[$qty] = [];

            foreach ($headers as $index => $header_key) {
                $cell_index = $index + 1;
                $price = isset($row[$cell_index]) ? self::to_int_price($row[$cell_index]) : null;
                if ($price !== null) {
                    $matrix[$qty][$header_key] = $price;
                }
            }
        }

        if (empty($quantities)) {
            return [];
        }

        sort($quantities, SORT_NUMERIC);

        return [
            'sheet_name' => $sheet_name,
            'conditions' => $conditions,
            'headers' => $headers,
            'quantities' => $quantities,
            'matrix' => $matrix,
        ];
    }

    /**
     * シート名を "種類_サイズ_紙質_厚み_その他" で解釈。
     *
     * @param string $sheet_name シート名。
     * @return array
     */
    private static function parse_conditions_from_sheet_name($sheet_name)
    {
        $parts = array_map('trim', explode('_', (string) $sheet_name));

        return [
            'type' => isset($parts[0]) ? $parts[0] : '',
            'size' => isset($parts[1]) ? $parts[1] : '',
            'paper' => isset($parts[2]) ? $parts[2] : '',
            'thickness' => isset($parts[3]) ? $parts[3] : '',
            'other' => isset($parts[4]) ? $parts[4] : '',
            'raw' => $parts,
        ];
    }

    /**
     * 価格文字列を整数へ変換。
     *
     * @param mixed $value 値。
     * @return int|null
     */
    private static function to_int_price($value)
    {
        if ($value === '' || $value === null) {
            return null;
        }
        $num = preg_replace('/[^0-9.-]/', '', (string) $value);
        if ($num === '' || !is_numeric($num)) {
            return null;
        }

        return (int) round((float) $num);
    }
}
