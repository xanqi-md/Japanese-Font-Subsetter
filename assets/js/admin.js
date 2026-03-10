/**
 * Japanese Font Subsetter — Admin JavaScript v2.2
 * ブラウザ内で opentype.js + wawoff2 (WASM) を使用してフォントを処理します。
 * v2.1: fetchKanji を jQuery Deferred 明示実装に修正、デバッグログ追加
 * v2.2: Elementor連携対応 — ファイル名を「元名.woff2」に変更
 *       変換成功時に font_name を表示、ファイルリスト行も更新
 */
(function ($) {
    'use strict';

    /* ============================================================
       グローバル状態
    ============================================================ */
    var selectedFile     = null;
    var isConverting     = false;
    var woff2ModuleReady = false;
    var kanjiCache       = {};

    /* ============================================================
       wawoff2 WASM モジュール初期化待ち
    ============================================================ */
    if (window.JFSWoff2Ready) {
        window.JFSWoff2Ready.then(function () {
            woff2ModuleReady = true;
            console.log('[JFS] wawoff2 WASM ready');
        }).catch(function (e) {
            console.error('[JFS] wawoff2 init failed:', e);
        });
    } else {
        console.warn('[JFS] window.JFSWoff2Ready が見つかりません。wp_add_inline_script を確認してください。');
    }

    /* ============================================================
       文字セット定義
    ============================================================ */
    function getHiragana() {
        var chars = [];
        for (var cp = 0x3041; cp <= 0x3096; cp++) { chars.push(String.fromCodePoint(cp)); }
        for (var cp2 = 0x309D; cp2 <= 0x309F; cp2++) { chars.push(String.fromCodePoint(cp2)); }
        return chars;
    }

    function getKatakana() {
        var chars = [];
        for (var cp = 0x30A1; cp <= 0x30FA; cp++) { chars.push(String.fromCodePoint(cp)); }
        [0x30FC, 0x30FD, 0x30FE, 0x30FF].forEach(function (cp) { chars.push(String.fromCodePoint(cp)); });
        for (var cp2 = 0xFF66; cp2 <= 0xFF9F; cp2++) { chars.push(String.fromCodePoint(cp2)); }
        return chars;
    }

    function getHalfAlphanumeric() {
        var chars = [];
        for (var cp = 0x0020; cp <= 0x007E; cp++) { chars.push(String.fromCodePoint(cp)); }
        return chars;
    }

    function getFullHalfAlphanumeric() {
        var chars = getHalfAlphanumeric();
        for (var cp = 0xFF01; cp <= 0xFF60; cp++) { chars.push(String.fromCodePoint(cp)); }
        for (var cp2 = 0xFFE0; cp2 <= 0xFFE6; cp2++) { chars.push(String.fromCodePoint(cp2)); }
        return chars;
    }

    function getSymbols() {
        var ranges = [
            [0x3000, 0x303F], [0x2000, 0x206F], [0x2190, 0x21FF],
            [0x2200, 0x22FF], [0x25A0, 0x25FF], [0x2600, 0x26FF],
            [0x3200, 0x32FF], [0xFE30, 0xFE4F], [0xFF00, 0xFF60]
        ];
        var chars = [];
        ranges.forEach(function (r) {
            for (var cp = r[0]; cp <= r[1]; cp++) { chars.push(String.fromCodePoint(cp)); }
        });
        return chars;
    }

    function getKanaAndSymbols() {
        return getHiragana().concat(getKatakana(), getFullHalfAlphanumeric(), getSymbols());
    }

    /* ============================================================
       漢字データ取得（AJAX + キャッシュ）
       ★ v2.1修正: $.ajax().then() の代わりに jQuery Deferred を明示使用
       　 $.when() との互換性を確保するため
    ============================================================ */
    function fetchKanji(type) {
        // キャッシュがある場合はそのまま返す
        if (kanjiCache[type]) {
            console.log('[JFS] fetchKanji cache hit:', type, '文字数:', kanjiCache[type].length);
            return $.Deferred().resolve(kanjiCache[type].split('')).promise();
        }

        // 明示的な jQuery Deferred を使用（$.when() との互換性のため）
        var dfd = $.Deferred();

        $.ajax({
            url:  jfsData.ajaxurl,
            type: 'GET',
            data: {
                action: 'jfs_get_kanji',
                nonce:  jfsData.kanji_nonce,
                type:   type
            }
        }).done(function (resp) {
            if (resp && resp.success && resp.data && resp.data.chars) {
                var chars = resp.data.chars;
                console.log('[JFS] fetchKanji success:', type, '文字数:', chars.length);
                kanjiCache[type] = chars;
                dfd.resolve(chars.split(''));
            } else {
                var errMsg = (resp && resp.data && resp.data.message)
                    ? resp.data.message
                    : '漢字データの取得に失敗しました (' + type + ')';
                console.error('[JFS] fetchKanji error:', errMsg);
                dfd.reject(errMsg);
            }
        }).fail(function (xhr, status, error) {
            var errMsg = '漢字データ取得エラー (' + type + '): ' + status + ' ' + error;
            console.error('[JFS]', errMsg, 'HTTP:', xhr.status);
            dfd.reject(errMsg);
        });

        return dfd.promise();
    }

    /* ============================================================
       サブセット文字セット取得 (jQuery Deferred 版)
    ============================================================ */
    function getCharSet(subsetType) {
        var t = parseInt(subsetType, 10);
        console.log('[JFS] getCharSet type:', t);
        var dfd = $.Deferred();
        switch (t) {
            case 0: dfd.resolve(null); break;
            case 1: dfd.resolve(getHiragana()); break;
            case 2: dfd.resolve(getKatakana()); break;
            case 3: dfd.resolve(getHalfAlphanumeric()); break;
            case 4: dfd.resolve(getFullHalfAlphanumeric()); break;
            case 5: dfd.resolve(getKanaAndSymbols()); break;
            case 6:
                fetchKanji('joyo').done(function (joyo) {
                    dfd.resolve(getKanaAndSymbols().concat(joyo));
                }).fail(function (e) { dfd.reject(e); });
                break;
            case 7:
                // ★ v2.1修正: 明示的 Deferred を使って $.when() 互換を確保
                $.when(fetchKanji('joyo'), fetchKanji('jis1')).done(function (joyo, jis1) {
                    console.log('[JFS] joyo:', joyo ? joyo.length : 'null', 'jis1:', jis1 ? jis1.length : 'null');
                    dfd.resolve(getKanaAndSymbols().concat(joyo, jis1));
                }).fail(function (e) { dfd.reject(e); });
                break;
            case 8:
                $.when(fetchKanji('joyo'), fetchKanji('jis1'), fetchKanji('jis2')).done(function (joyo, jis1, jis2) {
                    console.log('[JFS] joyo:', joyo ? joyo.length : 'null', 'jis1:', jis1 ? jis1.length : 'null', 'jis2:', jis2 ? jis2.length : 'null');
                    dfd.resolve(getKanaAndSymbols().concat(joyo, jis1, jis2));
                }).fail(function (e) { dfd.reject(e); });
                break;
            default: dfd.resolve(null); break;
        }
        return dfd.promise();
    }

    /* ============================================================
       フォントサブセット処理 (opentype.js)
    ============================================================ */
    function subsetFont(fontBuffer, charSet) {
        if (!charSet || charSet.length === 0) {
            console.log('[JFS] subsetFont: charSet empty or null → 元バッファを返す');
            return { buffer: fontBuffer, glyphCount: 0 };
        }

        var font = opentype.parse(fontBuffer);
        console.log('[JFS] subsetFont: 元グリフ数:', font.glyphs.length);

        var charSetUniq = [];
        var seen = {};
        charSet.forEach(function (ch) {
            if (!seen[ch]) { seen[ch] = true; charSetUniq.push(ch); }
        });
        console.log('[JFS] subsetFont: ユニーク文字数:', charSetUniq.length);

        var glyphIdsToKeep = {};
        glyphIdsToKeep[0] = true; // .notdef
        charSetUniq.forEach(function (ch) {
            var idx = font.charToGlyphIndex(ch);
            if (idx > 0) { glyphIdsToKeep[idx] = true; }
        });

        var sortedIds = Object.keys(glyphIdsToKeep)
            .map(Number)
            .sort(function (a, b) { return a - b; });

        console.log('[JFS] subsetFont: 保持するグリフID数:', sortedIds.length);

        var glyphs = sortedIds.map(function (id) {
            return font.glyphs.get(id);
        }).filter(function (g) { return !!g; });

        console.log('[JFS] subsetFont: 新フォントのグリフ数:', glyphs.length);

        var familyName = font.getEnglishName('fontFamily') || 'Font';
        var styleName  = font.getEnglishName('fontSubfamily') || 'Regular';

        var newFont = new opentype.Font({
            familyName: familyName,
            styleName:  styleName,
            unitsPerEm: font.unitsPerEm,
            ascender:   font.ascender,
            descender:  font.descender,
            glyphs:     glyphs
        });

        var ttfBuf = newFont.toArrayBuffer();
        console.log('[JFS] subsetFont: TTF サイズ:', (ttfBuf.byteLength / 1024).toFixed(1), 'KB');
        return { buffer: ttfBuf, glyphCount: glyphs.length };
    }

    /* ============================================================
       WOFF2 圧縮 (wawoff2 WASM)
    ============================================================ */
    function compressToWoff2(ttfBuffer) {
        var mod = window.JFSWoff2Module;
        if (!mod || typeof mod.compress !== 'function') {
            throw new Error('wawoff2 モジュールが初期化されていません。ページを再読み込みしてください。');
        }
        var result = mod.compress(new Uint8Array(ttfBuffer));
        if (result === false) {
            throw new Error('WOFF2 圧縮に失敗しました。');
        }
        console.log('[JFS] WOFF2 サイズ:', (result.length / 1024).toFixed(1), 'KB');
        return result;
    }

    /* ============================================================
       UI ヘルパー
    ============================================================ */
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function fmtSize(bytes) {
        if (bytes >= 1024 * 1024) { return (bytes / 1024 / 1024).toFixed(2) + ' MB'; }
        return (bytes / 1024).toFixed(1) + ' KB';
    }

    function setProgress(pct, label) {
        $('#jfs-progress-fill').css('width', pct + '%');
        if (label) { $('#jfs-progress-text').text(label); }
    }

    function showProgress() {
        $('#jfs-progress').show();
        setProgress(0, '初期化中...');
    }

    function hideProgress() {
        $('#jfs-progress').hide();
    }

    function showError(msg) {
        $('#jfs-result').html('<div class="jfs-result-error">❌ ' + escHtml(msg) + '</div>').show();
    }

    function showSuccess(data) {
        var html = '<div class="jfs-result-success">'
                 + '<strong>✅ ' + escHtml(data.message) + '</strong><br>';
        if (data.font_name) {
            html += '🎨 Elementor フォント名: <strong>' + escHtml(data.font_name) + '</strong><br>';
        }
        html += 'ファイル名: <code>' + escHtml(data.filename) + '</code><br>'
              + 'ファイルサイズ: ' + escHtml(data.filesize) + '<br>';
        if (data.glyphCount) { html += 'グリフ数: ' + escHtml(String(data.glyphCount)) + '<br>'; }
        if (data.elapsed) { html += '処理時間: ' + escHtml(data.elapsed) + '<br>'; }
        html += '<a href="' + escHtml(data.download_url) + '" class="jfs-dl-link" download>'
             +  '⬇️ ダウンロード</a></div>';
        $('#jfs-result').html(html).show();
    }

    /* ============================================================
       ドロップゾーン
    ============================================================ */
    var $dropzone  = $('#jfs-dropzone');
    var $fileInput = $('#jfs-font-file');

    $dropzone.on('click', function (e) {
        if (!$(e.target).is('label') && !$(e.target).is('#jfs-font-file')) {
            $fileInput.trigger('click');
        }
    });

    $fileInput.on('change', function () {
        if (this.files && this.files[0]) {
            updateSelectedFile(this.files[0]);
        }
    });

    $dropzone.on('dragover dragenter', function (e) {
        e.preventDefault(); e.stopPropagation();
        $(this).addClass('dragover');
    });

    $dropzone.on('dragleave dragend', function (e) {
        e.preventDefault(); e.stopPropagation();
        $(this).removeClass('dragover');
    });

    $dropzone.on('drop', function (e) {
        e.preventDefault(); e.stopPropagation();
        $(this).removeClass('dragover');
        var files = e.originalEvent.dataTransfer.files;
        if (files && files[0]) {
            var ext = files[0].name.split('.').pop().toLowerCase();
            if (['otf', 'ttf', 'woff'].indexOf(ext) === -1) {
                showError('対応形式は OTF / TTF / WOFF のみです。');
                return;
            }
            try {
                var dt = new DataTransfer();
                dt.items.add(files[0]);
                $fileInput[0].files = dt.files;
            } catch (e2) { /* DataTransfer未対応ブラウザは無視 */ }
            updateSelectedFile(files[0]);
        }
    });

    function updateSelectedFile(file) {
        selectedFile = file;
        $('#jfs-selected-file').text('📄 ' + file.name + ' (' + fmtSize(file.size) + ')');
        $dropzone.addClass('has-file');
        $('#jfs-convert-btn').prop('disabled', false);
        $('#jfs-result').hide().empty();
    }

    /* ============================================================
       サブセット選択 UI
    ============================================================ */
    $(document).on('change', 'input[name="subset_type"]', function () {
        $('.jfs-subset-item').removeClass('active');
        $(this).closest('.jfs-subset-item').addClass('active');
        console.log('[JFS] subset_type 変更:', $(this).val());
    });

    /* ============================================================
       変換ボタン
    ============================================================ */
    $('#jfs-convert-btn').on('click', function () {
        if (isConverting) { return; }
        if (!selectedFile) {
            showError('フォントファイルを選択してください。');
            return;
        }
        if (!woff2ModuleReady) {
            showError('WOFF2 モジュールの初期化中です。しばらく待ってからお試しください。');
            return;
        }
        if (typeof opentype === 'undefined') {
            showError('opentype.js が読み込まれていません。ページを再読み込みしてください。');
            return;
        }

        var subsetType = parseInt($('input[name="subset_type"]:checked').val() || '0', 10);
        console.log('[JFS] 変換開始 subsetType:', subsetType, 'ファイル:', selectedFile.name, fmtSize(selectedFile.size));
        startConversion(selectedFile, subsetType);
    });

    /* ============================================================
       変換メインフロー
    ============================================================ */
    function startConversion(file, subsetType) {
        isConverting = true;
        var tStart   = Date.now();
        var $btn     = $('#jfs-convert-btn');
        var glyphCountResult = 0; // グリフ数を保持

        $btn.prop('disabled', true);
        $btn.find('.jfs-btn-text').hide();
        $btn.find('.jfs-btn-loading').show();
        $('#jfs-result').hide().empty();
        showProgress();
        setProgress(5, 'フォントファイルを読み込んでいます...');

        // Step 1: FileReader でバイナリ読み込み
        var reader = new FileReader();
        reader.readAsArrayBuffer(file);

        reader.onerror = function () {
            finishConversion(false, 'ファイルの読み込みに失敗しました。');
        };

        reader.onload = function (ev) {
            var fontBuffer = ev.target.result;
            console.log('[JFS] フォント読み込み完了:', (fontBuffer.byteLength / 1024).toFixed(1), 'KB');
            setProgress(15, '文字セットを準備しています...');

            // Step 2: 文字セット取得
            getCharSet(subsetType)
                .done(function (charSet) {
                    if (charSet) {
                        console.log('[JFS] charSet 取得完了 文字数:', charSet.length);
                    } else {
                        console.log('[JFS] charSet = null (サブセットなし)');
                    }
                    setProgress(30, 'フォントを解析しています...');

                    // Step 3: サブセット処理
                    setTimeout(function () {
                        var ttfBuffer;
                        try {
                            setProgress(50, 'サブセット化しています...');
                            var subsetResult = subsetFont(fontBuffer, charSet);
                            ttfBuffer = subsetResult.buffer;
                            glyphCountResult = subsetResult.glyphCount;
                        } catch (e) {
                            console.error('[JFS] サブセット化エラー:', e);
                            finishConversion(false, 'サブセット化エラー: ' + e.message);
                            return;
                        }

                        setProgress(72, 'WOFF2 へ圧縮しています...');

                        // Step 4: WOFF2 圧縮
                        setTimeout(function () {
                            var woff2Data;
                            try {
                                woff2Data = compressToWoff2(ttfBuffer);
                            } catch (e) {
                                console.error('[JFS] WOFF2 圧縮エラー:', e);
                                finishConversion(false, 'WOFF2 圧縮エラー: ' + e.message);
                                return;
                            }

                            setProgress(88, 'サーバーへアップロードしています...');

                            // Step 5: WOFF2 をサーバーへ送信
                            var blob = new Blob([woff2Data], { type: 'application/octet-stream' });
                            var formData = new FormData();
                            formData.append('action',       'jfs_save_font');
                            formData.append('nonce',        jfsData.nonce);
                            // ファイル名は「元のベース名.woff2」で送信
                            // → PHP 側でそのまま保存され Elementor のフォント名になる
                            var woff2FileName = file.name.replace(/\.[^.]+$/, '') + '.woff2';
                            formData.append('woff2_file',   blob, woff2FileName);
                            formData.append('orig_name',    file.name);
                            formData.append('subset_type',  subsetType);
                            formData.append('glyph_count',  String(glyphCountResult));

                            console.log('[JFS] アップロード: subset_type=' + subsetType + ', woff2=' + (woff2Data.length / 1024).toFixed(1) + 'KB');

                            $.ajax({
                                url:         jfsData.ajaxurl,
                                type:        'POST',
                                data:        formData,
                                processData: false,
                                contentType: false,
                                timeout:     120000
                            }).done(function (response) {
                                setProgress(100, '完了！');
                                hideProgress();
                                if (response.success) {
                                    response.data.elapsed    = ((Date.now() - tStart) / 1000).toFixed(1) + ' 秒';
                                    response.data.glyphCount = glyphCountResult > 0 ? glyphCountResult : undefined;
                                    showSuccess(response.data);
                                    refreshFilesList(response.data);
                                } else {
                                    showError((response.data && response.data.message) || '保存に失敗しました。');
                                }
                                finishConversion(true);
                            }).fail(function (xhr, status) {
                                var msg = status === 'timeout'
                                    ? 'アップロードがタイムアウトしました。'
                                    : '通信エラーが発生しました（status: ' + status + '）';
                                finishConversion(false, msg);
                            });

                        }, 50);
                    }, 50);
                })
                .fail(function (errMsg) {
                    finishConversion(false, String(errMsg) || '文字セットの取得に失敗しました。');
                });
        };
    }

    function finishConversion(success, errorMsg) {
        isConverting = false;
        hideProgress();
        var $btn = $('#jfs-convert-btn');
        $btn.prop('disabled', selectedFile ? false : true);
        $btn.find('.jfs-btn-text').show();
        $btn.find('.jfs-btn-loading').hide();
        if (!success && errorMsg) {
            showError(errorMsg);
            console.error('[JFS]', errorMsg);
        }
    }

    /* ============================================================
       ファイルリスト更新
    ============================================================ */
    function refreshFilesList(data) {
        var $list  = $('#jfs-files-list');
        var $tbody = $list.find('.jfs-files-table tbody');

        var now = new Date();
        function pad(n) { return String(n).padStart(2, '0'); }
        var dateStr = now.getFullYear() + '/' + pad(now.getMonth() + 1) + '/' + pad(now.getDate())
                    + ' ' + pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());

        // フォント名セル（font_name があれば表示）
        var fontNameCell = '<span class="dashicons dashicons-media-document"></span>';
        if (data.font_name) {
            fontNameCell += ' <strong>' + escHtml(data.font_name) + '</strong>'
                         + '<br><small style="color:#64748b;">' + escHtml(data.filename) + '</small>';
        } else {
            fontNameCell += ' ' + escHtml(data.filename);
        }
        var elementorCell = data.font_name
            ? '<span class="jfs-elementor-status jfs-elementor-status--ok">✅ 登録済み</span>'
            : '<span class="jfs-elementor-status jfs-elementor-status--ng">— 未登録</span>';

        var rowHtml = '<tr id="jfs-file-' + md5simple(data.filename) + '">'
            + '<td>' + fontNameCell + '</td>'
            + '<td>' + escHtml(data.filesize) + '</td>'
            + '<td>' + dateStr + '</td>'
            + '<td>' + elementorCell + '</td>'
            + '<td class="jfs-actions">'
            + '<a href="' + escHtml(data.download_url) + '" class="button button-small jfs-dl-btn" download>⬇️ DL</a> '
            + '<button class="button button-small jfs-delete-btn" data-filename="' + escHtml(data.filename) + '">🗑️ 削除</button>'
            + '</td></tr>';

        if ($tbody.length) {
            $tbody.prepend(rowHtml);
        } else {
            $list.html(
                '<table class="jfs-files-table widefat striped">'
                + '<thead><tr><th>フォント名 / ファイル名</th><th>サイズ</th><th>作成日時</th><th>Elementor</th><th>操作</th></tr></thead>'
                + '<tbody>' + rowHtml + '</tbody></table>'
            );
        }
    }

    /* ============================================================
       ファイル削除
    ============================================================ */
    $(document).on('click', '.jfs-delete-btn', function () {
        var filename = $(this).data('filename');
        if (!confirm('「' + filename + '」を削除しますか？')) { return; }
        var $row = $(this).closest('tr');
        $.ajax({
            url:  jfsData.ajaxurl,
            type: 'POST',
            data: { action: 'jfs_delete', nonce: jfsData.nonce, filename: filename }
        }).done(function (resp) {
            if (resp.success) {
                $row.fadeOut(300, function () { $(this).remove(); });
            } else {
                alert('削除失敗: ' + ((resp.data && resp.data.message) || '不明なエラー'));
            }
        }).fail(function () {
            alert('通信エラーが発生しました。');
        });
    });

    /* ============================================================
       ユーティリティ
    ============================================================ */
    function md5simple(str) {
        var hash = 0;
        for (var i = 0; i < str.length; i++) {
            hash = ((hash << 5) - hash) + str.charCodeAt(i);
            hash |= 0;
        }
        return Math.abs(hash).toString(16);
    }

})(jQuery);
