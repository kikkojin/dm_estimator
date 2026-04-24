<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 見積計算専用クラス。
 *
 * 将来的にPDF出力や外部連携をする際も、
 * このクラスが返す「明細配列」を使い回せる構造にしている。
 */
class DME_Pricing
{
    /**
     * 見積計算実行。
     *
     * @param array $payload フロントからの入力。
     * @param array $catalog スプレッドシートカタログ。
     * @return array
     */
    public static function calculate($payload, $catalog)
    {
        $items = [];
        $errors = [];

        $ship_count = isset($payload['shipCount']) ? absint($payload['shipCount']) : 0;
        if ($ship_count <= 0) {
            $errors[] = '発送部数が不足しています。';
        }

        // 基本作業費。
        $basic_fee = self::find_work_fee($catalog, '発送基本料金');
        if ($basic_fee === null) {
            $errors[] = '発送基本料金を取得できません。';
        } else {
            $items[] = self::make_item('発送基本料金', $basic_fee, 1, '必須作業');
        }

        // 封筒。
        if (isset($payload['envelope']) && isset($payload['envelope']['use']) && $payload['envelope']['use'] === 'yes') {
            $envelope_item = self::calc_envelope($payload['envelope'], $ship_count, $catalog);
            if ($envelope_item['error']) {
                $errors[] = $envelope_item['error'];
            } elseif (!empty($envelope_item['item'])) {
                $items[] = $envelope_item['item'];
            }
        }

        // 内容物（複数）。
        $content_count = 0;
        if (!empty($payload['contents']) && is_array($payload['contents'])) {
            foreach ($payload['contents'] as $content) {
                $content_count++;
                $result = self::calc_content_item($content, $ship_count, $catalog);
                if ($result['error']) {
                    $errors[] = sprintf('内容物%1$d: %2$s', $content_count, $result['error']);
                } elseif (!empty($result['item'])) {
                    $items[] = $result['item'];
                }
            }
        }

        // 封入作業費。
        if ($content_count > 0 && $ship_count > 0) {
            $insert_base = self::find_work_fee($catalog, '封入1点目');
            $insert_additional = self::find_work_fee($catalog, '封入追加');

            if ($insert_base !== null) {
                $items[] = self::make_item('封入作業（1点目）', $insert_base, $ship_count, '部数分');
            }
            if ($insert_additional !== null && $content_count > 1) {
                $items[] = self::make_item('封入作業（2点目以降）', $insert_additional, ($content_count - 1) * $ship_count, '追加点数×部数');
            }
        }

        // 返信方法。
        $reply_mode = isset($payload['replyMode']) ? (string) $payload['replyMode'] : 'stamp';
        if ($reply_mode === 'stamp') {
            $stamp_fee = self::find_work_fee($catalog, '切手貼代');
            $postage = self::find_postage_fee($catalog, $payload);
            if ($stamp_fee === null || $postage === null) {
                $errors[] = '返信用の切手関連費用を算出できません。';
            } else {
                $items[] = self::make_item('切手貼代', $stamp_fee, $ship_count, '返信方法: 切手');
                $items[] = self::make_item('返信郵便料金', $postage, $ship_count, '重量計算ベース');
            }
        } else {
            $items[] = self::make_item('受取人払い', 0, 1, '料金は個別見積');
            if (!empty($payload['reply']['delegate'])) {
                $delegate_fee = self::find_work_fee($catalog, '申請代行');
                if ($delegate_fee !== null) {
                    $items[] = self::make_item('受取人払い申請代行', $delegate_fee, 1, 'オプション');
                }
            }
        }

        // アンケート発送で封筒デザイン依頼あり。
        if (!empty($payload['envelopeDesignRequest']) && $payload['envelopeDesignRequest'] === true) {
            $design_fee = self::find_work_fee($catalog, '封筒デザイン依頼');
            if ($design_fee !== null) {
                $items[] = self::make_item('封筒デザイン依頼', $design_fee, 1, 'アンケート向け追加');
            }
        }

        $items = self::filter_invalid_zero_display($items);

        $total = 0;
        foreach ($items as $item) {
            $total += (int) $item['subtotal'];
        }

        return [
            'items' => array_values($items),
            'total' => $total,
            'is_estimatable' => empty($errors),
            'errors' => $errors,
        ];
    }

