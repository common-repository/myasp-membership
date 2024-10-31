/**
 * タグ一覧画面js
 * ・クイック編集ボタン押下時初期化
 *	 ・一覧の追加項目の値を、クイック編集の項目へ転記する
 */
 ( function ( $ ) {
	const wpInlineEdit = window['inlineEditTax'].edit;

	const radioMyaspOpenStatus =
		'<label><input type="radio" name="term_meta[myasp_open_status]" value="1">公開</input></label><label><input type="radio" name="term_meta[myasp_open_status]" value="0">非公開</input></label>';
	const radioMyaspAutoRedirect =
		'<label><input type="radio" name="term_meta[myasp_auto_redirect]" value="1">記事へリダイレクトする</input></label><label><input type="radio" name="term_meta[myasp_auto_redirect]" value="0">サンクスページを表示する</input></label>';

	window['inlineEditTax'].edit = function ( id ) {
		wpInlineEdit.apply( this, arguments );

		let postId = 0;
		if ( typeof id === 'object' ) {
			postId = parseInt( this.getId( id ) );
		}

		if ( postId > 0 ) {
			const editRow = $( '#edit-' + postId ); // クイック編集行
			const postRow = $( '#tag-' + postId ); // 表示行

			// MyASP連携(checkbox)
			const myaspManagedPost = $(
				'.column-myasp_managed_post input',
				postRow
			).attr( 'checked' );
			$( 'input[name="term_meta[myasp_managed_post]"] ', editRow ).prop(
				'checked',
				myaspManagedPost === 'checked'
			);
			// 初期表示（チェックを付けたら全表示）
			if ( myaspManagedPost != 'checked' ) {
				$( 'fieldset.myasp-toggle-field' ).hide();
			} else {
				$( 'fieldset.myasp-toggle-field' ).show();
			}

			// チェックボックスクリックで、表示を切り替える
			$(
				'input[type="checkbox"][name="term_meta[myasp_managed_post]"]',
				editRow
			).on( 'click', function ( e ) {
				if ( $( e.target ).prop( 'checked' ) ) {
					$( 'fieldset.myasp-toggle-field' ).show();
				} else {
					$( 'fieldset.myasp-toggle-field' ).hide();
				}
			} );

			// 公開状況(radio)
			const myaspOpenStatus =
				$( '.column-myasp_open_status input', postRow ).val() || '1';

			// jsで動的に追加(PHP側で追加すると、同じname属性のタグが複数行数分生成されてしまい、正しく動作しなくなるため)
			$( '.myasp_open_status', editRow ).append( radioMyaspOpenStatus );
			$(
				'input:radio[name="term_meta[myasp_open_status]"] ',
				editRow
			).val( [ myaspOpenStatus ] );

			// リストボックス用ライブラリ(Select2)の初期化
			if (
				!$( '.allowed-item_list', editRow ).hasClass(
					'select2-hidden-accessible'
				)
			) {
				// 初回だけ初期化
				$( '.allowed-item_list', editRow ).select2( {
					placeholder: '(省略可) クリックしてシナリオを選択(複数選択可能)',
					allowClear: true,
				} );

				const select2 = $( '.allowed-item_list', editRow ).select2();

				// 許可シナリオ
				const myaspAllowedItem = $(
					'.column-myasp_allowed_item input',
					postRow
				).val();
				select2.val( myaspAllowedItem.split( ',' ) ).trigger( 'change' );
			}

			// 登録後、記事へリダイレクトする(radio)
			let myaspAutoRedirect =
				$( '.column-myasp_auto_redirect input', postRow ).val() || '1';
			// jsで動的に追加(PHP側で追加すると、同じname属性のタグが複数行数分生成されてしまい、正しく動作しなくなるため)
			$( '.myasp_auto_redirect', editRow ).append(
				radioMyaspAutoRedirect
			);
			$(
				'input:radio[name="term_meta[myasp_auto_redirect]"] ',
				editRow
			).val( [ myaspAutoRedirect ] );

			// 独自ログインページURL(text)
			let myaspLoginUrl = $(
				'.column-myasp_login_url input',
				postRow
			).val();
			myaspLoginUrl = myaspLoginUrl.replace(
				'未設定<br>(プラグインで出力)',
				''
			);
			$( 'input[name="term_meta[myasp_login_url]"]', editRow ).val(
				myaspLoginUrl
			);
		}
	};
} )( jQuery );
