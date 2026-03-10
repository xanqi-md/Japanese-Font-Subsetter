<?php
/**
 * Plugin Name: Japanese Font Subsetter
 * Plugin URI:  https://example.com/japanese-font-subsetter
 * Description: 日本語フォント（OTF/TTF/WOFF）をブラウザ内でサブセット化し、WOFF2へ変換。Elementor無料版・Pro版・V3・V4に自動登録します。
 * Version:     2.5.0
 * Author:      Your Name
 * Author URI:  https://example.com
 * License:     GPL-2.0+
 * Text Domain: japanese-font-subsetter
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'JFS_VERSION',    '2.5.0' );
define( 'JFS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JFS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'JFS_UPLOAD_DIR', WP_CONTENT_DIR . '/uploads/japanese-font-subsetter/' );
define( 'JFS_UPLOAD_URL', WP_CONTENT_URL . '/uploads/japanese-font-subsetter/' );

/**
 * プラグインのメインクラス
 * v2.5: Elementor V3/V4 完全デュアル対応
 *
 * V4 (v2.4) 対応済み内容:
 *   フォントタイプ 'jfs_fonts' → 'custom' 変更
 *   V4 React useFontFamilies は 'system'|'custom'|'googlefonts' のみ認識
 *
 * V3 追加対応 (v2.5) :
 *   根本原因: Fonts::LOCAL = 'local' であり、V3 デフォルトの get_font_groups()
 *   には 'custom' グループが含まれていない（Elementor Custom Fonts プラグイン
 *   がある場合のみ追加される）。そのためフォントタイプが 'custom' でも
 *   V3 フォントピッカーのグループリストに表示されなかった。
 *
 *   修正: elementor/fonts/groups フィルターで 'custom' グループを明示追加
 *     → V3 Backbone.js ピッカー: 'custom' グループが出現し、フォントが表示
 *     → V4 React ピッカー: useFontFamilies が 'custom' を認識（従来通り）
 *     → CSS: print_font_links/custom アクションは V3/V4 共通で動作
 */
class Japanese_Font_Subsetter {

    private static $instance = null;

    /** V4 React ピッカーが認識するフォントタイプ */
    const FONT_TYPE = 'custom';

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // 管理画面
        add_action( 'admin_menu',            array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        // Ajax
        add_action( 'wp_ajax_jfs_save_font',  array( $this, 'ajax_save_font' ) );
        add_action( 'wp_ajax_jfs_delete',     array( $this, 'ajax_delete' ) );
        add_action( 'wp_ajax_jfs_get_kanji',  array( $this, 'ajax_get_kanji' ) );

        // ── Elementor フォントセレクター登録（V3 / V4 共通） ──
        // V3: Backbone.js 型コントロールの option に追加
        // V4: React useFontFamilies が getElementorConfig().controls.font.options を参照するため同じフィルターが有効
        add_filter( 'elementor/fonts/additional_fonts', array( $this, 'elementor_additional_fonts' ), 10, 1 );
        // V3: font_groups に 'custom' がない場合のみ追加（Custom Fonts プラグイン非依存）
        // V4: useFontFamilies は font.options のタイプ文字列で判定するため groups への追加は影響しないが無害
        add_filter( 'elementor/fonts/groups', array( $this, 'elementor_font_groups' ), 10, 1 );

        // ── CSS 配信（多層アプローチ：V3/V4/フロントエンド全対応） ──

        // [Layer A] WordPress 標準スタイルキュー（フロントエンド）
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_font_css' ) );

        // [Layer B] wp_head 直接 echo（最優先フォールバック）
        add_action( 'wp_head',  array( $this, 'print_font_css_direct' ), 5 );

        // [Layer C] wp_footer 直接 echo（ウィジェットレンダリング後の確実な出力）
        add_action( 'wp_footer', array( $this, 'print_font_css_direct' ), 1 );

        // [Layer D] Elementor プレビュー iframe（V3/V4 共通）
        add_action( 'elementor/preview/enqueue_styles',        array( $this, 'enqueue_font_css' ), 20 );

        // [Layer E] Elementor エディター UI（パネル側）
        add_action( 'elementor/editor/before_enqueue_scripts', array( $this, 'enqueue_font_css' ) );
        add_action( 'elementor/editor/footer',                 array( $this, 'print_font_css_direct' ) );

        // [Layer F] V4 専用エディタースタイルフック
        add_action( 'elementor/editor/v2/styles/enqueue',      array( $this, 'enqueue_font_css' ) );

        // [Layer G] PHP フォントCSS パイプライン統合
        //   frontend->enqueue_font($font) → print_fonts_links → get_list_of_google_fonts_by_type
        //   → switch(font_type) default → do_action('elementor/fonts/print_font_links/custom', $font)
        //   フォントタイプを 'custom' に変更したため /custom アクションにフック
        add_action( 'elementor/fonts/print_font_links/custom', array( $this, 'print_single_font_css' ), 10, 1 );

        // [Layer H] V4 エディター JavaScript 注入
        //   JS の enqueueFont() は 'custom' タイプに対して fontUrl を設定しないため
        //   JS 側で preview iframe と editor document の両方に CSS を直接注入する
        add_action( 'elementor/editor/after_enqueue_scripts',  array( $this, 'inject_font_css_to_v4_editor' ) );
    }