    private static function calc_envelope($envelope, $ship_count, $catalog)
    {
        $mode = isset($envelope['mode']) ? (string) $envelope['mode'] : 'supplied';
        $count = isset($envelope['count']) ? absint($envelope['count']) : $ship_count;
        if ($count <= 0) {
            return ['item' => null, 'error' => '封筒部数が不足しています。'];
        }

        if ($mode === 'supplied') {
            return ['item' => self::make_item('封筒（支給）', 0, $count, '価格0円・重量計算のみ'), 'error' => null];
        }

        if ($mode === 'clear') {
            $clear_fee = self::find_work_fee($catalog, '透明封筒');
            if ($clear_fee === null) {
                return ['item' => null, 'error' => '透明封筒の料金を取得できません。'];
            }
            return ['item' => self::make_item('透明封筒', $clear_fee, $count, 'DM作業費より'), 'error' => null];
        }

        $query = [
            'bookKey' => 'envelope_print',
            'size' => isset($envelope['size']) ? $envelope['size'] : '',
            'paper' => isset($envelope['paper']) ? $envelope['paper'] : '',
            'thickness' => isset($envelope['thickness']) ? $envelope['thickness'] : '',
            'spec' => isset($envelope['spec']) ? $envelope['spec'] : '',
            'quantity' => $count,
        ];

        $found = self::find_price_from_catalog($catalog, $query);
        if ($found === null) {
            return ['item' => null, 'error' => '封筒印刷の価格取得不可。'];
        }

        return ['item' => self::make_item('封筒印刷', $found['unit_price'], $count, $found['note']), 'error' => null];
    }

    private static function calc_content_item($content, $ship_count, $catalog)
    {
        $name = !empty($content['name']) ? sanitize_text_field($content['name']) : '内容物';
        $mode = isset($content['mode']) ? (string) $content['mode'] : 'supplied';

        if ($mode === 'supplied') {
            return ['item' => self::make_item($name . '（支給）', 0, $ship_count, '価格0円'), 'error' => null];
        }

        $query = [
            'bookKey' => isset($content['bookKey']) ? $content['bookKey'] : '',
            'size' => isset($content['size']) ? $content['size'] : '',
            'paper' => isset($content['paper']) ? $content['paper'] : '',
            'thickness' => isset($content['thickness']) ? $content['thickness'] : '',
            'spec' => isset($content['spec']) ? $content['spec'] : '',
            'quantity' => $ship_count,
        ];

        $found = self::find_price_from_catalog($catalog, $query);
        if ($found === null) {
            return ['item' => null, 'error' => $name . ' の価格取得不可'];
        }

        return [
            'item' => self::make_item($name, $found['unit_price'], $ship_count, $found['note']),
            'error' => null,
        ];
    }

    private static function find_price_from_catalog($catalog, $query)
    {
        if (empty($catalog['printCatalog']) || !is_array($catalog['printCatalog'])) {
            return null;
        }

        foreach ($catalog['printCatalog'] as $sheet) {
            if (($query['bookKey'] !== '') && $sheet['bookKey'] !== $query['bookKey']) {
                continue;
            }

            $conditions = isset($sheet['conditions']) ? $sheet['conditions'] : [];
            if (!empty($query['size']) && (!isset($conditions['size']) || $conditions['size'] !== $query['size'])) {
                continue;
            }
            if (!empty($query['paper']) && (!isset($conditions['paper']) || $conditions['paper'] !== $query['paper'])) {
                continue;
            }
            if (!empty($query['thickness']) && (!isset($conditions['thickness']) || $conditions['thickness'] !== $query['thickness'])) {
                continue;
            }

            $spec = $query['spec'];
            if ($spec === '' && !empty($sheet['headers'][0])) {
                $spec = $sheet['headers'][0];
            }

            $selected_qty = self::pick_min_quantity_over((int) $query['quantity'], $sheet['quantities']);
            if ($selected_qty === null) {
                continue;
            }

            $unit = isset($sheet['matrix'][$selected_qty][$spec]) ? (int) $sheet['matrix'][$selected_qty][$spec] : null;
            if ($unit === null) {
                continue;
            }

            return [
                'unit_price' => $unit,
                'note' => sprintf('%s / %s部適用', $sheet['sheetName'], $selected_qty),
            ];
        }

        return null;
    }

    private static function pick_min_quantity_over($required, $quantities)
    {
        if ($required <= 0 || empty($quantities)) {
            return null;
        }
        sort($quantities, SORT_NUMERIC);
        foreach ($quantities as $qty) {
            if ((int) $qty >= $required) {
                return (int) $qty;
            }
        }
        return null;
    }

