<?php

/**
 * Plugin Name: MyASP MemberShip
 * Description: MyASP MemberShip プラグイン（WordPressをMyASPと連携して会員サイト化するためのプラグインです。課金してない人を閲覧制限等させることが出来ます。）
 * Version: 1.0.4
 * Author: TOOLLABO Co.,Ltd.
 * Author URI: https://myasp.jp/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ログインユーザのセットアップ後(プラグインの初期化処理に利用)
add_action( 'init', 'MyASPMembershipPlugin::init' );

// プラグインアクティベート時フック(固定ページの追加、カスタムフィールド追加)
register_activation_hook( __FILE__, [ 'MyASPMembershipPlugin', 'add_custom_page' ] );

// 前方一致による自動補完リダイレクトを停止
add_filter( 'do_redirect_guess_404_permalink', '__return_false' );
// 旧スラッグからの自動補完リダイレクトを停止
// remove_action('template_redirect', 'wp_old_slug_redirect');

/**
 * タグアーカイブ(タグに紐づくページ一覧)に固定ページを含める
 * https://wordpress.org/support/topic/tags-for-pages-2/
 */
function myasp_add_page_to_tag_archive( $obj )
{
	if ( $obj->get( 'tag' ) ) {
		$obj->set( 'post_type', 'any' );
	}
}
add_action( 'pre_get_posts', 'myasp_add_page_to_tag_archive' );

/**
 * クエリストリング許可リストに追加
 */
function myasp_add_query_vars_filter( $vars )
{
	$vars[] = 'token';			// 自動ログイン用token
	$vars[] = 'redirect_to';	// ログイン時、戻り先ページ
	$vars[] = 'rid';			// ログイン時、戻り先ページのid
	$vars[] = 'page';			
	$vars[] = 'message';		// メッセージ
	$vars[] = 'reminder';		// Reminder_id
	return $vars;
}
add_filter( 'query_vars', 'myasp_add_query_vars_filter' );

/**
 * formタグ内のaction要素でのショートコードを許可
 */
function myasp_set_wp_kses_allowed_html( $tags ) 
{
	$tags['form']['action'] = true;
	return $tags;
}
add_filter( 'wp_kses_allowed_html', 'myasp_set_wp_kses_allowed_html', 10, 2 );

/**
 * [MyASP連携プラグイン]
 * admin管理メニューに追加される項目
 * ・MyASP連携設定
 * 
 * admin管理メニューの画面に変更を与える項目
 * ・タグ管理画面をカスタマイズ(MyASPに連携するシナリオを紐づける)
 * ・固定ページに下記を追加
 * 　・ログインページ、権限なしページ、非公開ページ
 * 
 * 記事画面制御
 * ・ページ閲覧制御
 * 　・ログイン状況チェック、権限(シナリオ購読)有無チェック
 * ・ショートコード追加
 * 　・ユーザー名、メールアドレス
 * 　・条件分岐用ショートコード
 * 　　・ログイン中ユーザのみに見せる領域、特定シナリオを(購読/未購読)の人に見せる領域
 * ・the_content フィルター
 * 　・「この続きをみるには～」のフィルタリング(後半を削除)
 * 　・未ログイン時、タグアーカイブでコンテンツが出力されないように制御
 */
class MyASPMembershipPlugin
{
	const VERSION						= '1.0.0';
	const PLUGIN_ID						= 'myasp-membership-plugin';
	const CREDENTIAL_ACTION				= self::PLUGIN_ID . '-nonce-action';
	const CREDENTIAL_NAME				= self::PLUGIN_ID . '-nonce-key';
	const CREDENTIAL_TAXONOMY_ACTION	= self::PLUGIN_ID . '-nonce-taxonomy-action';
	const CREDENTIAL_TAXONOMY_NAME		= self::PLUGIN_ID . '-nonce-taxonomy-key';
	const CREDENTIAL_LOGIN_ACTION		= self::PLUGIN_ID . '-nonce-login-action';
	const CREDENTIAL_LOGIN_NAME			= self::PLUGIN_ID . '-nonce-login-key';
	const CREDENTIAL_PASSWORD_ACTION	= self::PLUGIN_ID . '-nonce-password-action';
	const CREDENTIAL_PASSWORD_NAME		= self::PLUGIN_ID . '-nonce-password-key';
	const CREDENTIAL_RESET_ACTION		= self::PLUGIN_ID . '-nonce-reset-action';
	const CREDENTIAL_RESET_NAME			= self::PLUGIN_ID . '-nonce-reset-key';
	const PLUGIN_DB_PREFIX				= self::PLUGIN_ID . '_';
	const MENU_PARENT_SLUG				= self::PLUGIN_ID;
	const MENU_CONFIG_SLUG				= self::PLUGIN_ID . '-config';
	const COMPLETE_TRANSIENT_KEY		= self::PLUGIN_ID . '_complete';
	const LOGIN_ERROR_TRANSIENT_KEY		= 'transient-login-error-'; // transientのキーはmax45文字。固定文字＋session_id先頭20文字とする(合計42)

	// カスタム投稿タイプでログイン制御の有効／無効を切り替えるフラグ
	const ENABLE_CUSTOM_POST_TYPE = false;

	// 権限
	const ALLOWED_PAGE		= true;
	const NOT_LOG_IN		= false;
	const NOT_ALLOWED_PAGE	= -1; // 権限無し
	const PRIVATE_PAGE		= 0; // 非公開設定ページ

	// シナリオ情報
	public $group_items = [];	// シナリオグループ-シナリオ
	public $items = [];			// シナリオのみ
	public $registered_items = [];	// (ログイン済みの場合)登録済みシナリオ
	public $last_status = '';		// シナリオ情報取得(REST)時の直近のステータス
	public $last_message = '';		// シナリオ情報取得(REST)時の直近のメッセージ

	// 入力チェック
	const EMAIL_REGULAR_EXPRESSION = '/^[a-zA-Z0-9._+-]+@[a-zA-Z0-9_-]+[a-zA-Z0-9._-]+$/';
	const PASSWORD_REGULAR_EXPRESSION = '/^[a-zA-Z0-9!?:;=<>%&@~|#$_*+-]*$/';
	const TERM_INPUT_KEYS = ['myasp_open_status', 'myasp_auto_redirect', 'myasp_managed_post', 'myasp_login_url', 'myasp_allowed_item'];

	static function init()
	{
		return new self();
	}

	function __construct()
	{
		// REST APIエンドポイント登録(ajaxでシナリオ一覧を取得)
		add_action( 'rest_api_init', [ $this, 'add_custom_endpoint' ] );

		if ( is_admin() && is_user_logged_in() ) {
			// 管理サイトにメニューと画面を追加

			// シナリオ一覧を取得する(毎回だと時間かかるけどとりあえず)
			$this->set_item_list();

			// メニューと連携設定画面追加
			add_action( 'admin_menu', [ $this, 'set_myasp_menu' ] );

			// 連携設定のデータ保存
			add_action( 'admin_init', [ $this, 'save_myasp_config' ] );
			add_action( 'admin_notices', [ $this, 'admin_notices' ] );

			// タグ
			// 固定ページでタグ付けられるように変更
			$this->add_tag_to_page();

			// タグの編集画面
			// タグの編集画面に入力欄を追加（クイック編集は別処理add_customfield_to_quickedit)
			add_action( 'post_tag_edit_form_fields', [ $this, 'add_taxonomy_fields' ] );
			// タグの編集画面に追加した項目値の保存
			add_action( 'edited_term', [ $this, 'save_taxonomy_fileds' ] );

			//タグ管理画面(一覧)
			//タグ管理画面(一覧)に「MyASP連携」カラムヘッダーを設置する
			add_filter( 'manage_edit-post_tag_columns', [ $this, 'add_taxonomy_columns' ] );
			//タグ管理画面(一覧)に「MyASP連携チェックボックス」追加
			add_action( 'manage_post_tag_custom_column', [ $this, 'add_taxonomy_custom_fields' ], 10, 3 );

			// タグクイック編集
			// タグクイック編集に「入力項目」を追加
			add_action( 'quick_edit_custom_box', [ $this, 'add_customfield_to_quickedit' ], 10, 2 );
			// タグクイック編集用のjsファイル読み込み（一覧に表示したデータを、編集項目に転記するために使う)
			add_action( 'admin_enqueue_scripts', [ $this, 'wp_my_admin_enqueue_scripts' ] );

			// エラーメッセージ出力
			add_action( 'all_admin_notices', function () {
				global $hook_suffix, $parent_file;
				if ( 'options-general.php' === $parent_file ) return;

				$array = [ 'export-personal-data.php', 'erase-personal-data.php' ];
				if ( in_array( $hook_suffix, $array ) ) return;
				settings_errors();
			} );
		} else {
			add_action( 'wp_enqueue_scripts', function () {
				// スタイルを追加
				wp_enqueue_style( 'style', plugins_url( 'client/css/myasp-paywall.css', __FILE__ ), [], '1.0.0' );

				// 共通スクリプト追加
				wp_enqueue_script( 'myasp_client_script', plugins_url( 'client/js/myasp-client.js', __FILE__ ), [], '1.0.0', true );
			} );

			// 一般ページ
			// セッション開始(ログイン情報を保持する)
			if ( session_status() !== PHP_SESSION_ACTIVE ) {
				session_start();
			}

			// MyASPページ用アクセスコントローラー
			add_action( 'template_redirect', [ $this, 'myasp_access_controller' ] );

			// コンテンツ出力時置き換え処理
			// ・[myasp_tag_more_protection]があった場合のログインチェック＋画面書き換え処理
			// ・タグアーカイブで未ログイン時に本文が出力されないようにフィルタリング
			add_filter( 'the_content', [ $this, 'filter_the_content' ] );
		}
	}

