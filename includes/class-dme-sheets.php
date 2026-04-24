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

        return [
            'printCatalog' => $print_catalog,
            'workFees' => isset($all['dm_work']) ? $all['dm_work']['sheets'] : [],
            'paperWeights' => isset($all['paper_weight']) ? $all['paper_weight']['sheets'] : [],
            'postage' => isset($all['postage']) ? $all['postage']['sheets'] : [],
            'updatedAt' => gmdate('c'),
        ];
    }

    /**
     * 全ブック読み込み。
     *
     * @return array
     */
    public static function get_all_books()
    {
        $cache_key = 'dme_sheet_books_v1';
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $output = [];
        foreach (self::SHEET_BOOKS as $book_key => $spreadsheet_id) {
            $output[$book_key] = [
                'label' => $book_key,
                'sheets' => self::load_book_sheets($spreadsheet_id),
            ];
        }

        set_transient($cache_key, $output, 30 * MINUTE_IN_SECONDS);
        return $output;
    }

    /**
     * ブック内シート一覧と内容取得。
     *
     * @param string $spreadsheet_id スプレッドシートID。
     * @return array
     */
    private static function load_book_sheets($spreadsheet_id)
    {
        $worksheets_url = sprintf(
            'https://spreadsheets.google.com/feeds/worksheets/%s/public/basic?alt=json',
            rawurlencode($spreadsheet_id)
        );

        $res = wp_remote_get($worksheets_url, ['timeout' => 15]);
        if (is_wp_error($res)) {
            return [];
        }

        $json = json_decode((string) wp_remote_retrieve_body($res), true);
        if (!is_array($json) || empty($json['feed']['entry'])) {
            return [];
        }

        $sheets = [];
        foreach ($json['feed']['entry'] as $entry) {
            $sheet_name = isset($entry['title']['$t']) ? (string) $entry['title']['$t'] : '';
            if ($sheet_name === '') {
                continue;
            }

            $csv_url = sprintf(
                'https://docs.google.com/spreadsheets/d/%s/gviz/tq?tqx=out:csv&sheet=%s',
                rawurlencode($spreadsheet_id),
                rawurlencode($sheet_name)
            );
            $csv_res = wp_remote_get($csv_url, ['timeout' => 15]);
            if (is_wp_error($csv_res)) {
                continue;
            }

            $rows = self::parse_csv((string) wp_remote_retrieve_body($csv_res));
            $row_blocks = self::split_row_blocks($rows);
            $total_blocks = count($row_blocks);

            foreach ($row_blocks as $block_index => $row_block) {
                $normalized = self::normalize_price_table($sheet_name, $row_block);
                if (empty($normalized)) {
                    continue;
                }

                if ($total_blocks > 1) {
                    $normalized['sheet_name'] = sprintf('%s [%d]', $sheet_name, $block_index + 1);
                }
                $sheets[] = $normalized;
            }
        }

        return $sheets;
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

        $fallback_qty = 1;

        for ($r = 1; $r < count($rows); $r++) {
            $row = $rows[$r];
            if (!isset($row[0])) {
                continue;
            }

            $qty = (int) preg_replace('/[^0-9]/', '', (string) $row[0]);
            if ($qty <= 0) {
                // 作業費などは1列目が名称で、部数になっていない表がある。
                $qty = $fallback_qty;
                $fallback_qty++;
            }

            $row_prices = [];
            foreach ($headers as $index => $header_key) {
                $cell_index = $index + 1;
                $price = isset($row[$cell_index]) ? self::to_int_price($row[$cell_index]) : null;
                if ($price !== null) {
                    $row_prices[$header_key] = $price;
                }
            }

            if (empty($row_prices)) {
                continue;
            }

            while (isset($matrix[$qty])) {
                $qty++;
            }

            $quantities[] = $qty;
            $matrix[$qty] = $row_prices;
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
