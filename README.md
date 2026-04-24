# DM Estimator

印刷・DM発送の見積もりをリアルタイム計算し、明細付き問い合わせメールを送信する WordPress プラグインです。

## ショートコード

`[dm_estimator_form]`

## 主な機能

- Googleスプレッドシート（公開）から価格表を取得
- 条件に応じた段階的絞り込みと見積計算
- 見積不可時の送信ブロック
- 見積明細をテキスト形式で `wp_mail()` 送信
- 宛先メールを管理画面（設定 > DM Estimator）で変更

## インストール

1. 本プラグインを `wp-content/plugins/dm-estimator` に配置
2. 管理画面で「DM Estimator」を有効化
3. 「設定 > DM Estimator」で送信先メールを設定
4. 固定ページ等に `[dm_estimator_form]` を設置
