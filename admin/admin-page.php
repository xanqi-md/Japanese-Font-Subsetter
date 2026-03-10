<?php
/**
 * 管理画面テンプレート (v2.4 — 推奨バッジ・Elementor V4完全対応)
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$plugin  = Japanese_Font_Subsetter::get_instance();
$files   = $plugin->get_converted_files();
$reg_fonts = $plugin->get_registered_fonts();

$subset_options = array(
    0 => array( 'label' => '① なし（WOFF2変換のみ）',              'desc' => 'サブセット化を行わず、WOFF2フォーマットへの変換のみ行います。', 'icon' => '🔄', 'recommended' => false ),
    1 => array( 'label' => '② ひらがなのみ',                       'desc' => 'ひらがな（ぁ〜ゟ）のみを含みます。',                          'icon' => 'あ',  'recommended' => false ),
    2 => array( 'label' => '③ カタカナのみ',                       'desc' => 'カタカナ（ァ〜ン等）のみを含みます。',                        'icon' => 'ア',  'recommended' => false ),
    3 => array( 'label' => '④ 半角英数字のみ',                     'desc' => 'ASCII印刷可能文字（U+0020〜U+007E）のみを含みます。',         'icon' => 'Aa',  'recommended' => false ),
    4 => array( 'label' => '⑤ 半角および全角の英数字',             'desc' => '半角英数字＋全角英数字（Ａ〜Ｚ、ａ〜ｚ、０〜９等）を含みます。', 'icon' => 'Ａａ', 'recommended' => false ),
    5 => array( 'label' => '⑥ ひらがな＋カタカナ＋英数字＋記号', 'desc' => 'ひらがな・カタカナ・半角/全角英数字・各種記号を含みます。',    'icon' => '記',  'recommended' => false ),
    6 => array( 'label' => '⑦ ⑥＋常用漢字（2,136字）',           'desc' => '⑥のセット＋文部科学省告示の常用漢字2,136字を含みます。',      'icon' => '常',  'recommended' => false ),
    7 => array( 'label' => '⑧ ⑥＋JIS第1水準漢字（2,965字）',     'desc' => '⑥のセット＋JIS X 0208 第1水準漢字を含みます。一般的なWeb用途に最適です。', 'icon' => '第1', 'recommended' => true ),
    8 => array( 'label' => '⑨ ⑧＋JIS第2水準漢字（合計6,353字）', 'desc' => '第1水準のセット＋JIS X 0208 第2水準漢字を含みます。より幅広い漢字が必要な場合に。', 'icon' => '第2', 'recommended' => true ),
);
?>
<div class="wrap jfs-wrap">
    <h1 class="jfs-title">
        <span class="dashicons dashicons-editor-textcolor"></span>
        Japanese Font Subsetter <span class="jfs-version-badge">v2.3</span>
    </h1>

    <!-- ブラウザJS処理の案内 -->
    <div class="jfs-info-banner">
        <span class="jfs-info-icon">ℹ️</span>
        <div>
            <strong>ブラウザ内処理モード</strong>
            — フォントのサブセット化と WOFF2 変換はすべてブラウザ内で行われます。サーバーに Python や fonttools は不要です。
        </div>
    </div>

    <!-- Elementor 連携ステータス -->
    <div class="jfs-elementor-banner <?php echo empty( $reg_fonts ) ? 'jfs-elementor-banner--empty' : 'jfs-elementor-banner--active'; ?>">
        <span class="jfs-elementor-icon">
            <?php echo empty( $reg_fonts ) ? '🔌' : '✅'; ?>
        </span>
        <div>
            <?php if ( empty( $reg_fonts ) ) : ?>
                <strong>Elementor 連携</strong> — フォントを変換するとElementorの「フォントファミリー」選択に自動登録されます。<br>
                <span style="font-size:12px;">Elementor 無料版・Pro版・V3・V4 すべてに対応しています。</span>
            <?php else : ?>
                <strong>Elementor に <?php echo count( $reg_fonts ); ?> 件のフォントが登録済みです。</strong><br>
                <span style="font-size:12px;">
                    登録フォント:
                    <?php
                    $names = array_keys( $reg_fonts );
                    echo esc_html( implode( '、', $names ) );
                    ?>
                    — Elementorエディターの「スタイル → タイポグラフィ → フォントファミリー」から選択できます。
                </span>
            <?php endif; ?>
        </div>
    </div>

    <div class="jfs-main-grid">
        <!-- 左カラム: アップロード・変換 -->
        <div class="jfs-card jfs-upload-card">
            <h2>📤 フォントをアップロード・変換</h2>

            <!-- ドロップゾーン -->
            <div class="jfs-dropzone" id="jfs-dropzone">
                <div class="jfs-dropzone-inner">
                    <span class="jfs-drop-icon">🗛</span>
                    <p class="jfs-drop-text">フォントファイルをドラッグ＆ドロップ</p>
                    <p class="jfs-drop-sub">または</p>
                    <label class="jfs-file-btn" for="jfs-font-file">
                        ファイルを選択
                    </label>
                    <input type="file" id="jfs-font-file" accept=".otf,.ttf,.woff" style="display:none;">
                    <p class="jfs-drop-formats">対応形式: OTF / TTF / WOFF</p>
                    <p id="jfs-selected-file" class="jfs-selected-file"></p>
                </div>
            </div>

            <!-- サブセット選択 -->
            <div class="jfs-subset-section">
                <h3>📋 サブセット範囲を選択</h3>
                <div class="jfs-subset-grid">
                    <?php foreach ( $subset_options as $val => $opt ) : ?>
                    <label class="jfs-subset-item <?php echo $val === 0 ? 'active' : ''; ?><?php echo $opt['recommended'] ? ' jfs-subset-recommended' : ''; ?>"
                           data-value="<?php echo $val; ?>">
                        <?php if ( $opt['recommended'] ) : ?>
                        <span class="jfs-badge-recommended">推奨</span>
                        <?php endif; ?>
                        <input type="radio" name="subset_type" value="<?php echo $val; ?>"
                               <?php echo $val === 0 ? 'checked' : ''; ?>>
                        <span class="jfs-subset-icon"><?php echo esc_html( $opt['icon'] ); ?></span>
                        <span class="jfs-subset-label"><?php echo esc_html( $opt['label'] ); ?></span>
                        <span class="jfs-subset-desc"><?php echo esc_html( $opt['desc'] ); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="button" class="jfs-convert-btn" id="jfs-convert-btn" disabled>
                <span class="jfs-btn-text">🚀 変換開始</span>
                <span class="jfs-btn-loading" style="display:none;">⏳ 処理中...</span>
            </button>

            <!-- 進行状況 -->
            <div id="jfs-progress" style="display:none;">
                <div class="jfs-progress-bar">
                    <div class="jfs-progress-fill" id="jfs-progress-fill"></div>
                </div>
                <p class="jfs-progress-text" id="jfs-progress-text">処理しています...</p>
            </div>

            <!-- 結果 -->
            <div id="jfs-result" style="display:none;"></div>
        </div>

        <!-- 右カラム: 変換済みファイル -->
        <div class="jfs-card jfs-files-card">
            <h2>📁 変換済みフォント（Elementor登録済み）</h2>
            <div id="jfs-files-list">
                <?php if ( empty( $files ) ) : ?>
                <div class="jfs-empty-state">
                    <p>まだ変換済みファイルがありません。</p>
                </div>
                <?php else : ?>
                <table class="jfs-files-table widefat striped">
                    <thead>
                        <tr>
                            <th>フォント名 / ファイル名</th>
                            <th>サイズ</th>
                            <th>作成日時</th>
                            <th>Elementor</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $files as $f ) : ?>
                        <tr id="jfs-file-<?php echo esc_attr( md5( $f['filename'] ) ); ?>">
                            <td>
                                <span class="dashicons dashicons-media-document"></span>
                                <?php if ( $f['font_name'] ) : ?>
                                    <strong><?php echo esc_html( $f['font_name'] ); ?></strong><br>
                                    <small style="color:#64748b;"><?php echo esc_html( $f['filename'] ); ?></small>
                                <?php else : ?>
                                    <?php echo esc_html( $f['filename'] ); ?>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( $f['filesize'] ); ?></td>
                            <td><?php echo esc_html( $f['modified'] ); ?></td>
                            <td>
                                <?php if ( $f['is_registered'] ) : ?>
                                <span class="jfs-elementor-status jfs-elementor-status--ok">✅ 登録済み</span>
                                <?php else : ?>
                                <span class="jfs-elementor-status jfs-elementor-status--ng">— 未登録</span>
                                <?php endif; ?>
                            </td>
                            <td class="jfs-actions">
                                <a href="<?php echo esc_url( $f['url'] ); ?>"
                                   class="button button-small jfs-dl-btn" download>
                                    ⬇️ DL
                                </a>
                                <button class="button button-small jfs-delete-btn"
                                        data-filename="<?php echo esc_attr( $f['filename'] ); ?>">
                                    🗑️ 削除
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Elementor 使い方案内 -->
            <div class="jfs-elementor-howto">
                <h3>🎨 Elementor での使い方</h3>
                <ol class="jfs-howto-list">
                    <li>フォントを変換すると自動的にElementorに登録されます</li>
                    <li>Elementorエディターでテキストウィジェットを選択</li>
                    <li>左パネル「スタイル」→「タイポグラフィ」→「フォントファミリー」をクリック</li>
                    <li><strong>V3:</strong> リスト上部の「<strong>⚡ JFS サブセットフォント</strong>」グループから選択</li>
                    <li><strong>V4:</strong> 「<strong>Custom Fonts</strong>」グループから選択（V4 Reactピッカー対応）</li>
                    <li>フォントが即時反映されない場合はページをリロードしてください</li>
                </ol>
                <div class="jfs-compat-badges">
                    <span class="jfs-badge jfs-badge--green">✅ Elementor Free</span>
                    <span class="jfs-badge jfs-badge--green">✅ Elementor Pro</span>
                    <span class="jfs-badge jfs-badge--green">✅ V3 対応</span>
                    <span class="jfs-badge jfs-badge--green">✅ V4 対応</span>
                </div>
            </div>
        </div>
    </div><!-- .jfs-main-grid -->
</div><!-- .jfs-wrap -->
