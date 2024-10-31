/**
 * ログインフォームに戻り先ページ(redirect_to)を追加する
 * ・何回実行実行しても問題ない(同一キーは追加しない)
 */
function myaspInitLoginForm() {
	const searchParams = new URLSearchParams( window.location.search );
	const forms = document.querySelectorAll( 'form.myasp_login_form' );
	for ( let form of forms ) {
		const url = new URL( form.action );
		let redirectTo = searchParams.get( 'redirect_to' );

		for ( const [ key, value ] of searchParams.entries() ) {
			if ( !url.searchParams.has( key ) ) {
				url.searchParams.append( key, value );
			}
		}

		if ( !redirectTo && !url.searchParams.has( 'redirect_to' ) ) {
			redirectTo = window.location.href;
			url.searchParams.append( 'redirect_to', redirectTo );
		}

		form.action = url.href;
	}
}

/**
 * 登録フォームに戻り先ページ(redirect_to)を追加する
 * ・何回実行実行しても問題ない(同一キーは追加しない)
 * ・フォームタグ(js)内でも下記と同様の処理を行っている(フォーム追加がCContentLoaded以降)
 */
function myaspInitRegisterForm() {
	// urlにパラメータ「redirect_to」があれば、<form action="～">のURLに追加(引き継ぐ)
	const searchParams = new URLSearchParams( window.location.search );
	if ( searchParams.has( 'redirect_to' ) ) {
		const forms = document.querySelectorAll( 'form.myForm' );
		for ( let form of forms ) {
			const url = new URL( form[ 'action' ] );
			for ( const [ key, value ] of searchParams.entries() ) {
				if ( !url.searchParams.has( key ) ) {
					url.searchParams.append( key, value );
				}
			}
			form.action = url.href;
		}
	}
}

document.addEventListener( 'DOMContentLoaded', function () {
	myaspInitLoginForm();
	myaspInitRegisterForm();
} );