    private static function find_work_fee($catalog, $keyword)
    {
        if (empty($catalog['workFees'])) {
            return null;
        }

        $aliases = [$keyword];
        if ($keyword === '発送基本料金') {
            $aliases[] = 'hassou_kihon';
            $aliases[] = '発送関連作業基本料金';
        }

        foreach ($catalog['workFees'] as $sheet) {
            if (!empty($sheet['rows']) && is_array($sheet['rows'])) {
                // 1) 作業コード一致 2) 作業名一致（完全） 3) 作業名部分一致 の順で探索。
                foreach ($aliases as $alias) {
                    foreach ($sheet['rows'] as $row) {
                        if (!is_array($row)) {
                            continue;
                        }

                        $code = isset($row['work_code']) ? (string) $row['work_code'] : '';
                        $name = isset($row['work_name']) ? (string) $row['work_name'] : '';
                        $is_match = ($code !== '' && $code === $alias)
                            || ($name !== '' && $name === $alias)
                            || ($name !== '' && mb_strpos($name, $alias) !== false);
                        if (!$is_match) {
                            continue;
                        }

                        if (isset($row['basic_fee']) && is_numeric($row['basic_fee'])) {
                            return (int) $row['basic_fee'];
                        }
                        if (isset($row['unit_price']) && is_numeric($row['unit_price'])) {
                            return (int) $row['unit_price'];
                        }
                    }
                }
            }

            $name = isset($sheet['sheet_name']) ? $sheet['sheet_name'] : '';
            if (mb_strpos($name, $keyword) !== false && !empty($sheet['matrix']) && is_array($sheet['matrix'])) {
                foreach ($sheet['matrix'] as $row) {
                    foreach ($row as $price) {
                        if (is_numeric($price)) {
                            return (int) $price;
                        }
                    }
                }
            }
        }
        return null;
    }

    private static function find_postage_fee($catalog, $payload)
    {
        if (empty($catalog['postage'])) {
            return null;
        }

        $target_size = '';
        if (!empty($payload['reply']['size'])) {
            $target_size = (string) $payload['reply']['size'];
        } elseif (!empty($payload['envelope']['size'])) {
            $target_size = (string) $payload['envelope']['size'];
        }

        $target_weight = 0.0;
        if (isset($payload['reply']['weightG']) && is_numeric($payload['reply']['weightG'])) {
            $target_weight = (float) $payload['reply']['weightG'];
        } elseif (isset($payload['totalWeightG']) && is_numeric($payload['totalWeightG'])) {
            $target_weight = (float) $payload['totalWeightG'];
        } elseif (isset($payload['estimatedWeightG']) && is_numeric($payload['estimatedWeightG'])) {
            $target_weight = (float) $payload['estimatedWeightG'];
        }

        $lowest = null;
        foreach ($catalog['postage'] as $sheet) {
            if (!empty($sheet['rows']) && is_array($sheet['rows'])) {
                $rows = $sheet['rows'];
                if ($target_size !== '') {
                    $size_rows = array_values(array_filter($rows, function ($row) use ($target_size) {
                        if (empty($row['size'])) {
                            return false;
                        }
                        $size = (string) $row['size'];
                        return $size === $target_size || mb_strpos($size, $target_size) !== false || mb_strpos($target_size, $size) !== false;
                    }));
                    if (!empty($size_rows)) {
                        $rows = $size_rows;
                    }
                }

                usort($rows, function ($a, $b) {
                    return ((float) ($a['max_g'] ?? 0)) <=> ((float) ($b['max_g'] ?? 0));
                });

                $selected = null;
                foreach ($rows as $row) {
                    if (!isset($row['fee']) || !is_numeric($row['fee'])) {
                        continue;
                    }
                    $max_g = isset($row['max_g']) && is_numeric($row['max_g']) ? (float) $row['max_g'] : 0.0;
                    if ($target_weight > 0 && $max_g > 0 && $target_weight <= $max_g) {
                        $selected = (int) $row['fee'];
                        break;
                    }
                    if ($target_weight <= 0 && $selected === null) {
                        $selected = (int) $row['fee'];
                    }
                    if ($target_weight > 0) {
                        $selected = (int) $row['fee']; // 上限超過時のフォールバックとして最後の段を保持
                    }
                }

                if ($selected !== null) {
                    return $selected;
                }
            }

            if (!empty($sheet['matrix']) && is_array($sheet['matrix'])) {
                foreach ($sheet['matrix'] as $row) {
                    foreach ($row as $price) {
                        if (is_numeric($price)) {
                            $price = (int) $price;
                            if ($price > 0 && ($lowest === null || $price < $lowest)) {
                                $lowest = $price;
                            }
                        }
                    }
                }
            }
        }
        return $lowest;
    }

    private static function make_item($label, $unit_price, $quantity, $note)
    {
        $quantity = (int) $quantity;
        $unit_price = (int) $unit_price;

        return [
            'label' => $label,
            'unit_price' => $unit_price,
            'quantity' => $quantity,
            'subtotal' => $unit_price * $quantity,
            'note' => $note,
        ];
    }

    private static function filter_invalid_zero_display($items)
    {
        foreach ($items as &$item) {
            // 「0円誤表示禁止」ルールにより、0円は理由を備考に明示。
            if ((int) $item['unit_price'] === 0 && mb_strpos((string) $item['note'], '0円') === false) {
                $item['note'] .= '（0円理由明記）';
            }
        }

        return $items;
    }
}