	/**
	 * プラグインロード時に、固定ページ(ログイン、アクセス不可)を追加
	 * ・すでにある場合は作らない（初回プラグインロード時に自動で作成する）
	 */
	static function add_custom_page()
	{
		$page = get_page_by_path( 'myasp-login-page', OBJECT, 'page' );
		if ( empty( $page ) ) {
			$homeUrl = home_url();

			$post_content = 
				'<!-- wp:paragraph -->' . "\n" .
				'<p>登録するとお読みいただけます</p>' . "\n" .
				'<!-- /wp:paragraph -->' . "\n" .
				'' . "\n" .
				'<!-- wp:shortcode -->' . "\n" .
				'[myasp_register_form_url_list]' . "\n" .
				'<!-- /wp:shortcode -->' . "\n" .
				'' . "\n" .
				'<!-- wp:separator -->' . "\n" .
				'<hr class="wp-block-separator"/>' . "\n" .
				'<!-- /wp:separator -->' . "\n" .
				'' . "\n" .
				'<!-- wp:paragraph -->' . "\n" .
				'<p>登録済みの方：こちらからログイン</p>' . "\n" .
				'<!-- /wp:paragraph -->' . "\n" .
				'' . "\n" .
				'<!-- wp:shortcode -->' . "\n" .
				'[myasp_login_error]' . "\n" .
				'<!-- /wp:shortcode -->' . "\n" .
				'' . "\n" .
				'<!-- wp:html -->' . "\n" .
				'<form class="myasp_login_form" action="' . esc_url( $homeUrl  . '/myasp-login-page' ) . '" method="post">' . "\n" .
				'   [myasp_form_nonce action="' . self::CREDENTIAL_LOGIN_ACTION . '" name="' . self::CREDENTIAL_LOGIN_NAME . '"]' . "\n" .
				'	<div class="myasp-login-input">' . "\n" .
				'		<label for="mail">メールアドレス</label>' . "\n" .
				'		<input id="mail" class="email_trim" name="mail" size="20" type="text" placeholder="ご登録いただいたメールアドレス"><br>' . "\n" .
				'			<input name="user_name" type="hidden">' . "\n" .
				'	</div>' . "\n" .
				'	<div class="myasp-login-input">' . "\n" .
				'		<label for="password">パスワード</label>' . "\n" .
				'		<input id="password" class="string_trim" name="password" size="20" type="password" placeholder="パスワード">' . "\n" .
				'	</div>' . "\n" .
				'	<div class="myasp-login-submit">' . "\n" .
				'		<input class="myasp-login-button" type="submit" value="ログイン">' . "\n" .
				'	</div>' . "\n" .
				'</form>' . "\n" .
				'<!-- /wp:html -->' . "\n" .
				'<!-- wp:separator -->' . "\n" .
				'<hr class="wp-block-separator"/>' . "\n" .
				'<!-- /wp:separator -->' . "\n" .
				'<!-- wp:paragraph -->' . "\n" .
				'<p><b>＞</b> <a href="' . esc_url( $homeUrl . '/myasp-request-password-reminder-page') .'" target="_blank" rel="noopener noreferrer">パスワードを忘れた方</a></p>' . "\n" .
				'<!-- /wp:paragraph -->';

			// ブロックエディタ版ログイン画面ソース
			$my_post = [
				'post_title' => 'ログインページ',
				'post_name' => 'myasp-login-page',
				'post_content' => $post_content,
				'post_status' => 'publish',
				'post_author' => 1,
				'post_type' => 'page',
			];

			// 固定ページの追加
			wp_insert_post( $my_post );
		}

		$page = get_page_by_path( 'myasp-private-page', OBJECT, 'page' );
		if ( empty( $page ) ) {
			// 非公開ページ(フォームタグで登録フォームを表示)
			$post_content = 
				'<!-- wp:html -->' . "\n" .
				'<div class="myasp-login-wrap">' . "\n" .
				'	<h3>閲覧が制限されています</h3>' . "\n" .
				'</div>' . "\n" .
				'<!-- /wp:html -->'.
				'<!-- wp:shortcode -->' . "\n" .
				'[myasp_general_error]' . "\n" .
				'<!-- /wp:shortcode -->' . "\n";
			// 非公開ページ(フォームタグで登録フォームを表示)
			$my_post = [
				'post_title' => '閲覧不可ページ',
				'post_name' => 'myasp-private-page',
				'post_content' => $post_content,
				'post_status' => 'publish',
				'post_author' => 1,
				'post_type' => 'page',
			];

			// 固定ページの追加
			wp_insert_post( $my_post );
		}

		$page = get_page_by_path( 'myasp-change-password-page', OBJECT, 'page' );
		if ( empty( $page ) ) {
			$form = self::password_form_html( home_url( '/myasp-change-password-page' ), false );
			$allow_tags = [
				'form' => ['action' => [], 'method' => [], 'class' => []], 
				'div' => ['class' => []], 
				'label' => ['for' => []], 
				'input' => ['type' => [], 'name' => [], 'size' => [], 'class' => [], 'id' => [], 'value' => [], 'placeholder' => []]
			];
			$post_content = 
				'<!-- wp:html -->' . "\n" .
				'<div class="myasp-change-password-wrap">' . "\n" .
				'	' . wp_kses( $form, $allow_tags ) . "\n" .
				'</div>' . "\n" .
				'<!-- /wp:html -->';
			// パスワード変更ページ
			$my_post = [
				'post_title' => '会員ログイン_パスワード変更ページ',
				'post_name' => 'myasp-change-password-page',
				'post_content' => $post_content,
				'post_status' => 'publish',
				'post_author' => 1,
				'post_type' => 'page',
			];

			// 固定ページの追加
			wp_insert_post( $my_post );
		}

		$page = get_page_by_path( 'myasp-request-password-reminder-page', OBJECT, 'page' );
		if ( empty( $page ) ) {
			$post_content = 
				'<!-- wp:paragraph -->' . "\n" .
				'<p>パスワードの再設定を行います。<br>' . "\n" .
				'以下のメールアドレス入力欄に、契約時に入力したメールアドレスをご入力の上、【送信】をクリックしてください。<br>' . "\n" .
				'メールアドレスの確認後、そのメールアドレス宛に、パスワード再設定用メールが自動送信されます。</p>' . "\n" .
				'[myasp_password_error]' . "\n" .
				'<!-- /wp:paragraph -->' . "\n" .
				'<!-- wp:html -->' . "\n" .
				'<form class="myasp-password-reminder-form" action="' . esc_url( $homeUrl . '/myasp-request-password-reminder-page' ) . '" method="post">' . "\n" .
				'	[myasp_form_nonce action="' . self::CREDENTIAL_RESET_ACTION . '" name="' . self::CREDENTIAL_RESET_NAME . '"]' . "\n" .
				'	<div class="myasp-password-reminder-input">' . "\n" .
				'		<label for="mail">メールアドレス</label>' . "\n" .
				'		<input id="mail1" class="email_trim" name="mail1" size="20" type="text" placeholder="ご登録いただいたメールアドレス">' . "\n" .
				'	</div>' . "\n" .
				'	<div class="myasp-password-reminder-input">' . "\n" .
				'		<label for="mail">メールアドレス（確認用）</label>' . "\n" .
				'		<input id="mail2" class="email_trim" name="mail2" size="20" type="text" placeholder="確認のため再度入力">' . "\n" .
				'	</div>' . "\n" .
				'	<div class="myasp-password-reminder-submit">' . "\n" .
				'		<input class="myasp-password-reminder-button" type="submit" value="送信">' . "\n" .
				'	</div>' . "\n" .
				'</form>' . "\n" .
				'<!-- /wp:html -->';

			// パスワードの再設定ページ
			$my_post = [
				'post_title' => '会員ログイン_パスワードの再設定(メールアドレス入力)',
				'post_name' => 'myasp-request-password-reminder-page',
				'post_content' => $post_content,
				'post_status' => 'publish',
				'post_author' => 1,
				'post_type' => 'page',
			];

			// 固定ページの追加
			wp_insert_post( $my_post );
		}

		$page = get_page_by_path( 'myasp-sent-password-reminder-page', OBJECT, 'page' );
		if ( empty( $page ) ) {
			$post_content = 
				'<!-- wp:paragraph -->' . "\n" .
				'<p><b>ご登録されているメールアドレスにパスワード再設定用のメールを配信しました。</b></p>' . "\n" .
				'<!-- /wp:paragraph -->';

			// パスワードの再設定ページ
			$my_post = [
				'post_title' => '会員ログイン_パスワード再設定メール送信',
				'post_name' => 'myasp-sent-password-reminder-page',
				'post_content' => $post_content,
				'post_status' => 'publish',
				'post_author' => 1,
				'post_type' => 'page',
			];

			// 固定ページの追加
			wp_insert_post( $my_post );
		}

		$page = get_page_by_path( 'myasp-password-reminder-page', OBJECT, 'page' );
		if ( empty( $page ) ) {
			$homeUrl = home_url();
			$post_content = 
				'<!-- wp:paragraph -->' . "\n" .
				'<p>新しいパスワードを設定してください。</p>' . "\n" .
				'[myasp_password_error]' . "\n" .
				'<!-- /wp:paragraph -->' . "\n" .
				'' . "\n" .
				'<!-- wp:html -->' . "\n" .
				'<form class="myasp-password-reminder-form" action="' . esc_url( $homeUrl ) . '/myasp-password-reminder-page/?reminder=[myasp_reminder]" method="post">' . "\n" .
				'	[myasp_form_nonce action="' . self::CREDENTIAL_PASSWORD_ACTION . '" name="' . self::CREDENTIAL_PASSWORD_NAME . '"]' . "\n" .
				'	<div class="myasp-password-input">' . "\n" .
				'		<label for="new_password1">新しいパスワード</label>' . "\n" .
				'		<input type="password" name="new_password1" size="20" class="string_trim" placeholder="パスワード" id="new_password1">' . "\n" .
				'	</div>' . "\n" .
				'	<div class="myasp-password-input">' . "\n" .
				'		<label for="new_password2">新しいパスワード（確認用）</label>' . "\n" .
				'		<input type="password" name="new_password2" size="20" class="string_trim" placeholder="確認のため再度入力" id="new_password2">' . "\n" .
				'	</div>' . "\n" .
				'	<div class="myasp-password-submit">' . "\n" .
				'		<input class="myasp-password-button" type="submit" value="パスワード再設定">' . "\n" .
				'	</div>' . "\n" .
				'</form>' . "\n" .
				'<!-- /wp:html -->';
			// パスワードの再設定ページ
			$my_post = [
				'post_title' => '会員ログイン_パスワード再設定',
				'post_name' => 'myasp-password-reminder-page',
				'post_content' => $post_content,
				'post_status' => 'publish',
				'post_author' => 1,
				'post_type' => 'page',
			];

			// 固定ページの追加
			wp_insert_post( $my_post );
		}

		$page = get_page_by_path( 'myasp-password-reminder-complete-page', OBJECT, 'page' );
		if ( empty( $page ) ) {
			$post_content = 
				'<!-- wp:paragraph -->' . "\n" .
				'<p><b>パスワードの変更が完了しました。</b></p>' . "\n" .
				'<!-- /wp:paragraph -->';

			// パスワード変更完了
			$my_post = [
				'post_title' => '会員ログイン_パスワード変更完了',
				'post_name' => 'myasp-password-reminder-complete-page',
				'post_content' => $post_content,
				'post_status' => 'publish',
				'post_author' => 1,
				'post_type' => 'page',
			];

			// 固定ページの追加
			wp_insert_post( $my_post );
		}

	}

	/**
	 * 固定ページ(ログイン、アクセス不可)を削除
	 * ⇒ 「リセット」ボタンを押したタイミングで消して、標準の固定ページに戻す
	 */
	function remove_custom_page()
	{
		// 固定ページの削除
		$page = get_page_by_path( 'myasp-login-page', OBJECT, 'page' );
		if ( ! empty( $page ) ) {
			wp_delete_post( $page->ID, true );
		}

		$page = get_page_by_path( 'myasp-private-page', OBJECT, 'page' );
		if ( ! empty( $page ) ) {
			wp_delete_post( $page->ID, true );
		}

		$page = get_page_by_path( 'myasp-change-password-page', OBJECT, 'page' );
		if ( ! empty( $page ) ) {
			wp_delete_post( $page->ID, true );
		}

		$page = get_page_by_path( 'myasp-request-password-reminder-page', OBJECT, 'page' );
		if ( ! empty( $page ) ) {
			wp_delete_post( $page->ID, true );
		}

		$page = get_page_by_path( 'myasp-sent-password-reminder-page', OBJECT, 'page' );
		if ( ! empty( $page ) ) {
			wp_delete_post( $page->ID, true );
		}

		$page = get_page_by_path( 'myasp-password-reminder-page', OBJECT, 'page' );
		if ( ! empty( $page ) ) {
			wp_delete_post( $page->ID, true );
		}

		$page = get_page_by_path( 'myasp-password-reminder-complete-page', OBJECT, 'page' );
		if ( ! empty( $page ) ) {
			wp_delete_post( $page->ID, true );
		}
	}

	/**
	 * MyASP API経由で(有効な)全シナリオの一覧を取得する
	 * ・WordPressアクセス時、毎回MyASPからデータを取得している
	 *	 ⇒ 処理が遅くなるのでキャッシュした方がよい（が、今はしていない）
	 */
	function myasp_get_items()
	{
		$seller_id = get_option( self::PLUGIN_DB_PREFIX . 'common_seller_id' );
		$domain = get_option( self::PLUGIN_DB_PREFIX . 'common_domain' );

		if ( empty( $domain ) || empty( $domain ) ) {
			return [];
		}

		$url = "https://{$domain}/wordpressApi/wp_item_list/{$seller_id}";

		// MyASPユーザーとしてログイン済の場合、購読済みシナリオの取得も同時に行う
		if ( ! empty( $_SESSION['myasp_login_data'] ) ) {
			$user_id = sanitize_text_field( $_SESSION['myasp_login_data']['User']['id'] );
			$url .= "/{$user_id}";
		}

		$response = json_decode( $this->remote_request( $url ), true );
		return $response;
	}

	/**
	 * MyASPから取得したシナリオ一覧をセットする
	 * @return bool 
	 */
	function set_item_list()
	{
		$items = $this->myasp_get_items();
		if ( ! empty( $items ) ) {
			$this->group_items = $items['group_list'];
			$this->items = $items['item_list'];
			$this->last_status = $items['status'];
			if ( ! empty( $items['message'] ) ) {
				$this->last_message = $items['message'];
			} else {
				$this->last_message = '';
			}

			if ( ! empty( $items['items'] ) ) {
				// ログイン済ユーザの場合、一緒に登録済みシナリオを取得する
				$this->registered_items = $items['items'];
			}
			return true;
		}
		return false;
	}

	/**
	 * MyASPページ用アクセスコントローラー
	 * ・ログイン状態、権限チェック(リダイレクト)を行う
	 */
	function myasp_access_controller()
	{
		global $wp_query;

		// $is_page = is_page();	 // 固定ページ
		// $is_single = is_single(); // 投稿ページ
		// $is_home = is_home();	 // トップページ(ホームページ)
		// $is_tag = is_tag();		 // タグアーカイブ
		// $title = get_the_title(); // タイトル

		$url = sanitize_url( $_SERVER['REQUEST_URI'] );
		$id = get_the_id(); // Pageのid

		// MyASPサーバから、シナリオ一覧を取得する(毎回だと時間かかるけどとりあえず)
		if ( ! $this->set_item_list() ) {
			// MyASPと接続できないのでなにもしない
			return;
		}

		// ショートコードの生成(ログイン、登録フォーム)
		$return_url = sanitize_url( get_query_var( 'redirect_to', '' ) );
		$rid = get_query_var( 'rid', '' );
		$rid = is_numeric( $rid ) ? $rid : '';
		$this->add_MyASP_short_code( $rid, $return_url );

		// MyASP用特殊ページの処理を行う
		// ログイン、ログアウト、登録フォーム表示後のリダイレクトページ
		if ( $this->process_MyASP_special_page( $url ) ) {
			return;
		}

		// ログイン済みチェック
		$is_logged_in = ! empty( $_SESSION['myasp_login_data'] );

		// 自動ログイン処理
		$token = get_query_var( 'token' );
		if ( ! empty( $token ) && $this->validate_token( $token ) ) {
			// URLにtokenが付与されている場合は自動ログイン
			$is_logged_in = $this->autoLogin( $token );
		}

		// メッセージを表示する(別画面から遷移する際、クエリストリングで引き渡す)
		$message = get_query_var( 'message', '' );
		if ( ! empty( $message ) ) {
			$this->add_show_message( $message );
		}

		// MyASP連携済みページかチェック
		if ( ! $this->is_MyASP_control_page( $url, $id ) ) {
			// MyASPと関係ないページであれば処理しない
			return;
		}

		// リダイレクトに必要なパラメーター
		$param = $this->redirect_url( $url, $id );

		if ( $is_logged_in ) {
			// 購読済みシナリオの更新
			$_SESSION['myasp_login_data']['registered_items'] = $this->registered_items;

			// ログイン済みであれば、MyASP連携ページに対し、表示する権限を持つか？をチェックする
			$tags = get_the_tags();
			$permission = $this->has_permission( $tags );
			if ( $permission === self::ALLOWED_PAGE ) {
				// 権限があればそのまま表示する
				return;
			} elseif ( $permission === self::NOT_ALLOWED_PAGE ) {
				// 権限がないので「会員登録ページ(ログイン済み)」へリダイレクト
				wp_redirect( home_url( '/myasp-login-page' ) . $param, 301 );
				exit( 0 );
			} elseif ( $permission === self::PRIVATE_PAGE ) {
				// 非公開に設定されている場合は「ページが閲覧できません」へリダイレクト
				wp_redirect( home_url( '/myasp-private-page' ), 301 );
				exit( 0 );
			}
			return;
		} else {
			// 未ログイン＋ログインページ以外のアクセスは、ログインページへリダイレクト
			$myasp_login_url = $this->get_tag_setting( 'myasp_login_url' );
			if ( rtrim( $myasp_login_url ) !== '' ) {
				// ユーザー指定ログインページ
				if ( strpos( $myasp_login_url, 'http' ) === false ) {
					// httpが含まれない場合、自ドメインの指定ページへ遷移する(スラッグのみ指定もOKとする)
					$server_name = sanitize_text_field( $_SERVER['SERVER_NAME'] );
					$myasp_login_url =  sanitize_url( "http://{$server_name}/{$myasp_login_url}" );
				}
				wp_redirect( $myasp_login_url . $param, 301 );
				exit( 0 );
			} else {
				// 標準のログインページ
				wp_redirect( home_url( 'myasp-login-page' . $param ), 301 );
				exit( 0 );
			}
		}
	}

