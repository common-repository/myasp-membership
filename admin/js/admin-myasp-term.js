/**
 * タグ「編集」画面初期化
 */
( function ( $ ) {
	// 初期表示
	if ( !$( '#term_meta\\[myasp_managed_post\\]' ).prop( 'checked' ) ) {
		$( 'tr.myasp-input-field.myasp-toggle-field' ).hide();
	}

	// 許可シナリオ複数選択コンポーネント(Select2)初期化
	$( '.allowed-item_list' ).select2( {
		placeholder: '(省略可) クリックしてシナリオを選択(複数選択可能)',
		allowClear: true,
	} );

	// 「連携する」チェックで、以降の項目を表示する
	$( '#term_meta\\[myasp_managed_post\\]' ).on( 'change', function ( e ) {
		if ( $( e.target ).prop( 'checked' ) ) {
			$( 'tr.myasp-input-field.myasp-toggle-field' ).show();
		} else {
			$( 'tr.myasp-input-field.myasp-toggle-field' ).hide();
		}
	} );
} )( jQuery );