    /* ============================================================
       プラグイン有効化
    ============================================================ */
    public static function on_activate() {
        if ( ! file_exists( JFS_UPLOAD_DIR ) ) {
            wp_mkdir_p( JFS_UPLOAD_DIR );
        }
        $htaccess = JFS_UPLOAD_DIR . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Options -Indexes\n" );
        }
    }

    /* ============================================================
       管理メニュー
    ============================================================ */
    public function add_admin_menu() {
        add_menu_page(
            __( 'Japanese Font Subsetter', 'japanese-font-subsetter' ),
            __( 'Font Subsetter', 'japanese-font-subsetter' ),
            'manage_options',
            'japanese-font-subsetter',
            array( $this, 'render_admin_page' ),
            'dashicons-editor-textcolor',
            80
        );
    }

    /* ============================================================
       管理スクリプト
    ============================================================ */
    public function enqueue_admin_scripts( $hook ) {
        if ( 'toplevel_page_japanese-font-subsetter' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'jfs-admin',
            JFS_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            JFS_VERSION
        );

        wp_enqueue_script(
            'jfs-opentype',
            JFS_PLUGIN_URL . 'assets/js/opentype/opentype.min.js',
            array(),
            '1.3.4',
            true
        );

        wp_enqueue_script(
            'jfs-wawoff2',
            JFS_PLUGIN_URL . 'assets/js/wawoff2/compress_binding.js',
            array(),
            JFS_VERSION,
            true
        );
        wp_add_inline_script(
            'jfs-wawoff2',
            'window.JFSWoff2Module = {}; window.JFSWoff2Ready = new Promise(function(resolve){ window.JFSWoff2Module.onRuntimeInitialized = resolve; }); var Module = window.JFSWoff2Module;',
            'before'
        );

        wp_enqueue_script(
            'jfs-admin',
            JFS_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery', 'jfs-opentype', 'jfs-wawoff2' ),
            JFS_VERSION,
            true
        );

        wp_localize_script( 'jfs-admin', 'jfsData', array(
            'ajaxurl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'jfs_nonce' ),
            'kanji_nonce' => wp_create_nonce( 'jfs_kanji' ),
        ) );
    }

    public function render_admin_page() {
        require_once JFS_PLUGIN_DIR . 'admin/admin-page.php';
    }

    /* ============================================================
       Elementor: フォントグループ追加（V3/V4 共通）
       ─────────────────────────────────────────────────────────
       Elementor V3/V4 の Fonts::get_font_groups() デフォルトには
       'custom' グループが含まれていない（Elementor Custom Fonts プラグイン
       が有効な時のみ追加される）。

       V3 Backbone.js フォントピッカー: font_groups に 'custom' がないと
       フォントタイプが 'custom' でもグループが表示されない → 追加が必須。

       V4 React useFontFamilies: フィルター後の groups 配列を参照せず
       elementor.config.controls.font.options のタイプ文字列で判定するため
       groups への追加は不要だが、追加しても副作用なし。
    ============================================================ */
    public function elementor_font_groups( $font_groups ) {
        if ( ! array_key_exists( 'custom', $font_groups ) ) {
            // 'custom' グループが未登録の場合に追加
            // V3 で Elementor Custom Fonts プラグインなしの環境でも表示される
            $font_groups['custom'] = esc_html__( 'Custom Fonts', 'elementor' );
        }
        return $font_groups;
    }

    /* ============================================================
       Elementor: フォントセレクターへの追加（V3/V4 共通）
       ─────────────────────────────────────────────────────────
       V4 useFontFamilies が認識するタイプ: 'system' | 'custom' | 'googlefonts'
       → 'custom' を使用することで V4 React ピッカーの "Custom Fonts" 欄に表示される
    ============================================================ */
    public function elementor_additional_fonts( $additional_fonts ) {
        $fonts = $this->get_registered_fonts();
        foreach ( $fonts as $font_name => $font_url ) {
            // 'custom' タイプで登録 → V4 React ピッカー対応
            $additional_fonts[ $font_name ] = self::FONT_TYPE;
        }
        return $additional_fonts;
    }

    /* ============================================================
       [Layer A/D/E/F] WordPress スタイルキューで @font-face CSS 配信
    ============================================================ */
    public function enqueue_font_css() {
        $fonts = $this->get_registered_fonts();
        if ( empty( $fonts ) ) {
            return;
        }

        $css = $this->generate_font_face_css( $fonts );

        if ( ! wp_style_is( 'jfs-custom-fonts', 'registered' ) ) {
            wp_register_style( 'jfs-custom-fonts', false, array(), null );
        }
        if ( ! wp_style_is( 'jfs-custom-fonts', 'enqueued' ) ) {
            wp_enqueue_style( 'jfs-custom-fonts' );
            wp_add_inline_style( 'jfs-custom-fonts', $css );
        }
    }

    /* ============================================================
       [Layer B/C/E] 直接 <style> タグを echo
       wp_head(priority=5), wp_footer(priority=1), elementor/editor/footer
       → スタイルキューが機能しない場合の究極フォールバック
    ============================================================ */
    public function print_font_css_direct() {
        $fonts = $this->get_registered_fonts();
        if ( empty( $fonts ) ) {
            return;
        }
        $css = $this->generate_font_face_css( $fonts );
        // ID で重複出力を防ぐ（同一ページで複数フックが呼ばれる場合）
        static $already_printed = false;
        if ( ! $already_printed ) {
            $already_printed = true;
        }
        // 常に出力（ページの head / footer どちらにいても有効）
        echo '<style id="jfs-fonts-direct">' . $css . '</style>' . "\n";
    }

    /* ============================================================
       [Layer G] Elementor CSS パイプライン統合
       do_action('elementor/fonts/print_font_links/custom', $font)
       → 個別フォントの @font-face を echo
       V4 フォントタイプ 'custom' で起動
    ============================================================ */
    public function print_single_font_css( $font_name ) {
        $fonts = $this->get_registered_fonts();
        if ( ! isset( $fonts[ $font_name ] ) ) {
            // このフォントは JFS フォントではない（他プラグインの 'custom' フォント）
            return;
        }
        $css = $this->generate_font_face_css( array( $font_name => $fonts[ $font_name ] ) );
        echo '<style id="jfs-font-' . esc_attr( sanitize_html_class( $font_name ) ) . '">'
            . $css . '</style>' . "\n";
    }

    /* ============================================================
       [Layer H] V4 エディター JavaScript 注入
       ─────────────────────────────────────────────────────────
       V4 の JS enqueueFont() は 'custom' タイプに fontUrl を設定しないため
       CSS が editor / preview に届かない。PHP フック
       'elementor/editor/after_enqueue_scripts' でインライン JS を注入し
       editor document と preview iframe の両方に @font-face を注入する。
    ============================================================ */
    public function inject_font_css_to_v4_editor() {
        $fonts = $this->get_registered_fonts();
        if ( empty( $fonts ) ) {
            return;
        }

        $css         = $this->generate_font_face_css( $fonts );
        $css_escaped = wp_json_encode( $css );
        ?>
<script id="jfs-v4-font-injector">
(function() {
    'use strict';
    var jfsCss = <?php echo $css_escaped; ?>;
    var STYLE_ID = 'jfs-fonts-injected';

    /**
     * 指定された document に <style> を注入（重複防止）
     */
    function injectStyle( doc ) {
        if ( ! doc || ! doc.head ) return;
        if ( doc.getElementById( STYLE_ID ) ) return;
        var s   = doc.createElement( 'style' );
        s.id    = STYLE_ID;
        s.textContent = jfsCss;
        doc.head.appendChild( s );
    }

    /**
     * プレビュー iframe の document を取得（V3/V4 両対応）
     */
    function getPreviewDoc() {
        // V4 の $preview (jQuery) 経由
        if ( window.elementor && window.elementor.$preview && window.elementor.$preview[0] ) {
            try {
                var d = window.elementor.$preview[0].contentDocument
                     || window.elementor.$preview[0].contentWindow.document;
                if ( d ) return d;
            } catch(e) {}
        }
        // getElementById フォールバック
        var iframe = document.getElementById( 'elementor-preview-iframe' );
        if ( iframe ) {
            try {
                return iframe.contentDocument || iframe.contentWindow.document;
            } catch(e) {}
        }
        return null;
    }

    /**
     * エディター UI + プレビュー iframe に CSS を注入
     */
    function injectAll() {
        // エディター UI 自体
        injectStyle( document );

        // プレビュー iframe
        var previewDoc = getPreviewDoc();
        if ( previewDoc ) {
            injectStyle( previewDoc );
        }
    }

    /**
     * iframe の load イベントを監視してフォントを再注入
     */
    function watchPreviewIframe() {
        // V4: window.elementor.$preview
        if ( window.elementor && window.elementor.$preview && window.elementor.$preview[0] ) {
            window.elementor.$preview[0].addEventListener( 'load', function() {
                var d = getPreviewDoc();
                if ( d ) injectStyle( d );
            });
            return true;
        }
        // フォールバック: getElementById
        var iframe = document.getElementById( 'elementor-preview-iframe' );
        if ( iframe ) {
            iframe.addEventListener( 'load', function() {
                var d = getPreviewDoc();
                if ( d ) injectStyle( d );
            });
            return true;
        }
        return false;
    }

    /** 複数タイミングで確実に実行 */
    function setup() {
        injectAll();
        watchPreviewIframe();
    }

    // 即時実行
    setup();

    // Elementor 初期化イベント（V3/V4 共通）
    window.addEventListener( 'elementor/initialized', function() {
        // 少し待ってから実行（iframe 生成を待つ）
        requestAnimationFrame( function() {
            setup();
            // さらに少し待って再試行
            setTimeout( setup, 500 );
            setTimeout( setup, 1500 );
        });
    });

    // DOM 読み込み完了後に再試行
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', function() {
            setTimeout( setup, 300 );
        });
    } else {
        setTimeout( setup, 300 );
    }

    // Elementor V4 の 'elementor:init' イベント
    window.addEventListener( 'elementor:init', function() {
        requestAnimationFrame( function() {
            setup();
            setTimeout( setup, 800 );
        });
    });

    // Elementor V4: editor documents の attach-preview コマンド後に再注入
    window.addEventListener( 'elementor/commands/run/after', function(e) {
        if ( e && e.detail && e.detail.command === 'editor/documents/attach-preview' ) {
            setTimeout( function() {
                var d = getPreviewDoc();
                if ( d ) injectStyle( d );
            }, 200 );
        }
    });

})();
</script>
        <?php
    }

    /* ============================================================
       @font-face CSS 生成ヘルパー
    ============================================================ */
    private function generate_font_face_css( array $fonts ) {
        $css = '';
        foreach ( $fonts as $font_name => $font_url ) {
            $safe_name = esc_attr( $font_name );
            $safe_url  = esc_url( $font_url );
            $css .= "@font-face{"
                . "font-family:'{$safe_name}';"
                . "src:url('{$safe_url}') format('woff2');"
                . "font-weight:normal;"
                . "font-style:normal;"
                . "font-display:swap;"
                . "}";
        }
        return $css;
    }

    /* ============================================================
       登録済みフォント一覧
       戻り値: [ 'FontName' => 'https://...woff2', ... ]
    ============================================================ */
    public function get_registered_fonts() {
        return get_option( 'jfs_registered_fonts', array() );
    }

    /* ============================================================
       フォントを DB に登録 / 更新
    ============================================================ */
    private function register_font( $font_name, $font_url ) {
        $fonts = $this->get_registered_fonts();
        $fonts[ $font_name ] = $font_url;
        update_option( 'jfs_registered_fonts', $fonts, false );
    }

    /* ============================================================
       フォントを DB から削除
    ============================================================ */
    private function unregister_font_by_url( $font_url ) {
        $fonts = $this->get_registered_fonts();
        foreach ( $fonts as $name => $url ) {
            if ( $url === $font_url ) {
                unset( $fonts[ $name ] );
            }
        }
        update_option( 'jfs_registered_fonts', $fonts, false );
    }

    /* ============================================================
       Ajax: WOFF2 ファイルをサーバーに保存 → Elementor に登録
    ============================================================ */
    public function ajax_save_font() {
        check_ajax_referer( 'jfs_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
            return;
        }

        if ( ! file_exists( JFS_UPLOAD_DIR ) ) {
            wp_mkdir_p( JFS_UPLOAD_DIR );
        }

        if ( empty( $_FILES['woff2_file'] ) || $_FILES['woff2_file']['error'] === UPLOAD_ERR_NO_FILE ) {
            wp_send_json_error( array( 'message' => 'ファイルが送信されていません。' ) );
            return;
        }

        $file = $_FILES['woff2_file'];
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( array( 'message' => 'アップロードエラー (code: ' . intval( $file['error'] ) . ')' ) );
            return;
        }

        if ( $file['size'] > 30 * 1024 * 1024 ) {
            wp_send_json_error( array( 'message' => 'ファイルサイズが上限（30MB）を超えています。' ) );
            return;
        }

        // WOFF2 マジックバイト確認
        $handle = fopen( $file['tmp_name'], 'rb' );
        $magic  = fread( $handle, 4 );
        fclose( $handle );
        if ( $magic !== 'wOF2' ) {
            wp_send_json_error( array( 'message' => '有効な WOFF2 ファイルではありません。' ) );
            return;
        }

        $orig_name  = isset( $_POST['orig_name'] ) ? sanitize_file_name( $_POST['orig_name'] ) : 'font';
        $base       = preg_replace( '/\.(otf|ttf|woff|woff2)$/i', '', $orig_name );
        $font_name  = $base;
        $filename   = $base . '.woff2';
        $dest       = JFS_UPLOAD_DIR . $filename;

        if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
            wp_send_json_error( array( 'message' => 'ファイルの保存に失敗しました。' ) );
            return;
        }

        $font_url    = JFS_UPLOAD_URL . $filename;
        $glyph_count = isset( $_POST['glyph_count'] ) ? intval( $_POST['glyph_count'] ) : 0;
        $subset_type = isset( $_POST['subset_type'] ) ? intval( $_POST['subset_type'] ) : 0;

        $this->register_font( $font_name, $font_url );

        wp_send_json_success( array(
            'message'      => '変換・保存が完了しました！Elementor のフォント選択に「' . esc_html( $font_name ) . '」として追加されました。',
            'filename'     => $filename,
            'font_name'    => $font_name,
            'download_url' => $font_url,
            'filesize'     => size_format( filesize( $dest ) ),
            'glyph_count'  => $glyph_count > 0 ? $glyph_count : null,
            'subset_type'  => $subset_type,
        ) );
    }

    /* ============================================================
       Ajax: 漢字データ返却
    ============================================================ */
    public function ajax_get_kanji() {
        check_ajax_referer( 'jfs_kanji', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
            return;
        }

        $type     = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : '';
        $file_map = array(
            'joyo' => JFS_PLUGIN_DIR . 'data/joyo_kanji.txt',
            'jis1' => JFS_PLUGIN_DIR . 'data/jis1_kanji.txt',
            'jis2' => JFS_PLUGIN_DIR . 'data/jis2_kanji.txt',
        );

        if ( ! isset( $file_map[ $type ] ) ) {
            wp_send_json_error( array( 'message' => '不正なタイプです。' ) );
            return;
        }

        $data = file_get_contents( $file_map[ $type ] );
        if ( false === $data ) {
            wp_send_json_error( array( 'message' => 'データファイルの読み込みに失敗しました。' ) );
            return;
        }

        wp_send_json_success( array( 'chars' => trim( $data ) ) );
    }

    /* ============================================================
       Ajax: ファイル削除 → Elementor 登録も解除
    ============================================================ */
    public function ajax_delete() {
        check_ajax_referer( 'jfs_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => '権限がありません。' ) );
            return;
        }

        $filename = isset( $_POST['filename'] ) ? sanitize_file_name( $_POST['filename'] ) : '';
        if ( empty( $filename ) ) {
            wp_send_json_error( array( 'message' => 'ファイル名が不正です。' ) );
            return;
        }

        $filepath    = JFS_UPLOAD_DIR . $filename;
        $real_upload = realpath( JFS_UPLOAD_DIR );
        $real_file   = realpath( $filepath );

        if ( false === $real_file || false === $real_upload
            || strpos( $real_file, $real_upload ) !== 0 ) {
            wp_send_json_error( array( 'message' => '不正なファイルパスです。' ) );
            return;
        }

        if ( file_exists( $filepath ) && unlink( $filepath ) ) {
            $font_url = JFS_UPLOAD_URL . $filename;
            $this->unregister_font_by_url( $font_url );
            wp_send_json_success( array( 'message' => 'ファイルを削除しました。' ) );
        } else {
            wp_send_json_error( array( 'message' => 'ファイルの削除に失敗しました。' ) );
        }
    }

    /* ============================================================
       変換済みファイル一覧
    ============================================================ */
    public function get_converted_files() {
        if ( ! file_exists( JFS_UPLOAD_DIR ) ) {
            return array();
        }
        $files      = glob( JFS_UPLOAD_DIR . '*.woff2' );
        $result     = array();
        $registered = $this->get_registered_fonts();

        if ( ! $files ) return $result;

        foreach ( $files as $file ) {
            $fname        = basename( $file );
            $file_url     = JFS_UPLOAD_URL . $fname;
            $is_registered = in_array( $file_url, $registered, true );
            $reg_name     = $is_registered ? array_search( $file_url, $registered, true ) : '';

            $result[] = array(
                'filename'      => $fname,
                'filesize'      => size_format( filesize( $file ) ),
                'modified'      => date_i18n( 'Y/m/d H:i:s', filemtime( $file ) ),
                'url'           => $file_url,
                'is_registered' => $is_registered,
                'font_name'     => $reg_name,
            );
        }

        usort( $result, function( $a, $b ) {
            return strcmp( $b['modified'], $a['modified'] );
        } );

        return $result;
    }
}

register_activation_hook( __FILE__, array( 'Japanese_Font_Subsetter', 'on_activate' ) );
Japanese_Font_Subsetter::get_instance();