	/**
	 * リダイレクト先のパラメーターを作成する
	 * ・WordPressのインストールパス(サイトURL)も付ける（複数WordPress対応)
	 */
	function redirect_url( $request_url, $rid = '' )
	{
		$server = sanitize_text_field( $_SERVER['SERVER_NAME'] );
		$http = empty( $_SERVER['HTTPS'] ) ? 'http' : 'https';
		$parsed = parse_url( home_url() );
		$param_array = [
			'redirect_to' => "{$http}://{$server}{$request_url}",
			'path' => $parsed['path'] ?? '',
		];
		if ( ! empty( $rid ) ) {
			$param_array['rid'] = $rid;
		}

		return '?' . http_build_query( $param_array );
	}

	/**
	 * タグの設定を取得する
	 */
	function get_tag_setting( $key )
	{
		$tags = get_the_tags();
		if ( ! empty( $tags ) ) {
			foreach ( $tags as $tag ) {
				$term_meta = get_option( $tag->taxonomy . '_' . $tag->term_id );
				if ( ! empty( $term_meta[ $key ] ) ) {
					return $term_meta[ $key ];
				}
			}
		}
		return '';
	}

	/**
	 * 自動ログイン処理 (サンクスメール内のURLをクリックした場合など)
	 * ・tokenが付与されていた場合、自動ログインを行う
	 * ・tokenの仕様は会員ページと同じ
	 */
	function autoLogin( $token )
	{
		// tokenをチェックしてログイン(token = ProceedID＋UserID)
		// トークンIDから、ProceedIDとUserIDを抜き出します。
		$token_id = str_split( $token, 8 );
		$token_proceed_id = $token_id[0]; // 左半分がProceedID
		$token_user_id = $token_id[1]; // 右半分がUserID

		// 自動ログイン（該当ユーザのProceedか？プロシードが有効か？をチェック後、購入シナリオの一覧を返してもらう）
		$response = $this->myasp_auto_login( $token_proceed_id, $token_user_id );

		// ログイン後、指定ページに移動（指定がなければトップページ）
		if ( $response['status'] === 'OK' ) {
			// ログイン成功
			$login_data = $this->sanitize_login_data( $response );
			$_SESSION['myasp_login_data'] = $login_data;
			$this->registered_items = $login_data['registered_items']; // 最適化の都合上
			return true;
		} else if ( $response['status'] === 'error' ) {
			// エラーの場合は制限ページへ
			wp_redirect( home_url( '/myasp-private-page' ), 301 );
			exit( 0 );
		} else {
			// NGの場合は無視(ログイン画面へ転送される)
			return false;
		}
	}

	/**
	 * APIから取得したログイン情報のサニタイズを行う
	 */
	function sanitize_login_data( $response ) {
		$user = [];
		foreach( array_keys( $response['User'] ) as $user_key )  {
			$user[$user_key] = sanitize_text_field( $response['User'][ $user_key ] );
		}

		$items = [];
		foreach( $response['items'] as $item ) {
			$items[] = [
				'id' => sanitize_text_field( $item['id'] ),
				'item_name' => sanitize_text_field( $item['item_name'] ),
			];
		}

		$login_data = [
			'mail' => sanitize_email( $response['mail'] ),
			'user_name' => sanitize_text_field( $response['user_name'] ),
			'User' => $user,
			'registered_items' => $items,
		];
		return $login_data;
	}

	/**
	 * MyASP API 自動ログイン処理呼び出し
	 */
	function myasp_auto_login( $proceed_id, $user_id )
	{
		$seller_id = get_option( self::PLUGIN_DB_PREFIX . 'common_seller_id' );
		$domain = get_option( self::PLUGIN_DB_PREFIX . 'common_domain' );

		// MyASPサーバにログイン確認を行う
		$param = [];
		$param['seller_id'] = $seller_id;
		$param['proceed_id'] = $proceed_id;
		$param['user_id'] = $user_id;

		$url = "https://{$domain}/wordpressApi/wp_auto_login";
		$response = $this->remote_request( $url, 'POST', $param );
		$response = json_decode( $response, true );

		return $response;
	}

	/**
	 * 登録フォームからのリダイレクト受け入れ処理
	 * ・元ページを表示する
	 */
	function redirect_from_MyASP_form()
	{
		// 先頭の「/myasp-redirect-page」を削除。残りの部分をくっつけてリダイレクトする
		$redirect_to = preg_replace( '/.*\/myasp-redirect-page/s', '', sanitize_url( $_SERVER['REQUEST_URI'] ) );
		$http = empty( $_SERVER['HTTPS'] ) ? 'http' : 'https';
		wp_redirect( "{$http}://{$_SERVER["HTTP_HOST"]}{$redirect_to}" );
	}

	/**
	 * ログインページ処理
	 */
	function processLogin()
	{
		if ( sanitize_text_field( $_SERVER['REQUEST_METHOD'] ) === "POST" ) {
			// ログインページのpost
			// MyASPサーバにログイン確認を行う
			$response = $this->login_MyASP();

			unset( $_SESSION['myasp_login_data'] ); // ログイン情報クリア
			if ( $response['status'] === 'OK' ) {
				// セッションに値をセットしてトップページを表示する
				$login_data = $this->sanitize_login_data( $response );
				$_SESSION['myasp_login_data'] = $login_data;

				// ログインが成功したら、元ページへ戻る
				$return_url = sanitize_url( get_query_var( 'redirect_to', '' ) );
				if ( empty( $return_url ) ) {
					wp_redirect( home_url() );
				} else {
					wp_redirect( $return_url );
				}
			} else if ($response['status'] === 'NG') {
				$error_message = '<p class="myasp-login-error">メールアドレスもしくはパスワードが間違っています</p>';
				// エラーメッセージ表示用ショートコードを生成
				remove_shortcode( 'myasp_login_error' );
				add_shortcode( 'myasp_login_error', function () use ( $error_message ) {
					return $error_message;
				} );

				$return_url = sanitize_url( get_query_var( 'redirect_to', '' ) );
				if ( ! empty( $return_url ) ) {
					// ログインできない場合、元のページへ戻す
					//  ⇒ 未ログインのためログインページが戻る(カスタムログインページが指定されている場合、その画面が表示される)
					//  ⇒ 画面遷移するため、エラーメッセージをtransientで引き渡す(5秒で削除、別ユーザーと混同しないようにsession_idをキーに含める)
					set_transient( self::LOGIN_ERROR_TRANSIENT_KEY . substr( session_id(), 0, 20 ), $error_message, 5 );
					wp_redirect( $return_url );
				}
			} else {
				// エラー
				remove_shortcode( 'myasp_login_error' );
				add_shortcode( 'myasp_login_error', function () use( $response ) {
					return '<p class="myasp-login-error">' . esc_html( $response['message'] ) . '</p>';
				} );
			}
		} else {
			// ログインフォーム表示
			// ログイン済でも、必要なシナリオ未購入のため再度表示される場合がある
			// そのための注意メッセージを表示する
			$is_logged_in = ! empty( $_SESSION['myasp_login_data'] );
			if ( $is_logged_in ) {
				// メッセージを表示
				add_shortcode( 'myasp_login_error', function () {
					return '<p class="myasp-login-error">閲覧するには以下から「お申し込み／購入手続き」をお願いします</p>';
				} );
			}
		}
	}

	/**
	 * パスワード変更処理
	 */
	function processChangePassword()
	{
		if ( ! empty( $_POST ) ) {
			// nonceで設定したcredentialのチェック
			if ( ! isset( $_POST[ self::CREDENTIAL_PASSWORD_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::CREDENTIAL_PASSWORD_NAME ] ) ), self::CREDENTIAL_PASSWORD_ACTION ) ) {
				add_shortcode( 'myasp_password_error', function () {
					return "<p class=\"myasp-password-error\">パスワードが正しくありません。</p>";
				} );
			}

			// パスワードチェック
			// MyASPサーバでパスワードチェックを行う
			$response = $this->myasp_change_password( $_POST );

			if ( $response['status'] === 'OK' ) {
				// セッションにセットされたパスワードを入れ替え
				$login_data = $_SESSION['myasp_login_data'];
				$login_data['User']['password'] = sanitize_text_field( $response['password'] );
				$_SESSION['myasp_login_data'] = $login_data;

				wp_redirect( home_url( '/myasp-password-reminder-complete-page' ), 301 );
			} else {
				// エラーメッセージを表示
				add_shortcode( 'myasp_password_error', function () use ( $response ) {
					$message = esc_html( $response['message'] );
					return "<p class=\"myasp-password-error\">{$message}</p>";
				} );
				return;
			}
		}
	}

	function add_show_message( $message )
	{
		add_filter( 'wp_footer', function() use( $message ) {
			?>
			<script>
				setTimeout( () => {
					alert( '<?= base64_decode( $message ); ?>' );
				}, 10 ); 
			</script> 
			<?php
		} );
	}

	/**
	 * パスワードリマインダー（メール送信）
	 * ・MyASPのAPIを呼び出してメールを送信
	 */
	function sendPasswordReminderMail()
	{
		if ( ! empty( $_POST ) ) {
			// nonceで設定したcredentialのチェック
			if ( ! isset( $_POST[ self::CREDENTIAL_RESET_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::CREDENTIAL_RESET_NAME ] ) ), self::CREDENTIAL_RESET_ACTION ) ) {
				add_shortcode( 'myasp_password_error', function () {
					return "<p class=\"myasp-password-error\">入力が正しくありません。</p>";
				} );
			}

			// MyASPサーバーからメールを送信
			$response = $this->myasp_request_reminder( $_POST );
			if ( $response['status'] === 'OK' ) {
				// ご登録されているメールアドレスにパスワード再設定用のメールを配信しました
				// ページへ遷移する
				wp_redirect( home_url( '/myasp-sent-password-reminder-page' ), 301 );
			} else {
				// エラーメッセージを表示
				add_shortcode( 'myasp_password_error', function () use ( $response ) {
					$message = esc_html( $response['message'] );
					return "<p class=\"myasp-password-error\">{$message}</p>";
				} );
			}
		}
	}

	/**
	 * パスワードリマインダー（パスワード変更）
	 * ・MyASPのAPIを呼び出して、パスワードを変更
	 */
	function processPasswordReminder()
	{
		// reminder_idをフォームに埋め込む
		add_shortcode( 'myasp_reminder', function () {
			return get_query_var( 'reminder' );
		} );

		if ( ! empty( $_POST ) ) {
			// nonceで設定したcredentialのチェック
			if ( ! isset( $_POST[ self::CREDENTIAL_PASSWORD_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::CREDENTIAL_PASSWORD_NAME ] ) ), self::CREDENTIAL_PASSWORD_ACTION ) ) {
				add_shortcode( 'myasp_password_error', function () {
					return "<p class=\"myasp-password-error\">パスワードが正しくありません。</p>";
				} );
			}

			// MyASPサーバーからメールを送信
			$response = $this->myasp_reminder_reset_password( $_POST );
			if ( $response['status'] === 'OK' ) {
				// ご登録されているメールアドレスにパスワード再設定用のメールを配信しました
				// ページへ遷移する
				wp_redirect( home_url( '/myasp-password-reminder-complete-page' ), 301 );
			} else {
				// エラーメッセージを表示
				add_shortcode( 'myasp_password_error', function () use ( $response ) {
					$message = esc_html( $response['message'] );
					return "<p class=\"myasp-password-error\">{$message}</p>";
				} );
			}
		}
	}

	/**
	 * MyASP用特殊ページの処理を行う
	 * ・ログアウト
	 * ・登録フォーム表示後のリダイレクト
	 * ・ログイン
	 * ・アクセス不可
	 */
	function process_MyASP_special_page( $url )
	{
		$is_logout = strpos( $url, '/myasp-logout-page' ) !== false; // ログアウトページ
		if ( $is_logout ) {
			unset( $_SESSION['myasp_login_data'] );
			// セッションを破棄してトップページへ戻る
			if ( ! empty( get_query_var( 'redirect_to', '' ) ) ) {
				$return_url = sanitize_url( urldecode( get_query_var( 'redirect_to', '' ) ) );
				wp_redirect( $return_url, 301 );
			} else {
				wp_redirect( home_url(), 301 );
			}

			exit( 0 );
			return true;
		}

		$is_redirect_page = strpos( $url, '/myasp-redirect-page' ) !== false; // 登録フォーム表示後のリダイレクト
		if ( $is_redirect_page ) {
			$token = get_query_var( 'token' );
			if ( ! empty( $token ) && $this->validate_token( $token ) ) {
				// URLにtokenが付与されている場合は自動ログイン
				$this->autoLogin( $token );
			}
			// MyASP登録フォームから戻ってきた場合、元ページを表示する(リダイレクト)
			$this->redirect_from_MyASP_form();
			return true;
		}

		$is_login_page = strpos( $url, '/myasp-login-page' ) !== false; // ログインページ
		if ( $is_login_page ) {
			$this->processLogin();
			return true;
		}

		// パスワード変更ページ
		$is_logged_in = ! empty( $_SESSION['myasp_login_data'] );
		$is_change_password_page = strpos( $url, '/myasp-change-password-page' ) !== false;
		if ( $is_change_password_page && $is_logged_in ) {
			$this->processChangePassword();
			return true;
		}

		// パスワード再設定(メールアドレス入力)
		$is_request_password_reminder_page = strpos( $url, '/myasp-request-password-reminder-page' ) !== false;
		if ( $is_request_password_reminder_page ) {
			$this->sendPasswordReminderMail();
			return true;
		}

		// パスワード再設定
		$is_password_reminder_page = strpos( $url, '/myasp-password-reminder-page' ) !== false; 
		if ( $is_password_reminder_page ) {
			$this->processPasswordReminder();
			return true;
		}

		return false;
	}

	/**
	 * カスタム投稿タイプ判断 
	 */
	function is_custom_post_type()
	{
		$post_type = get_post_type();
		return is_single() && $post_type !== 'post' && $post_type !== 'attachment';
	}

	/**
	 * MyASP連携ページであればtrueを返す
	 * ・Wordpress側で設定した情報を元に返す
	 */
	function is_MyASP_control_page( $url, $id )
	{
		// $is_login = strpos( $url, '/myasp-login-page' ) !== false; // ログインページ
		// $is_redirect = strpos( $url, '/myasp-redirect-page' ) !== false;	 // フォーム登録後のページ
		// $is_forbidden = strpos( $url, '/myasp-forbidden-page' ) !== false; // アクセス不可
		$is_page = is_page();	  // 固定ページ
		$is_single = is_single(); // 投稿ページ

		if ( is_admin() && is_user_logged_in() ) {
			// 管理サイトのアクセスは無視
			// MyASPと関係ないページであれば処理しない
			return false;
		}

		if ( is_home() || $url === '/favicon.ico' ) {
			// トップページなので誰でも表示可能
			return false;
		}

		// 固定ページ、投稿ページ以外のページは管理対象外
		if ( ! $is_page && ! $is_single ) {
			return false;
		}

		if ( ! self::ENABLE_CUSTOM_POST_TYPE ) {
			if ( $this->is_custom_post_type() ) {
				// カスタム投稿タイプは対象外
				return false;
			}
		}

		// パスワード変更ページ
		if ( strpos( $url, '/myasp-change-password-page' ) !== false ) {
			return true;
		}

		// 紐づいている「タグ」が「MyASP管理タグ」であるか判断する
		$tags = get_the_tags();
		$is_MyASP_managed = $this->is_MyASP_managed( $tags );
		return $is_MyASP_managed;
	}

	/**
	 * 紐づいている「タグ」が「MyASP管理タグ」であるか判断する
	 */
	function is_MyASP_managed( $tags )
	{
		if ( ! empty( $tags ) ) {
			foreach ( $tags as $tag ) {
				$term_meta = get_option( $tag->taxonomy . '_' . $tag->term_id );
				if ( $term_meta && $term_meta['myasp_managed_post'] === 'checked' ) {
					// 連携ページ
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * ログイン画面用ショートコードを追加する
	 * ・ログインフォーム:[myasp_login_form]
	 * ・エラーメッセージ:[myasp_login_error]
	 */
	function add_login_shortCode()
	{
		// エラーメッセージ表示用ショートコード（エラー時にメッセージを差し替える)
		add_shortcode( 'myasp_login_error', function () {
			return '';
		} );

		// ログインフォーム
		add_shortcode( 'myasp_login_form', function ( $atts ) {
			if ( empty( $_SESSION['myasp_login_data'] ) ) {
				$redirect = '';
				if ( ! empty( $atts['redirect_to'] ) ) {
					$redirect = "?redirect_to=" . urlencode( sanitize_url( $atts['redirect_to'] ) );
				}

				$homeUrl = home_url();
				$form = 
					'<p>[myasp_login_error]</p>' . "\n" .
					'<form action="' . esc_url( $homeUrl . '/myasp-login-page' . $redirect ) . '" method="post" class="myasp_login_form">' . "\n" .
					'	' . wp_nonce_field( self::CREDENTIAL_LOGIN_ACTION, self::CREDENTIAL_LOGIN_NAME ) . "\n" .
					'	<div class="myasp-login-input">' . "\n" .
					'		<label for="mail">メールアドレス</label>' . "\n" .
					'		<input name="mail" size="20" class="email_trim" placeholder="ご登録いただいたメールアドレス" type="text" id="mail">' . "\n" .
					'		<input type="hidden" name="user_name" />' . "\n" .
					'	</div>' . "\n" .
					'	<div class="myasp-login-input">' . "\n" .
					'		<label for="password">パスワード</label>' . "\n" .
					'		<input name="password" size="20" class="string_trim" placeholder="パスワード" type="password" id="password">' . "\n" .
					'	</div>' . "\n" .
					'	<div class="myasp-login-submit">' . "\n" .
					'		<input class="myasp-login-button" type="submit" value="ログイン">' . "\n" .
					'	</div>' . "\n" .
					'</form>';

				return do_shortcode( shortcode_unautop( $form ) );
			} else {
				return '';
			}
		} );

		// ログインページの場合、エラーメッセージがあれば表示する(ログインで失敗して同一画面に戻る場合)
		$is_login_page = strpos( sanitize_url( $_SERVER['REQUEST_URI'] ), '/myasp-login-page' ) !== false;
		if ( $is_login_page ) {
			// transientにメッセージが保存されている場合は、表示を行う(ログインエラー文字)
			$transient_key = self::LOGIN_ERROR_TRANSIENT_KEY . substr( session_id(), 0, 20 );
			$login_error_message = get_transient( $transient_key );
			if ( ! empty( $login_error_message ) ) {
				delete_transient( $transient_key ); // 念のため削除(5秒で削除する設定)
				add_shortcode( 'myasp_login_error', function () use ( $login_error_message ) {
					return $login_error_message;
				} );
			}
		}
	}

	/**
	 * 各種ショートコードの追加
	 */
	function add_MyASP_short_code( $id, $return_url )
	{
		// $api_token = get_option( self::PLUGIN_DB_PREFIX . 'common_api_token' );
		$domain = get_option( self::PLUGIN_DB_PREFIX . 'common_domain' );

		// 配列で指定した内容で、記事情報を取得
		// 記事のスラグと、$return_urlが不一致の場合、パラメーターを変更されているのでトップページへリダイレクト(別ページの権限で開くことができないようにする)
		if ( false ) {
			wp_redirect( home_url() );
		}

		// 許可されたシナリオ名一覧（＋登録フォームのURL）
		$allowed_items = [];
		$tags = get_the_tags( $id );
		if ( ! empty( $tags ) ) {
			foreach ( $tags as $tag ) {
				$term_meta = get_option( $tag->taxonomy . '_' . $tag->term_id );
				if ( ! empty( $term_meta ) ) {
					foreach ( $term_meta['myasp_allowed_item'] as $item ) {
						if ( isset( $term_meta['myasp_auto_redirect'] ) ) {
							$allowed_items[ $item ] = $term_meta['myasp_auto_redirect'];
						}
					}
				}
			}
		}

		$param = [];
		if ( ! empty( $_SESSION['myasp_login_data'] ) ) {
			$param['mail'] = sanitize_email( $_SESSION['myasp_login_data']['mail'] );
		}

		// 登録フォームへのリンクのリスト
		$form_list = $this->form_link_list( $allowed_items, $param );

		// MyASPから登録フォームのURLを取得して表示する
		add_shortcode( 'myasp_register_form_url_list', function () use ( $form_list ) {
			return wp_kses_post( $form_list );
		} );

		add_shortcode( 'myasp_password_error', function () {
			return '&nbsp;';
		} );

		// ログアウト用リンク
		add_shortcode( 'myasp_logout_url', function ( $atts ) {
			// 未ログインの場合、ログアウト用リンクは表示しない
			if ( empty( $_SESSION['myasp_login_data'] ) ) {
				return '&nbsp;';
			} else {
				$redirect = '';
				if ( ! empty( $atts['redirect_to'] ) ) {
					$redirect = "?redirect_to=" . urlencode( sanitize_url( $atts['redirect_to'] ) );
				}

				return '<a href="' . esc_url( home_url( 'myasp-logout-page' ) . $redirect ) . '">ログアウト</a>';
			}
		} );

		// 氏名
		add_shortcode( 'myasp_name', function () {
			if ( empty( $_SESSION['myasp_login_data'] ) ) {
				return '&nbsp;';
			} else {
				$user = $_SESSION['myasp_login_data']['User'];
				return esc_html( $user['name1'] . $user['name2'] );
			}
		} );

		// 姓
		add_shortcode( 'myasp_name1', function () {
			if ( empty( $_SESSION['myasp_login_data'] ) ) {
				return '&nbsp;';
			} else {
				$user = $_SESSION['myasp_login_data']['User'];
				return esc_html( $user['name1'] );
			}
		} );

		// 名
		add_shortcode( 'myasp_name2', function () {
			if ( empty( $_SESSION['myasp_login_data'] ) ) {
				return '&nbsp;';
			} else {
				$user = $_SESSION['myasp_login_data' ]['User'];
				return esc_html( $user['name2'] );
			}
		} );

		// メールアドレス
		add_shortcode( 'myasp_mail', function () {
			if ( empty( $_SESSION['myasp_login_data'] ) ) {
				return '&nbsp;';
			} else {
				return sanitize_email( $_SESSION['myasp_login_data']['mail'] );
			}
		} );

		// 登録フォームのURLを返す
		add_shortcode( 'myasp_register_form_url', function ( $atts ) use ( $domain, $return_url ) {
			if ( empty( $atts['item'] ) || empty( $this->items[ $atts['item'] ] ) ) {
				return '&nbsp;';
			}

			$item_id = $atts['item'];
			if ( ! $this->validate_item_id( $item_id ) ) {
				return '&nbsp;';
			}

			$mail = '';
			if ( ! empty( $_SESSION['myasp_login_data'] ) ) {
				$mail = '&mail=' . urlencode( sanitize_email( $_SESSION['myasp_login_data']['mail'] ) );
			}

			return esc_url( 'https://' . $domain . '/p/r/' . $item_id . '?redirect_to=' . urlencode( $return_url ) . $mail );
		} );

		// 登録フォームの<a>タグを返す
		add_shortcode( 'myasp_register_form_link', function ( $atts ) use ( $domain ) {
			if ( empty( $atts['item'] ) || empty( $this->items[ $atts['item'] ] ) ) {
				return '&nbsp;';
			}

			$item_id = $atts['item'];

			$params = [];
			if ( ! empty( $atts['redirect_to'] ) ) {
				// 戻り先を指定した場合
				$params['redirect_to'] = $atts['redirect_to'];
			} else {
				// 未指定の場合は現在のページに戻る
				$url = sanitize_url( $_SERVER["HTTP_HOST"] . $_SERVER['REQUEST_URI'] );
				$params['redirect_to'] = $url;
			}

			if ( ! empty( $_SESSION['myasp_login_data'] ) ) {
				$params['mail'] = sanitize_email( $_SESSION['myasp_login_data']['mail'] );
			}

			// WordPressサイトURLを渡す
			$parsed = parse_url( home_url() );
			$params['path'] = $parsed['path'] ?? '';

			$query_string = http_build_query( $params );

			$url = 'https://' . $domain . '/p/r/' . $item_id . '?' . $query_string;
			$text = $this->items[ $item_id ]['item_name_for_user'];

			return '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>' . "\n";
		} );

		// フォームタグ(js)を埋め込むためのタグ
		add_shortcode( 'myasp_register_form_tag', function ( $atts ) use ( $domain ) {
			// ショートコードのパラメータ
			if ( empty( $atts['item'] ) || empty( $this->items[ $atts['item'] ] ) ) {
				return '&nbsp;';
			}
			$item_id = $atts['item'];

			// フォームタグ埋め込み用ショートコード
			$url = "https://{$domain}/wordpressApi/wp_get_regist_formtag/{$item_id}";
			$response = json_decode( $this->remote_request( $url ), true );
			return $response['form_tag'];
		} );

		$this->add_login_shortCode();

		// ログイン済み会員には表示される領域(要閉じタグ)
		add_shortcode( 'myasp_ismember', function ( $atts, $content = null, $tag = '' ) {
			if ( ! empty( $_SESSION['myasp_login_data'] ) ) {
				$content = do_shortcode( shortcode_unautop( $content ) );
				return $content;
			}

			return '';
		} );

		// 未ログイン、または、シナリオ未購入の人には表示される領域(要閉じタグ)
		add_shortcode( 'myasp_nonmember', function ( $atts, $content = null, $tag = '' ) {
			if ( empty( $_SESSION['myasp_login_data'] ) ) {
				$content = do_shortcode( shortcode_unautop( $content ) );
				return $content;
			}
			return '';
		} );

		// 指定したシナリオ購入済みの場合だけ表示する
		// [myasp_private_area items="aaa,bbb"]シナリオ「aaa,bbb」購読中ユーザのみに表示[/myasp_private_area]
		add_shortcode( 'myasp_private_area', function ( $atts, $content = null, $tag = '' ) {
			if ( empty( $_SESSION['myasp_login_data'] ) ) {
				return '';
			}

			if ( empty( $atts['items'] ) && empty( $atts['tags'] ) ) {
				// tags, items 最低限いずれか一方は必要
				return '';
			}

			$permission = $this->has_permission( $atts );
			if ( $permission === true ) {
				$content = do_shortcode( shortcode_unautop( $content ) );
				return $content;
			} else {
				return '';
			}
		} );

		// [myasp_private_area]の反対（未ログイン時 or 必要なシナリオ未購入)
		// [!myasp_private_area items="aaa,bbb"]未ログイン または、シナリオ「aaa,bbb」未購読ユーザのみに表示[/!myasp_private_area]
		add_shortcode( '!myasp_private_area', function ( $atts, $content = null, $tag = '' ) {
			if ( empty( $_SESSION['myasp_login_data'] ) ) {
				$content = do_shortcode( shortcode_unautop( $content ) );
				return $content;
			}

			$permission = $this->has_permission( $atts );
			if ( $permission === true ) {
				return '';
			} else {
				$content = do_shortcode( shortcode_unautop( $content ) );
				return $content;
			}
		} );

		// 特定のシナリオに登録されている人（会員）に対してだけ、以降を表示する
		add_shortcode( 'myasp_tag_more_protection', function ( $atts ) {
			return '';
		} );

		// パスワードを表示
		add_shortcode( 'myasp_password', function ( $atts ) {
			if ( ! empty( $_SESSION['myasp_login_data'] ) ) {
				$user = $_SESSION['myasp_login_data']['User'];
				return esc_html( $user['password'] );
			}
			return '';
		} );
		
		// パスワード変更フォーム
		add_shortcode( 'myasp_change_password', function ( $atts, $content = null ) {
			if ( empty( $_SESSION['myasp_login_data'] ) ) {
				// 未ログイン時は表示しない
				return '';
			}
			
			$redirect_to = sanitize_url( $_SERVER["HTTP_HOST"] . $_SERVER['REQUEST_URI'] );
			$redirect_to = strtok( $redirect_to, '?' );
			if ( ! empty( $atts['redirect_to'] ) ) {
				// 戻り先を指定した場合
				$redirect_to = $atts['redirect_to'];
			}

			$change_password = home_url( 'myasp-change-password-page' );
			$param = '';
			if ( ! empty( $redirect_to ) ) {
				$param = '?redirect_to=' . urlencode( $redirect_to );
			}

			// ログイン時しか使えない
			// ログイン画面と同じように、固定ページで「パスワード変更ページ」を作る
			// パスワード変更ページへPOSTしたら、WordPressからAjaxでMyASPAPIを呼び出して変更を行う

			$form = self::password_form_html( $change_password . $param );
			$form = do_shortcode( shortcode_unautop( $form ) );
			return $form;
		} );

		// 固定ページで作成したフォーム用nonce
		add_shortcode( 'myasp_form_nonce', function ( $atts, $content = null ) {
			if ( empty( $atts['action'] ) || empty( $atts['name'] ) ) {
				// 必要
				return '';
			}
			//return wp_nonce_field( self::CREDENTIAL_RESET_ACTION, self::CREDENTIAL_RESET_NAME );
			return wp_nonce_field( $atts['action'], $atts['name'] );
		} );

		// エラーメッセージ表示タグ(アカBANなど)
		$general_error = '';
		if ( $this->last_status === 'error' ) {
			$general_error = '<p class=\"myasp-password-error\">'. esc_html( $this->last_message ) .'</p>';
		}

		add_shortcode( 'myasp_general_error', function () use( $general_error ) {
			return $general_error;
		} );
	
	}

	static function password_form_html( $url )
	{
		$form = 
			'[myasp_password_error]' . "\n" .
			'<form action="' . esc_url( $url ) . '" method="post" class="myasp_password_form">' . "\n" .
			'	[myasp_form_nonce action="' . self::CREDENTIAL_PASSWORD_ACTION . '" name="' . self::CREDENTIAL_PASSWORD_NAME . '"]' . "\n" .
			'	<div class="myasp-password-input">' . "\n" .
			'		<label for="password">現在のパスワード</label>' . "\n" .
			'		<input type="password" name="password" size="20" class="string_trim" placeholder="現在のパスワード" id="password">' . "\n" .
			'	</div>' . "\n" .
			'	<div class="myasp-password-input">' . "\n" .
			'		<label for="new_password1">新しいパスワード</label>' . "\n" .
			'		<input type="password" name="new_password1" size="20" class="string_trim" placeholder="パスワード" id="new_password1">' . "\n" .
			'	</div>' . "\n" .
			'	<div class="myasp-password-input">' . "\n" .
			'		<label for="new_password2">新しいパスワード（確認用）</label>' . "\n" .
			'		<input type="password" name="new_password2" size="20" class="string_trim" placeholder="確認のため再度入力" id="new_password2">' . "\n" .
			'	</div>' . "\n" .
			'	<div class="myasp-password-submit">' . "\n" .
			'		<input class="myasp-password-button" type="submit" value="パスワード変更">' . "\n" .
			'	</div>' . "\n" .
			'</form>';
		
		return $form;
	}

	/**
	 * コンテンツ出力時置き換え処理
	 * ・「myasp_tag_more_protection」があった場合、権限があればタグ以降を表示
	 * ・未ログインの場合、ログインが必要なことを表示
	 * ・ログイン済みだけど、権限がない場合は登録フォームへのリンクを表示
	 * ・アーカイブ系のページも、ログインチェック＋シナリオ購読チェックをして出力を制限(ログインしたときと同じ)
	 */
	function filter_the_content( $the_content )
	{
		if ( is_admin() ) {
			// 管理画面は置き換え対象外
			return $the_content;
		}

		$content = $the_content;

		if ( is_archive() || is_search() || is_home() ) {
			// アーカイブページ( タグ、カテゴリー、日付、作成者等 )
			// 許可していないページの内容は返さない
			$is_logged_in = ! empty( $_SESSION['myasp_login_data'] ); // ログイン済み
			$tags = get_the_tags();
			$is_MyASP_managed = $this->is_MyASP_managed( $tags );
			if ( $is_MyASP_managed ) {
				// MyASP連携タグ
				if ( $is_logged_in ) {
					// ログイン済みの場合、許可されているかチェック
					// $permission = $this->isPermittedPage();
					$permission = $this->has_permission( $tags );
					if ( $permission === self::ALLOWED_PAGE ) {
						// 権限あり。[myasp_tag_more_protection]のフィルタ処理をして返す
						$content = get_the_content(); // タグがない場合があるので再取得
						return $this->filter_protection_tag( $content );
					} else {
						// 非公開、権限なし
						return '### 会員限定ページ ###';
					}
				} else {
					// 未ログインの場合、
					return '### 会員限定ページ ###';
				}
			} else {
				// MyASP連携タグではない。[myasp_tag_more_protection]のフィルタ処理をして返す
				$content = get_the_content(); // タグがない場合があるので再取得
				return $this->filter_protection_tag( $content );
			}
		}

		// アーカイブページ以外の処理
		// 以降は「～この先をみるには～」用の処理

		$matches = []; // ショートコードのマッチ結果
		$permission = false; // trueの場合は閲覧可能(has_permission()の戻り値)
		$allowed_items = []; // [myasp_tag_more_protection]に指定された許可シナリオ一覧

		$content_replaced = $this->filter_protection_tag( $content, $matches, $permission, $allowed_items );
		if ( $permission === true ) {
			// タグがない場合、もしくは閲覧可能な場合
			return $content_replaced;
		} else {
			// 以降は「～この先をみるには～」を追加する
			return $this->append_protection_area( $content, $content_replaced, $matches, $permission, $allowed_items );
		}
	}

	/**
	 * [myasp_tag_more_protection]タグが含まれている場合にtrueを返す
	 */
	function content_have_protection_tag( $content, &$matches = [] )
	{
		$pattern = get_shortcode_regex( ['myasp_tag_more_protection'] );
		preg_match( "/{$pattern}.*/s", $content, $matches );
		if ( count( $matches ) === 0 ) {
			// タグが見つからない場合は、そのまま出力する
			return false;
		}

		if ( $matches[2] === 'myasp_tag_more_protection' ) {
			return true;
		} else {
			// ほかのタグ
			return false;
		}
	}

	/**
	 * [myasp_tag_more_protection]
	 * ・上記タグがない場合は、$contentをのまま返す
	 * ・タグがある && 権限がある場合は、$contentをのまま返す
	 * ・タグがある && 権限がない場合は、タグの前までを返す
	 */
	function filter_protection_tag( $content, &$matches = [], &$permission = false, &$allowed_items = [] )
	{
		$pattern = get_shortcode_regex( ['myasp_tag_more_protection'] );

		// [myasp_tag_more_protection] タグチェック
		if ( $this->content_have_protection_tag( $content, $matches ) ) {
			// [myasp_tag_more_protection] がある場合

			// 引数(item or tags)を取得
			$atts = shortcode_parse_atts( $matches[3] );

			if ( empty( $atts ) ) {
				// tagやitem_id指定がない場合、ログインしていれば許可する
				if ( ! empty( $_SESSION['myasp_login_data'] ) ) {
					$permission = true;
					return $content;
				}
			}

			// 権限がある場合、全文を返す
			// 権限があるかチェック
			$permission = $this->has_permission( $atts, $allowed_items );
			if ( $permission === true ) {
				// 権限があるので全表示
				return $content;
			} elseif ( $permission === false || $permission === self::NOT_ALLOWED_PAGE ) {
				// 権限がない場合は、タグの前までを返す
				$content_replaced = preg_replace( "/{$pattern}.*/s", '', $content );
				return $content_replaced;
			} else {
				// 非公開設定
				return '';
			}
		} else {
			// [myasp_tag_more_protection] がない場合、全て返す
			$permission = true;
			return $content;
		}
	}

	/**
	 * 「～この先をみるには～」を追加する
	 */
	function append_protection_area( $content, $content_replaced, $matches, $permission, $allowed_items )
	{
		$params = [];
		if ( $permission === self::NOT_ALLOWED_PAGE ) {
			if ( ! empty( $_SESSION['myasp_login_data'] ) ) {
				$params['mail'] = sanitize_email( $_SESSION['myasp_login_data']['mail'] );
			}
		}

		$url = sanitize_url(  $_SERVER["HTTP_HOST"] . $_SERVER['REQUEST_URI'] );
		$params['redirect_to'] = $url;
		$params['rid'] = get_the_id();

		// WordPressサイトURL
		$parsed = parse_url( home_url() );
		$params['path'] = $parsed['path'] ?? '';

		// 登録フォームへのurl
		$form_list = $this->form_link_list( $allowed_items, $params );

		// 省略された文字の長さを計算する「この続き：{$rest_count}文字」
		$original_length = mb_strlen( $this->strip_tag_and_shortcode( $content ) );
		$replaced_length = mb_strlen( $this->strip_tag_and_shortcode( $content_replaced ) );
		$rest_count = $original_length - $replaced_length;
		$content = $content_replaced;

		$content .= '<div class="myasp-paywall-title">';
		$content .= '  <p class="has-text-align-center">～ この続きをみるには ～</p>';
		$content .= '</div>';
		$content .= '<div class="myasp-paywall">';
		$content .=	'  <div class="myasp-paywall-form">';

		if ( $permission == self::NOT_ALLOWED_PAGE ) {
			// ログインしたけど権限がない
			$user_name = sanitize_text_field( $_SESSION['myasp_login_data']['User']['name1'] );
			if ( ! empty( $user_name ) ) {
				$content .=		'<p>ようこそ' . esc_html($user_name) . 'さん</p>';
			}
		}
		$content .=		"<p>この続き：{$rest_count}文字</p>";
		$content .=		'<div class="myasp-paywall-procedure">登録すると続きをお読みいただけます</div>';
		$content .=		$form_list;
		$content .=		$matches[5];

		if ( $permission !== false ) {
			// 未ログイン時以外はログアウトリンクを表示する
			$content .=		'<hr>';
			$content .=		'<div class="myasp-login-form">';
			$content .=		'  <p class="myasp-logout-url"><a href="' . esc_url( home_url( 'myasp-logout-page' ) ) . '">ログアウト</a></p>';
			$content .=		'</div>';
		}

		if ( $permission === false ) {
			// 未ログインの場合は、ログインページへのリンクも表示する
			$url = sanitize_url( $_SERVER['REQUEST_URI'] );
			$param = '?redirect_to=' . urlencode( esc_url( $_SERVER["HTTP_HOST"] . $url ) ) . '&rid=' . get_the_id();

			$login_url = home_url( 'myasp-login-page' . $param );
			$content .=		'<hr>';
			$content .=		'<p class="myasp-paywall-login"><a href="' . esc_url( $login_url ) . '">登録済みの方はログイン</a></p>';
		}
		$content .=	'  </div>';
		$content .= '</div>';
		return wp_kses_post( $content );
	}

	/**
	 * タグとショートコードと改行を取り除く
	 * (文字列の長さを取得するために利用)
	 */
	function strip_tag_and_shortcode( $content )
	{
		$content = wp_strip_all_tags( $content );
		$content = preg_replace( '/' . get_shortcode_regex() . '/s', '', $content );
		$content = str_replace( [ "\r\n", "\r", "\n" ], '', $content );
		return $content;
	}

	/**
	 * 登録フォームへのリンクのリストを生成して返す
	 */
	function form_link_list( $allowed_items, $param = [] )
	{
		$domain = get_option( self::PLUGIN_DB_PREFIX . "common_domain" );

		$query = [];
		parse_str( wp_strip_all_tags( urldecode( wp_strip_all_tags( $_SERVER['QUERY_STRING'] ) ) ), $query );
		$query = array_merge( $query, $param );
		$query_string = http_build_query( $query ); // url encode

		// リダイレクト用パラメータ無し番
		unset( $query['redirect_to'] );
		$query_string_without_redirect = http_build_query( $query );

		$form_list = '<div class="myasp-pay-dialog">';
		$isAdded = false;
		foreach ( $allowed_items as $item => $redirect ) {
			if ( empty( $this->items[ $item ] ) ) {
				// タグに指定されたシナリオが存在しない場合、スキップ
				continue;
			}

			$form_list .= '<div class="myasp-pay-dialog-item">';
			$form_list .= '<hr>';
			$isAdded = true;
			$label = $this->items[ $item ]['is_free'] ? 'お申し込み' : '購入手続きへ';
			$form_list .= '<div class="myasp-pay-dialog-title">' . wp_kses_post( $this->items[ $item ]['item_name_for_user'] ) . '</div>' . "\n";
			$url = 'https://' . $domain . '/p/r/' . $item . '?';

			if ( $redirect !== '1' ) {
				// リダイレクトしない(空文字、'0'の場合)
				$form_list .= '<div class="myasp-pay-dialog-regist"><a class="myasp-button-regist" href="' . esc_url( $url . $query_string_without_redirect ) . '">' . esc_html( $label ) . '</a></div>' . "\n";
			} else {
				// リダイレクト($redirect==='1')
				$form_list .= '<div class="myasp-pay-dialog-regist"><a class="myasp-button-regist" href="' . esc_url( $url . $query_string ) . '">' . esc_html( $label ) . '</a></div>' . "\n";
			}
			$form_list .= '</div>';
		}

		// 指定しない場合もあるのでコメントアウト
		// if ( ! $isAdded ) {
		// 	$form_list .= '&lt; 登録先が見つかりません &gt;';
		// }

		$form_list .= '</div>';
		return $form_list;
	}

	/**
	 * 添え字が0から連続する数値(=配列とみなせる)ときにtrue
	 */
	function is_vector( array $arr )
	{
		return array_values( $arr ) === $arr;
	}

	/**
	 * 表示可能かどうかを返す
	 * @param array $atts タグのスラッグのcsv もしくは、get_the_tags()の戻り値
	 * @return 
	 *	true: 表示許可
	 *	false: 未ログイン
	 *	PRIVATE_PAGE: 非公開設定ページ
	 *	NOT_ALLOWED_PAGE: 権限無し(ログイン済み)
	 */
	function has_permission( $atts, &$allowed_items = [] )
	{
		$isPrivate = false;
		$itemNotSet = false;
		$tags = [];

		if ( is_string( $atts ) ) {
			$tags = explode( ',', $atts );
		} elseif ( $this->is_vector( $atts ) ) {
			// 配列の場合
			$tags = $atts;
		} else {
			// ハッシュの場合
			if ( ! empty( $atts['items'] ) ) {
				foreach ( explode( ',', $atts['items'] ) as $item ) {
					// リダイレクトしない？
					$allowed_items[ $item ] = "0";
				}
			}

			if ( ! empty( $atts['tags'] ) ) {
				$tags = explode( ',', $atts['tags'] );
			}
		}

		// タグに紐づくシナリオを取得
		foreach ( $tags as $tag ) {
			if ( is_string( $tag ) ) {
				$term = get_term_by( 'slug', $tag, 'post_tag' );
				if ( $term === false ) {
					continue;
				}
			} else {
				$term = $tag;
			}

			$term_meta = get_option( $term->taxonomy . '_' . $term->term_id );

			if ( $term_meta !== false ) {
				if ( empty( $term_meta['myasp_open_status'] ) || $term_meta['myasp_open_status'] == '0' ) {
					// 非公開の場合、シナリオ一覧に追加しない
					$isPrivate = true;
					continue;
				}

				if ( empty( $term_meta['myasp_allowed_item'] ) ) {
					// 付与されたタグ中に、シナリオ未設定(全てのシナリオで閲覧可能)があればＯＫ
					$itemNotSet = true;
				}

				foreach ( $term_meta['myasp_allowed_item'] as $item ) {
					$allowed_items[ $item ] = $term_meta['myasp_auto_redirect'];
				}
			}
		}

		if ( empty( $_SESSION['myasp_login_data'] ) ) {
			// 未ログイン
			return false;
		}

		if ( $itemNotSet ) {
			return self::ALLOWED_PAGE;
		}

		if ( $this->last_status == 'error' ) {
			// Sellerが無効(BAN)の場合
			return self::PRIVATE_PAGE; // 非公開設定ページ(エラーメッセージを表示する)
		}

		// 紐づいたシナリオ一覧
		$registered_items = $_SESSION['myasp_login_data']['registered_items'];
		foreach ( array_keys( $allowed_items ) as $item ) {
			foreach ( $registered_items as $regitem ) {
				if ( $this->validate_item_id( $regitem['id'] ) ) {
					if ( $regitem['id'] == $item ) {
						// 許可
						return self::ALLOWED_PAGE;
					}
				}
			}
		}

		if ( $isPrivate ) {
			return self::PRIVATE_PAGE; // 非公開設定ページ
		} else {
			return self::NOT_ALLOWED_PAGE; // 権限無し
		}
	}

	/**
	 * 外部送受信共通関数
	 * ・APIトークンをhttp headerに付与して送信する
	 */
	function remote_request( $url, $method = 'GET', $param = null, $api_token = null )
	{
		// APIトークン(ヘッダにつけて送信)
		$api_token = $api_token ?? get_option( self::PLUGIN_DB_PREFIX . 'common_api_token' );

		$args = [
			'method' => $method,
			'blocking' => true,
			'headers' => [
				'Content-Type' => 'application/json',
				'X-Requested-With' => 'XMLHttpRequest',
				'MyASP-WP-API-TOKEN' => $api_token,
				// MyASP側でremote_hostを取得するとバーチャルホストが取れないのでヘッダで渡す
				'MyASP-WP-REMOTE-HOST' => sanitize_text_field( $_SERVER['SERVER_NAME'] ),
			],
		];

		if ( $param ) {
			$args['body'] = wp_json_encode( $param );
		}

		$response = wp_remote_request( $url, $args );
		if ( $response instanceof WP_Error ) {
			return false;
		}

		return $response['body'];
	}

	/**
	 * MyASPログイン処理
	 */
	function login_MyASP()
	{
		$seller_id = get_option( self::PLUGIN_DB_PREFIX . "common_seller_id" );
		$domain = get_option( self::PLUGIN_DB_PREFIX . "common_domain" );

		// nonceで設定したcredentialのチェック
		if ( ! isset( $_POST[ self::CREDENTIAL_LOGIN_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::CREDENTIAL_LOGIN_NAME ] ) ), self::CREDENTIAL_LOGIN_ACTION ) ) {
			return ['status' => 'NG'];
		}

		$response = ['status' => 'NG'];

		// メールアドレスとパスワードのチェック
		if ( ! $this->validate_regexp( self::EMAIL_REGULAR_EXPRESSION, $_POST['mail'] ) ) {
			return $response;
		}

		if ( ! $this->validate_regexp( self::PASSWORD_REGULAR_EXPRESSION, $_POST['password'] ) ) {
			return $response;
		}

		// MyASPサーバにログイン確認を行う
		$param = [];
		$param['seller_id'] = $seller_id;
		$param['mail'] = sanitize_email( $_POST['mail'] );
		$param['password'] = $_POST['password'];

		$url = "https://{$domain}/wordpressApi/wp_login";
		$response = json_decode( $this->remote_request( $url, 'POST', $param ), true );

		return $response;
	}

	/**
	 * 正規表現で入力文字列のチェックを行う
	 * @return bool 
	 *  true: 検証成功、false: 検証失敗
	 */
	function validate_regexp( $regexp, $value) 
	{
		return preg_match( $regexp, $value ) === 1;
	}

	/**
	 * パスワード変更処理API呼び出し
	 */
	function myasp_change_password( $post )
	{
		$response = ['status' => 'NG', 'message' => 'パスワードが正しくありません。'];

		$seller_id = get_option( self::PLUGIN_DB_PREFIX . "common_seller_id" );
		$domain = get_option( self::PLUGIN_DB_PREFIX . "common_domain" );
		$user_id = sanitize_text_field( $_SESSION['myasp_login_data']['User']['id'] );
		$mail = sanitize_email( $_SESSION['myasp_login_data']['User']['mail'] );

		if ( ! $this->validate_regexp( self::PASSWORD_REGULAR_EXPRESSION, $post['password'] ) ) {
			return $response;
		}
		if ( ! $this->validate_regexp( self::PASSWORD_REGULAR_EXPRESSION, $post['new_password1'] ) ) {
			return $response;
		}
		if ( ! $this->validate_regexp( self::PASSWORD_REGULAR_EXPRESSION, $post['new_password2'] ) ) {
			return $response;
		}

		// MyASPサーバでパスワード変更処理をよびだす
		$param = [];
		$param['seller_id'] = $seller_id;
		$param['user_id'] = $user_id;
		$param['mail'] = $mail;
		$param['password'] = $post['password'];
		$param['new_password1'] = $post['new_password1'];
		$param['new_password2'] = $post['new_password2'];

		$url = "https://{$domain}/wordpressApi/wp_change_password";
		$response = json_decode( $this->remote_request( $url, 'POST', $param ), true );

		return $response;
	}

	/**
	 * パスワードリマインダーメール送信API呼び出し
	 */
	function myasp_request_reminder( $post )
	{
		$response = ['status' => 'NG', 'message' => 'メールアドレスが正しくありません。'];

		$seller_id = get_option( self::PLUGIN_DB_PREFIX . "common_seller_id" );
		$domain = get_option( self::PLUGIN_DB_PREFIX . "common_domain" );

		if ( ! $this->validate_regexp( self::EMAIL_REGULAR_EXPRESSION, $post['mail1'] ) ) {
			return $response;
		}
		if ( ! $this->validate_regexp( self::EMAIL_REGULAR_EXPRESSION, $post['mail2'] ) ) {
			return $response;
		}

		$param = [];
		$param['seller_id'] = $seller_id;
		// WPがサブディレクトリにインストールしてある場合を考慮してhome_url()から取得
		$param['domain'] = preg_replace( '/https?:\/\//', '', home_url() );
		$param['mail1'] = sanitize_email( $post['mail1'] );
		$param['mail2'] = sanitize_email( $post['mail2'] );

		$url = "https://{$domain}/wordpressApi/wp_password_reminder_send_mail";
		$response = json_decode( $this->remote_request( $url, 'POST', $param ), true );

		return $response;
	}

	/**
	 * パスワードリマインダー(パスワード変更)API呼び出し
	 */
	function myasp_reminder_reset_password( $post )
	{
		$response = ['status' => 'NG', 'message' => 'パスワードが正しくありません。'];

		$seller_id = get_option( self::PLUGIN_DB_PREFIX . "common_seller_id" );
		$domain = get_option( self::PLUGIN_DB_PREFIX . "common_domain" );

		if ( ! $this->validate_regexp( self::PASSWORD_REGULAR_EXPRESSION, $post['new_password1'] ) ) {
			return $response;
		}
		if ( ! $this->validate_regexp( self::PASSWORD_REGULAR_EXPRESSION, $post['new_password2'] ) ) {
			return $response;
		}

		$param = [];
		$param['seller_id'] = $seller_id;
		$param['reminder_id'] = get_query_var( 'reminder' );
		$param['new_password1'] = $post['new_password1'];
		$param['new_password2'] = $post['new_password2'];

		$url = "https://{$domain}/wordpressApi/wp_reminder_reset_password";
		$response = json_decode( $this->remote_request( $url, 'POST', $param ), true );

		return $response;
	}

	/**
	 * 親メニューの追加
	 */
	function set_myasp_menu()
	{
		add_menu_page(
			'MyASP連携設定',				/* ページタイトル*/
			'MyASP連携設定',				/* メニュータイトル */
			'manage_options',				/* 権限 */
			self::MENU_PARENT_SLUG,			/* ページを開いたときのURL(menu_slug) */
			[ $this, 'show_myasp_config' ], /* メニューに紐づく画面を描画するcallback関数 */
			'dashicons-format-links',		/* アイコン see: https://developer.wordpress.org/resource/dashicons/#awards */
			81								/* 表示位置 (81-「設定」 の次) */
		);
	}

	/**
	 * メニュー(親)の画面
	 */
	function show_myasp_config()
	{
		// wp_optionsのデータを取得して表示
		$seller_id = get_option( self::PLUGIN_DB_PREFIX . "common_seller_id" );
		$domain = get_option( self::PLUGIN_DB_PREFIX . "common_domain" );
		$api_token = get_option( self::PLUGIN_DB_PREFIX . "common_api_token" );
		$item_id = get_option( self::PLUGIN_DB_PREFIX . "common_item_id" );

		// ドメインが未入力の場合のMyASPログイン画面
		$myasp_home = 'https://member.myasp.jp/login/';
		if ( ! empty( $domain ) ) {
			$myasp_home = "https://{$domain}/member";
		}

?>
		<div class="wrap">
			<h1>MyASP連携設定</h1>
			<div class="myasp-content-infomation">
				<p>この画面では、MyASPと連携するために必要なドメイン名とAPIトークンを設定します。</p>
				<p><a href="https://myasp.jp/">MyASP</a>(スタンダードプラン)の契約が必要です。</p>
				<p>
					・<a href="https://docs.myasp.jp/?p=35348" target="_blank">ヘルプ</a><br>
					・<a href="<?php echo esc_url( $myasp_home ); ?>" target="_blank">MyASPトップページ</a><br>
					<?php
					if ( ! empty( $domain ) ) {
					?>
						・<a href="<?php echo esc_url( $myasp_home ); ?>/wordpress_setting" target="_blank">MyASP-WordPress連携設定</a>
					<?php
					}
					?>
				</p>
			</div>
			<form action="" method="post" id="myasp-config-form">
				<?php // nonceの設定 
				?>
				<?php wp_nonce_field( self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME ) ?>
				<div class="myasp-general-setting">
					<div>
						<div class="myasp-general-setting-title">
							<label for="common_domain" class="myasp-tooltip">MyASPサーバー<br>ドメイン名</label>
						</div>
						<div>
							<input type="text" name="common_domain" id="common_domain" style="width:25em;" value="<?php echo esc_attr( $domain ); ?>" />
							<p class="description">MyASPのドメイン名を入力します。<br>
								例：MyASPのURLが「https://example.com/member/index」の場合は、「example.com」を入力</p>
						</div>
					</div>
					<div>
						<div>
							<label for="common_api_token">APIトークン</label>
						</div>
						<div>
							<input type="text" name="common_api_token" id="common_api_token" style="width:25em;" value="<?php echo esc_attr( $api_token ); ?>" />
							<p class="description">MyASPの「WordPress連携設定」画面で発行したAPIトークンを入力します。<br>
							</p>
						</div>
					</div>
					<div>
						<div>
						</div>
						<div>
							<input type="submit" name="save" value="保 存" class="button button-primary button-large;">
						</div>
					</div>
				</div>
			</form>
			<hr>
			<div class="myasp-general-setting">
				<div>
					<div class="myasp-general-setting-title">
						<label>フォームリセット</label>
					</div>
					<div>
						<form action="" method="post" id="myasp-reset-form">
							<?php wp_nonce_field( self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME ) ?>
							<input type="submit" name="reset" value="リセット" class="button button-secondary button-large" onclick="return confirm( 'ログインページをリセットします。よろしいですか？' )"></input>
							<p><a href="<?php echo esc_url( home_url( 'myasp-login-page' ) ); ?>" target="_blank">ログインページ</a>をリセット(初期設定に戻す)します。</p>
						</form>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * 設定画面の項目データベースに保存する
	 */
	function save_myasp_config()
	{
		// nonceで設定したcredentialのチェック
		if ( ! isset( $_POST[ self::CREDENTIAL_NAME ] ) || ! check_admin_referer( self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME ) ) {
			return;
		}

		if ( isset( $_POST['save'] ) ) {
			// 入力チェック
			if ( ! $this->validate_config() ) {
				return;
			}

			// 変更前の値
			$prev_domain = get_option( self::PLUGIN_DB_PREFIX . "common_domain" );
			$prev_apiToken = get_option( self::PLUGIN_DB_PREFIX . "common_api_token" );

			// 保存処理
			$domain = wp_strip_all_tags( $_POST['common_domain'] ) ?? "";
			$apiToken = wp_strip_all_tags( $_POST['common_api_token'] ) ?? "";

			// APITokenのチェック(MyASPに接続して設定が存在するか確認する)
			$response = $this->myasp_validate_token( $domain, $apiToken );
			if ( empty( $response ) || $response['status'] != 'OK' ) {
				add_settings_error( 'validateConfig', 'validateConfig', "設定内容を確認してください(MyASPに登録されていません)", 'error' );
				return;
			}

			$seller_id = substr( $apiToken, 0, 8 );
			update_option( self::PLUGIN_DB_PREFIX . 'common_seller_id', $seller_id );
			update_option( self::PLUGIN_DB_PREFIX . 'common_domain', $domain );
			update_option( self::PLUGIN_DB_PREFIX . 'common_api_token', $apiToken );

			// 処理後のメッセージをtransientにセット
			$domain_msg = ( $prev_domain == $domain ) ? '' : "ドメイン：{$prev_domain}⇒{$domain}、";
			$api_msg =	( $prev_apiToken == $apiToken ) ? '' : "APIトークン：{$prev_apiToken}⇒{$apiToken}、";
			$msg = mb_substr( "{$domain_msg}{$api_msg}", 0, -1 );
			$completed_text = "設定を保存しました。" . ( empty( $msg ) ? '' : "( {$msg} )" );
			set_transient( self::COMPLETE_TRANSIENT_KEY, $completed_text, 5 );
		} elseif ( isset( $_POST['reset'] ) ) {
			// ログイン、登録フォームのリセット(固定ページを削除してから、デフォルトのページへ戻す)
			$this->remove_custom_page();
			$this->add_custom_page();
		}
	}

	/**
	 * 入力チェック
	 */
	function validate_config()
	{
		// nonceで設定したcredentialのチェック
		if ( ! isset( $_POST[ self::CREDENTIAL_NAME ] ) || ! check_admin_referer( self::CREDENTIAL_ACTION, self::CREDENTIAL_NAME ) ) {
			return false;
		}

		$domain = wp_strip_all_tags( $_POST['common_domain'] ) ?? "";
		if (
			! $this->check_required( $domain, 'MyASPサーバードメイン' ) ||
			! $this->check_regex( $domain, '/^([a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]*\.)+[a-zA-Z]{2,}$/', 'MyASPサーバードメイン' )
		) {
			return false;
		}

		$apiToken = wp_strip_all_tags( $_POST['common_api_token'] ) ?? "";
		if (
			! $this->check_required( $apiToken, 'APIトークン' ) ||
			! $this->check_regex( $apiToken, '/^[0-9a-zA-Z]+$/', 'APIトークン' )
		) {
			return false;
		}

		return true;
	}

	/**
	 * 必須チェック
	 */
	function check_required( $value, $itemLabel )
	{
		if ( empty( $value ) ) {
			add_settings_error( 'validateConfig', 'validateConfig', "{$itemLabel}は必須です", 'error' );
			return false;
		}
		return true;
	}

	/**
	 * 正規表現でチェック
	 */
	function check_regex( $value, $regexp, $itemLabel )
	{
		if ( ! preg_match( $regexp, $value ) ) {
			add_settings_error( 'validateConfig', 'validateConfig', "{$itemLabel}が正しくありません", 'error' );
			return false;
		}

		return true;
	}

	/**
	 * tokenのフォーマットを検証する
	 * ・英数字16文字
	 * @return bool 
	 *  true: 検証成功、false: 検証失敗
	 */
	function validate_token( $token )
	{
		$pattern = '/^[a-zA-Z0-9]{16}$/';
		return preg_match( $pattern, $token ) === 1;
	}

	/**
	 * item_idのフォーマットを検証する
	 * ・英数字8文字
	 * @return bool 
	 *  true: 検証成功、false: 検証失敗
	 */
	function validate_item_id( $item_id ) {
		// 正規表現を使って英数字8文字であるかをチェック
		$pattern = '/^[a-zA-Z0-9]{8}$/';
		return preg_match( $pattern, $item_id ) === 1;
	}

	/**
	 * APIトークンのフォーマットを検証する
	 * ・英数字32文字
	 * @return bool 
	 *  true: 検証成功、false: 検証失敗
	 */
	function validate_api_token( $api_token )
	{
		$pattern = '/^[a-zA-Z0-9]{32}$/';
		return preg_match( $pattern, $api_token ) === 1;
	}

	/**
	 * MyASP 自動ログイン処理呼び出し
	 */
	function myasp_validate_token( $domain, $api_token )
	{
		$url = "https://{$domain}/wordpressApi/wp_validate_token";
		$response = $this->remote_request( $url, 'GET', null, $api_token );
		$response = json_decode( $response, true );

		return $response;
	}

	/**
	 * 保存完了メッセージ
	 */
	public function admin_notices()
	{
		global $pagenow;
		if ( $pagenow != 'admin.php' || sanitize_text_field( $_GET['page'] ) !== self::PLUGIN_ID ) {
			return;
		}

		// 保存したメッセージがあれば表示する
		if ( $notice = get_transient( self::COMPLETE_TRANSIENT_KEY ) ) {
		?>
			<div id="message" class="notice notice-success is-dismissible">
				<p> <?php echo esc_html( $notice ); ?></p>
			</div>
		<?php
		}
	}

	/**
	 * カスタム投稿タイプでタグ付けられるように変更
	 */
	function add_tag_to_page()
	{
		register_taxonomy_for_object_type( 'post_tag', 'page' );
		// カテゴリーは使わないので有効化しない
		// register_taxonomy_for_object_type( 'category','page' );

		// カスタム投稿タイプの一覧を取得して、それぞれに「タグ」を設定できるようにする
		if ( self::ENABLE_CUSTOM_POST_TYPE ) {
			$args = [
				'public'   => true, // 公開されているもの.
				'_builtin' => false, // カスタム投稿タイプ.
			];
			$custom_type_slugs = get_post_types( $args, 'names', 'and' ); // 投稿タイプ名(スラッグ)の配列
			foreach ( $custom_type_slugs as $slug ) {
				// カスタム投稿タイプもタグを有効にする
				register_taxonomy_for_object_type( 'post_tag', $slug );
			}
		}
	}

	/**
	 * タグの編集画面に入力欄を追加
	 * （クイック編集は別処理add_customfield_to_quickedit)
	 */
	function add_taxonomy_fields( $term )
	{
		$term_id = $term->term_id; //タームID
		$taxonomy = $term->taxonomy; //タームIDに所属しているタクソノミー名
		//すでにデータが保存されている場合はDBから取得する
		$term_meta = get_option( $taxonomy . '_' . $term_id );

		if ( empty( $term_meta['myasp_allowed_item'] ) ) {
			// エラー回避のため空配列をセット
			$term_meta['myasp_allowed_item'] = [];
		}

		// hiddenはcheckboxのチェックが外れた場合、空文字("")がPostされるようにするために追加
		// （checkboxはチェックを外すと値がPostされ無くなり、データ保存が面倒になるため）
		wp_nonce_field( self::CREDENTIAL_TAXONOMY_ACTION, self::CREDENTIAL_TAXONOMY_NAME );
		?>
		<tr class="form-field myasp-input-field ">
			<th scope="row"><label for="term_meta[myasp_managed_post]">MyASP連携</label></th>
			<td>
				<input type="hidden" name="term_meta[myasp_managed_post]" value="" />
				<input type="checkbox" name="term_meta[myasp_managed_post]" id="term_meta[myasp_managed_post]" value="checked" <?php echo esc_attr( ( isset( $term_meta['myasp_managed_post'] ) && $term_meta['myasp_managed_post'] == 'checked' ) ? 'checked' : '' ); ?>>
				連携する</input>
				<ul class="myasp-list">
					<li>・チェックを入れることで、このタグを付与した記事をMyASPと連携した公開／非公開を制御することができます。</li>
					<li>・記事を表示する際にログイン画面を表示し、ログインすることができた人のみに該当の記事を閲覧させることができます。<br>
						（MyASPに登録したメールアドレスでログインします）</li>
				</ul>
			</td>
		</tr>
		<tr class="form-field myasp-input-field myasp-toggle-field">
			<th scope="row"><label for="term_meta[myasp_open_status]">公開状態</label></th>
			<td>
				<label><input type="radio" name="term_meta[myasp_open_status]" value="1" <?php echo esc_attr( ( isset( $term_meta['myasp_open_status'] ) && $term_meta['myasp_open_status'] != '0' ) ? 'checked' : '' ); ?>>公開</input></label>
				<label><input type="radio" name="term_meta[myasp_open_status]" value="0" <?php echo esc_attr( ( isset( $term_meta['myasp_open_status'] ) && $term_meta['myasp_open_status'] == '0' ) ? 'checked' : '' ); ?>>非公開</input></label>
				<p class="description">このタグが付いている記事の「公開／非公開」を一括で指定することができます。</p>
				<ul class="myasp-list">
					<li>・複数のタグが付いている場合は、１つでも「公開」設定のタグが付いていると、該当の記事を閲覧することができます。（or条件）</li>
					<li>・「非公開」に設定した場合、対象の記事にアクセスすると「閲覧不可ページ」が表示されます。</li>
				</ul>
			</td>
		</tr>
		<tr class="form-field myasp-input-field myasp-toggle-field">
			<th scope="row"><label for="term_meta[myasp_allowed_item]">許可シナリオ</label></th>
			<td>
				<select class="allowed-item_list" name="term_meta[myasp_allowed_item][]" multiple="multiple" style="width:95%;">
					<?php
					foreach ( $this->group_items as $group_name => $group ) {
						echo '<optgroup label="' . esc_attr( $group_name ) . '">';
						foreach ( $group as $item_cd => $item_name ) {
							echo '<option value="' . esc_attr($item_cd) . '" ' . esc_attr( in_array( $item_cd, $term_meta['myasp_allowed_item'] ) ? 'selected' : '' ) . ">" . esc_html($item_name) . "</option>";
						}
						echo '</optgroup>';
					}
					?>
				</select>
				<ul class="myasp-list">
					<li>・ここで指定したシナリオに登録しているユーザーのみ、この記事を閲覧することができます。</li>
					<li>・シナリオを複数指定した場合は、どれか1つのシナリオで有効なユーザーであれば、閲覧することができます。</li>
					<li>※「許可シナリオ」を空欄にした場合<br>
						全シナリオのうち、どれか１つのシナリオに登録されている 且つ 有効なユーザーであれば、閲覧することができます。</li>
				</ul>
			</td>
		</tr>
		<tr class="form-field myasp-input-field myasp-toggle-field">
			<th scope="row"><label for="term_meta[myasp_auto_redirect]">登録後、記事へリダイレクトする</label></th>
			<td>
				<label><input type="radio" name="term_meta[myasp_auto_redirect]" value="1" <?php echo esc_attr( ( isset( $term_meta['myasp_auto_redirect'] ) && $term_meta['myasp_auto_redirect'] != '0' ) ? 'checked' : '' ); ?>>記事へリダイレクトする</input></label>
				<label><input type="radio" name="term_meta[myasp_auto_redirect]" value="0" <?php echo esc_attr( ( isset( $term_meta['myasp_auto_redirect'] ) && $term_meta['myasp_auto_redirect'] == '0' ) ? 'checked' : '' ); ?>>サンクスページを表示する</input></label>

				<ul class="myasp-list">
					<li>・ユーザー登録後、WordPressの記事へリダイレクトするか、サンクスページを表示するかを設定します。</li>
					<li>・閲覧するための条件に満たなかった場合は、登録フォームへのリンクが表示されます。</li>
					<li>※有料商品シナリオの場合は、設定に関わらず常にサンクスページが表示されます。</li>
				</ul>
			</td>
		</tr>
		<tr class="form-field myasp-input-field myasp-toggle-field">
			<th scope="row"><label for="term_meta[myasp_login_url]">独自ログインページ</label></th>
			<td>
				<input type="text" name="term_meta[myasp_login_url]" placeholder="(省略可)" value="<?php echo esc_attr( isset( $term_meta['myasp_login_url'] ) ? $term_meta['myasp_login_url'] : '' ); ?>"></input>
				<p class="description">このタグをつけた記事を初めて開いたときに表示されるログインページを指定します。<br>
					　・省略した場合は、固定ページ＞ログインページが利用されます。<br>
					　・このタグをつけた記事のみ異なるデザインのログインページにしたい場合は、ここで指定します。<br>
					　※ログインページは以下の場合に表示されます。<br>
					　　・これまで１度もログインしたことがない場合<br>
					　　・許可シナリオに設定したシナリオに登録済みで、且つ有効ではない場合<br>
				</p>
			</td>
		</tr>
		<?php
	}

	/**
	 * タグの編集画面　データ保存
	 */
	function save_taxonomy_fileds( $term_id )
	{
		global $taxonomy; //タクソノミー名を取得

		if ( ! isset( $_POST[ self::CREDENTIAL_TAXONOMY_NAME ] ) || ! check_admin_referer( self::CREDENTIAL_TAXONOMY_ACTION, self::CREDENTIAL_TAXONOMY_NAME ) ) {
			return;
		}

		if ( isset( $_POST['term_meta'] ) ) {
			//追加項目に値が入っていたら処理する
			$term_meta = get_option( $taxonomy . '_' . $term_id );
			$term_keys = array_keys( $_POST['term_meta'] );
			
			foreach ( $term_keys as $key ) {
				// 不正なキーが含まれていたら処理を行わない
				if ( ! in_array( $key , self::TERM_INPUT_KEYS, true ) ) {
					return;
				}
			}

			foreach ( $term_keys as $key ) {
				if ( isset( $_POST['term_meta'][ $key ] ) ) {
					// 元の値に値し、POSTされた値で上書き
					if ( $key !== 'myasp_allowed_item' ) {
						$term_meta[ $key ] = stripslashes_deep( sanitize_text_field( $_POST['term_meta'][ $key ] ) );
					} else {
						$items = [];
						foreach( $_POST['term_meta'][ $key ] as $item ) {
							$items[] = sanitize_text_field($item);
						}
						$term_meta[ $key ] = $items;
					}
				}
			}

			if ( ! in_array( 'myasp_allowed_item', $term_keys, true ) ) {
				// 許可シナリオが未選択の場合、空配列をセットする
				$term_meta['myasp_allowed_item'] = [];
			}

			// 複数の値をまとめて保存
			update_option( $taxonomy . '_' . $term_id, $term_meta );
		}
	}

	/**
	 * タグ管理画面に「MyASP連携」カラムヘッダーを設置する
	 * ・ここで追加した(列数×行数)分、add_taxonomy_custom_fields()が呼び出され、一覧に値が表示される
	 */
	function add_taxonomy_columns( $columns )
	{
		$index = 4; // 追加位置

		return array_merge(
			array_slice( $columns, 0, $index ),
			[
				'myasp_managed_post' => 'MyASP連携',
				'myasp_open_status' => '公開状況',
				'myasp_allowed_item' => '許可シナリオ',
				'myasp_auto_redirect' => '登録後、記事へリダイレクトする',
				'myasp_login_url' => '独自ログインページ',
			],
			array_slice( $columns, $index )
		);
	}

	/**
	 * タグ管理画面(一覧)に、MyASP連携用項目(列)追加
	 */
	function add_taxonomy_custom_fields( $content, $column_name, $term_id )
	{
		global $taxonomy; //タクソノミー名を取得
		$term_meta = get_option( $taxonomy . '_' . $term_id );

		$comment = '';
		switch ( $column_name ) {
			case 'myasp_managed_post': // MyASP連携
				$value = $term_meta ? $term_meta[ $column_name ] : '';
				$content = '<input type="checkbox" disabled ' . esc_attr( ( $value == 'checked' ? 'checked' : '' ) ) . '>';
				break;
			case 'myasp_open_status': // 公開状態
				$value = $term_meta && isset( $term_meta[ $column_name ] ) ? $term_meta[ $column_name ] : '';
				if ( ! empty( $value ) ) {
					$comment = '公開';
				} else {
					$comment = '非公開';
				}
				$content = '<input type="hidden" value="' . esc_attr( $value ) . '">' . esc_html( $comment );
				break;
			case 'myasp_allowed_item': // 許可シナリオ
				$items = $term_meta && isset( $term_meta[ $column_name ] ) ? $term_meta[ $column_name ] : '';
				if ( ! empty( $items ) ) {
					$value = '';
					foreach ( $items as $item ) {
						if ( ! empty( $this->items[ $item ] ) ) {
							if ( ! empty( $value ) ) {
								$value .= ',';
							}
							$comment .= wp_strip_all_tags( $this->items[ $item ]['item_name_for_user'] ) . '<br>';
							$value .= $item;
						}
					}
				} else {
					$value = '';
					if ( ! empty( $term_meta['myasp_managed_post'] ) && $term_meta['myasp_managed_post'] == 'checked' ) {
						$comment = '(全てのシナリオで許可)';
					}
				}
				$content = '<input type="hidden" value="' . esc_attr( $value ) . '">' . wp_kses( $comment, [ 'br' => [] ] );
				break;
			case 'myasp_auto_redirect': // 登録後、記事へリダイレクトする
				$value = $term_meta && isset( $term_meta[ $column_name ] ) ? $term_meta[ $column_name ] : '';
				if ( ! empty( $value ) ) {
					$comment = '記事へリダイレクトする';
				} else {
					$comment = 'サンクスページを表示する';
				}
				$content = '<input type="hidden" value="' . esc_attr( $value ) . '">' . esc_html( $comment );
				break;
			case 'myasp_login_url': // 独自ログインページ
				$value = $term_meta ? $term_meta[ $column_name ] : '';
				if ( ! empty( $value ) ) {
					$comment = wp_strip_all_tags( $value );
				} else {
					$value = '';
				}
				$content = '<input type="hidden" value="' . esc_attr( $value ) . '">' . esc_html( $comment );
				break;
		}
		return $content;
	}

	/**
	 * クイック編集に「入力項目」を追加
	 */
	function add_customfield_to_quickedit( $column_name, $post_type )
	{
		if ( $post_type === 'edit-tags' ) {
			static $print_nonce = TRUE;
			if ( $print_nonce ) {
				$print_nonce = FALSE;
				wp_nonce_field( self::CREDENTIAL_TAXONOMY_ACTION, self::CREDENTIAL_TAXONOMY_NAME );
			}

			switch ( $column_name ) {
				case 'myasp_managed_post':
		?>
					<fieldset class="myasp-quick-field">
						<div class="inline-edit-col">
							<label>
								<span class="title myasp-tooltip">MyASP連携
									<span class="myasp-description">
										・チェックを入れることで、このタグを付与した記事をMyASPと連携した公開／非公開を制御することができます。<br>
										・記事を表示する際にログイン画面を表示し、ログインすることができた人のみに該当の記事を閲覧させることができます。<br>
										　（MyASPに登録したメールアドレスでログインします）<br>
									</span>
								</span>
								<span class="input-text-wrap">
									<input type="hidden" name="term_meta[myasp_managed_post]" value="" />
									<input type="checkbox" name="term_meta[myasp_managed_post]" value="checked">閲覧制限機能を有効にします</input>
								</span>
							</label>
						</div>
					</fieldset>
				<?php
					break;
				case 'myasp_open_status':
				?>
					<fieldset class="myasp-quick-field myasp-toggle-field">
						<div class="inline-edit-col">
							<label>
								<span class="title myasp-tooltip">公開状態
									<span class="myasp-description">
										このタグが付いている記事の「公開／非公開」を一括で指定することができます。<br>
										　・複数のタグが付いている場合は、１つでも「公開」設定のタグが付いていると、該当の記事を閲覧することができます。（or条件）<br>
										　・「非公開」に設定した場合、対象の記事にアクセスすると「閲覧不可ページ」が表示されます。<br>
									</span>
								</span>
								<span class="input-text-wrap myasp_open_status">
								</span>
							</label>
						</div>
					</fieldset>
				<?php
					break;
				case 'myasp_allowed_item':
				?>
					<fieldset class="myasp-quick-field myasp-toggle-field">
						<div class="inline-edit-col">
							<label>
								<span class="title myasp-tooltip">許可シナリオ
									<span class="myasp-description">
										・ここで指定したシナリオに登録しているユーザーのみ、この記事を閲覧することができます。<br>
										・シナリオを複数指定した場合は、どれか1つのシナリオで有効なユーザーであれば、閲覧することができます。<br>
										　※「許可シナリオ」を空欄にした場合<br>
										　　全シナリオのうち、どれか１つのシナリオに登録されている 且つ 有効なユーザーであれば、閲覧することができます。<br>
									</span>
								</span>
								<span class="input-text-wrap">
									<select class="allowed-item_list" name="term_meta[myasp_allowed_item][]" multiple="multiple" style="width:100%;">
										<?php
										foreach ( $this->group_items as $group_name => $group ) {
											echo '<optgroup label="' . esc_attr( $group_name ) . '">';
											foreach ( $group as $item_cd => $item_name ) {
												echo '<option value="' . esc_attr( $item_cd ) .'">' . esc_html( $item_name ) . '</option>';
											}
											echo '</optgroup>';
										}
										?>
									</select>
								</span>
							</label>
						</div>
					</fieldset>
				<?php
					break;
				case 'myasp_auto_redirect':
				?>
					<fieldset class="myasp-quick-field myasp-toggle-field">
						<div class="inline-edit-col">
							<label>
								<span class="title myasp-tooltip">登録後、記事へリダイレクトする
									<span class="myasp-description">
										・ユーザー登録後、WordPressの記事へリダイレクトするか、サンクスページを表示するかを設定します。<br>
										・閲覧するための条件に満たなかった場合は、登録フォームへのリンクが表示されます。<br>
										　※有料商品シナリオの場合は、設定に関わらず常にサンクスページが表示されます。<br>
									</span>
								</span>
								<span class="input-text-wrap myasp_auto_redirect">
								</span>
							</label>
						</div>
					</fieldset>
				<?php
					break;
				case 'myasp_login_url':
				?>
					<fieldset class="myasp-quick-field myasp-toggle-field">
						<div class="inline-edit-col">
							<label>
								<span class="title myasp-tooltip">独自ログインページ
									<span class="myasp-description">
										このタグをつけた記事を初めて開いたときに表示されるログインページを指定します。<br>
										　・省略した場合は、固定ページ＞ログインページが利用されます。<br>
										　・このタグをつけた記事のみ異なるデザインのログインページにしたい場合は、ここで指定します。<br>
										　※ログインページは以下の場合に表示されます。<br>
										　　・これまで１度もログインしたことがない場合<br>
										　　・許可シナリオに設定したシナリオに登録済みで、且つ有効ではない場合<br>
									</span>
								</span>
								<span class="input-text-wrap">
									<input type="text" name="term_meta[myasp_login_url]" placeholder="(省略可)ログイン画面を差し替える場合に入力します"></input>
								</span>

							</label>
						</div>
					</fieldset>
<?php
					break;
			}
		}
	}

	/**
	 * クイック編集用のjsファイル読み込み（一覧に表示したデータを、編集項目に転記するために使う)
	 */
	function wp_my_admin_enqueue_scripts( $hook )
	{
		// タグを編集
		if ( $hook == 'term.php' ) {
			// 複数選択コンポーネント読み込みテスト(Select2)
			wp_enqueue_style( 'Select2-css', plugins_url( 'admin/css/select2.min.css', __FILE__ ), [], '4.0.13' );
			wp_enqueue_script( 'Select2-js', plugins_url( 'admin/js/select2.min.js', __FILE__ ), [], '4.0.13', true );

			// tooltip
			wp_enqueue_style( 'style', plugins_url( 'admin/css/admin-myasp.css', __FILE__ ), [], '1.0.0' );

			// jsファイル(Select2の初期化)を追加
			wp_enqueue_script( 'myasp_admin_script', plugins_url( 'admin/js/admin-myasp-term.js', __FILE__ ), [], '1.0.0', true );
		}

		// タグ一覧
		if ( $hook == 'edit-tags.php' ) {
			// 複数選択コンポーネント読み込みテスト(Select2)
			wp_enqueue_style( 'Select2-css', plugins_url( 'admin/css/select2.min.css', __FILE__ ), [], '4.0.13' );
			wp_enqueue_script( 'Select2-js', plugins_url( 'admin/js/select2.min.js', __FILE__ ), [], '4.0.13', true );

			// tooltip
			wp_enqueue_style( 'style', plugins_url( 'admin/css/admin-myasp.css', __FILE__ ), [], '1.0.0' );
			// jsファイルを追加
			wp_enqueue_script( 'myasp_admin_script', plugins_url( 'admin/js/admin-myasp.js', __FILE__ ), [], '1.0.0', true );
		}

		if ( $hook == 'toplevel_page_' . self::PLUGIN_ID ) {
			// MyASP連携設定
			wp_enqueue_style( 'style', plugins_url( 'admin/css/admin-general-setting.css', __FILE__ ), [], '1.0.0' );
		}
	}

	/**
	 * REST API用 独自エンドポイント登録 
	 * ・MyASP側から設定情報を取得するため
	 * 　⇒「WordPress連携設定」を保存すると、下記情報をログに書き出す
	 */
	function add_custom_endpoint()
	{
		// https://<domain>/wp-json/myasp/v1/tags
		register_rest_route(
			//ネームスペース
			'myasp/v1',
			//ベースURL
			'/tags',
			//オプション
			[
				'methods' => WP_REST_Server::READABLE,
				'permission_callback' => function () {
					return true;
				},
				'callback' => [ $this, 'rest_get_tags' ],
			]
		);
	}

	/**
	 * タグと紐づいた投稿を返すREST
	 * (エンドポイントは/wp-json/myasp/v1/tags)
	 * ・MyASP連携が有効になったタグの情報
	 * ・タグを紐づけた記事の一覧（本文は500文字まで出力する）
	 * ・APIトークンで認証
	 */
	function rest_get_tags()
	{
		// 本文の長さ
		$content_length = 500;

		if ( ! $this->validate_api_token( $_SERVER['HTTP_MYASP_WP_API_TOKEN'] ) ) {
			// 検証に失敗した場合はなにも返さない
			return '';
		}
		$api_token = $_SERVER['HTTP_MYASP_WP_API_TOKEN'];

		$common_api_token = get_option( self::PLUGIN_DB_PREFIX . "common_api_token" );
		if ( $api_token != $common_api_token ) {
			// API_TOKENが一致しない場合はなにも返さない
			return '';
		}

		$tagList = get_tags();
		$tagData = [];
		foreach ( $tagList as $tag ) {
			$term_meta = get_option( $tag->taxonomy . '_' . $tag->term_id );
			$myasp_managed_post = '';
			$myasp_open_status = '';
			$allowed_items = [];
			$myasp_auto_redirect = '';
			$myasp_login_url = '';

			if ( $term_meta ) {
				$myasp_managed_post = $term_meta['myasp_managed_post'];
				$myasp_open_status = $term_meta['myasp_open_status'];
				$myasp_auto_redirect = $term_meta['myasp_auto_redirect'];
				$myasp_login_url = $term_meta['myasp_login_url'];

				foreach ( $term_meta['myasp_allowed_item'] as $item ) {
					$allowed_items[] = $item;
				}
			}

			if ( $myasp_managed_post == '' ) {
				// MyASP連携が有効なタグのみを出力する
				continue;
			}

			// タグに紐づいた投稿を取得
			$posts = [];
			$args = [
				'post_type' => [ 'post', 'page' ],
				'tag' => $tag->slug,
				'order' => 'ASC',
				'orderby' => 'id'
			];
			$tag_posts = get_posts( $args );
			foreach ( $tag_posts as $tag_post ) {
				$posts[] = [
					'id' => $tag_post->ID,
					'post_type' => $tag_post->post_type,
					'post_name' => $tag_post->post_name,
					'post_date' => $tag_post->post_date,
					'post_modified' => $tag_post->post_modified,
					'post_title' => $tag_post->post_title,
					'post_content' => mb_substr( $tag_post->post_content, 0, $content_length ),
				];
			}

			$tagData[] = [
				'taxonomy' => $tag->taxonomy,
				'term_id' => $tag->term_id,
				'name' => $tag->name,
				'myasp_managed_post' => $myasp_managed_post,
				'myasp_open_status' => $myasp_open_status,
				'allowed_items' => $allowed_items,
				'myasp_auto_redirect' => $myasp_auto_redirect,
				'myasp_login_url' => $myasp_login_url,
				'posts' => $posts, // tag設定された投稿
			];
		}

		$response = wp_json_encode( [ 'tags' => $tagData ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		return $response;
	}
} // end of class
