<?php

function h($string)
{
	return htmlspecialchars($string, ENT_QUOTES);
}

// カレンダーに表示する予定の編集
function yotei_wordwrap($string, $n, $break = "\n")
{
	$a = array();
	$b = array();
	$lines = explode("\n", $string);  // 行に分割して配列に入れる
	$lines = array_map('rtrim', $lines); // 各行最後の改行などを取り除く
	$lines = array_filter($lines, 'strlen'); // 文字数が0の行を取り除く
	$lines = array_values($lines); // キーを連番に振りなおす
	foreach ($lines as $line) {
		$len = mb_strlen($line, 'utf-8');  // 文字列の長さを取得
		if ($len > 0) {
			$p = 0;
			while ($p < $len) {
				$t = mb_strimwidth($line, $p, $n, '', 'utf-8');  // $nで指定した幅で文字列を丸める
				$a[] = $t;
				$p += mb_strlen($t, 'utf-8');
			}
		}
		// ↑↑↑ ※utf-8の指定必須
		// (ローカルでは問題なくても本番環境で全角６文字以上が表示されなかったりHTMLの書き出しが途中で止まったりする)
	}
	for ($i = 0; $i < count($a); $i++) {
		$b[$i] = $a[$i];
		if ($i == 2) {  // ３行目以降がある場合は「……」に置き換える
			$b[2] = '……';
			break;
		}
	}
	return implode($break, $b);  // 「\n」をはさんで配列要素を連結する
}

try {
	// $pdo = new PDO("mysql:host=localhost; dbname=phpcalender", "maro", "aaa");
	$pdo = new PDO("mysql:host=localhost; dbname=DBzd2B11", "zd2B11", "5GRTVW");
	$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
	$pdo->exec("set names utf8mb4");

	if ($_SERVER["REQUEST_METHOD"] === "POST") {
		// POST送信の場合
		$y = $_POST['y'];
		$m = $_POST['m'];

		//予定更新モーダルの「保存する」が押されたとき
		if (isset($_POST["modal-hozon"])) {
			$yotei_text = $_POST["modal-text"];
			$yotei_ymd  = $_POST["modal-ymd-hidden"];

			$sql = "select * from schedule where date = ?";
			$stmt = $pdo->prepare($sql);
			$stmt->execute(array($yotei_ymd));
			$yotei_data = $stmt->fetch(PDO::FETCH_ASSOC);

			if ($yotei_data == true) {  // DBにデータがある場合
				if (isset($yotei_text)) {  // 予定が入力されていれば、「更新」 ※条件が「if ($yotei_text)」だと0のときfalse扱いになる
					$sql = "update schedule set yotei = ? where date = ?";
					$stmt = $pdo->prepare($sql);
					$stmt->execute(array($yotei_text, $yotei_ymd));
				}
				else {  // 予定が入力されていなければ、「削除」
					$sql = "delete from schedule where date = ?";
					$stmt = $pdo->prepare($sql);
					$stmt->execute(array($yotei_ymd));
				}
			}
			else {  // DBにデータがない場合
				if (isset($yotei_text)) {  // 予定が入力されていれば、「追加」
					$sql = "insert into schedule (date, yotei, created) values (?, ?, now())";
					$stmt = $pdo->prepare($sql);
					$stmt->execute(array($yotei_ymd, $yotei_text));
				}
			}
		}
	}
	else {
		// GET送信の場合
		$y = date("Y");
		$m = date("n");
	}

	//カレンダーデータ用変数初期化
	$data = array();

	//カレンダー開始日のユリウス積算日
	$startD = gregoriantojd($m, 1, $y);
	$start = cal_from_jd($startD, CAL_GREGORIAN);

	//開始日に1日ずつ加算していって、1ヶ月分（月が同じ範囲）の日付の一覧を作る
	$jd = $startD;
	$d = $start;
	do {
		//ループし続ける限り格納し続ける
		$data[] = $d;
		//次の日は？
		++$jd;
		$d = cal_from_jd($jd, CAL_GREGORIAN);
		//次の日が翌月になったらループを抜ける
	} while ($d['month'] == $start['month']);

	// 配列ポインタを先頭に
	$d = reset($data);

	// 前月の日付を追加
	$jd2 = $startD;
	for ($i = $d['dow']; $i > 0; --$i) {
		--$jd2;
		$d2 = cal_from_jd($jd2, CAL_GREGORIAN);
		array_unshift($data, $d2);  //array_unshift()は配列の最初に要素を追加
	}

	// 来月の日付を追加
	$d = end($data); //配列の最後の値を取得する
	$endD = gregoriantojd($m, $d['day'], $y);
	for ($i = $d['dow']; $i < 6; ++$i) {
		$endD = $endD + 1;
		$d3 = cal_from_jd($endD, CAL_GREGORIAN);
		array_push($data, $d3);  //array_push()は配列の最後に要素を追加
	}

	$d = reset($data); // カレンダーの始まる日を取得する
	$cal_start = $d['year'] . '-' . sprintf('%02d', $d['month']) . '-' . sprintf('%02d', $d['day']);

	$d = end($data);  // カレンダーの終わる日を取得する
	$cal_end = $d['year'] . '-' . sprintf('%02d', $d['month']) . '-' . sprintf('%02d', $d['day']);

// DBからカレンダー表示分の予定を取得する
	$sql = 'select * from schedule WHERE date >= "' . $cal_start . '" and date <= "' . $cal_end . '"';
	$stmt = $pdo->query($sql);
	$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

	//$dataを7日ごとに割って、週ごとに格納された配列にする
	$data = array_chunk($data, 7);

	// セレクトボックス(年)の表示範囲
	$this_year = date("Y");
	$y_oldest = $this_year - 10;  // 過去10年
	$y_latest = $this_year + 5;   // 未来5年

}
catch (PDOException $e){
	echo $e->getMessage();
	exit;
}

?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta NAME="ROBOTS" CONTENT="NOINDEX,NOFOLLOW,NOARCHIVE">
<title>PHPでカレンダー</title>
<link href="https://fonts.googleapis.com/earlyaccess/roundedmplus1c.css" rel="stylesheet" />
<link rel="stylesheet" href="css/sanitize.css">
<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
<script>
$(function(){

	var agent = navigator.userAgent;
	// スマホの判別
	if(agent.search(/iPhone/) != -1 || agent.search(/iPod/) != -1 || agent.search(/Android/) != -1){
		$("body").addClass('smartphone');
	}
	else {
		$("body").removeClass('smartphone');
	}

	// モーダルウィンドウが開くときの処理
	$(".modalOpen").click(function(){
		var yoteiYMD = "",
			yoteiText = "",
			href = "";

		yoteiYMD = $(this).find('.yotei-area > p').attr("id"),  // クリックされた日付を取得
		yoteiText = $(this).find("#yotei-text-hidden").val(),  // クリックされた日付の予定内容を取得
		href = $(this).attr("href");  // モーダルウィンドウの場所

		$(href).fadeIn();
		$(this).addClass("open");
		$("#modal-ymd").text(yoteiYMD);
		$("#modal-text").text(yoteiText);
		$("#modal-ymd-hidden").val(yoteiYMD);
		return false;
	});

	// モーダルウィンドウが閉じるときの処理
	$(".modalClose").click(function(){
		$(this).parents(".modal").fadeOut();
		$(".modalOpen").removeClass("open");

		// 文字を入力して「カレンダーへ戻る」を押したとき、テキストエリアに入力内容が残りっぱなしになるのを防ぐ
		$('#form1').submit();

		// .modalCloseのa要素の機能を無効にする
		// (現在の年月以外で「カレンダーへ戻る」を押したとき、現在の年月に戻ってしまうのを防ぐ)
		return false;
	});

	$('#modal-hozon').click(function() {
		$(this).parents(".modal").fadeOut();
		$(".modalOpen").removeClass("open");
		// $('#form1').submit();
		$('#form1').append($('<input type="hidden" name="modal-hozon" value="保存する">')).submit();  // IE11・iphone対策
		 // return false;  //これがあるとsubmitのname属性が送れない
	});

});
</script>

<style>
/*=== PC用デザイン =========================================================*/

html {
	height: 100%;  /* 背景をページの下部まで伸ばす */
}

body {
	height: 100%;  /* 背景をページの下部まで伸ばす */
	font-family:  "Rounded Mplus 1c";
}

.haikei {
	min-width: 1040px;  /* 横スクロールしたときに背景右が途切れる不具合対策 */
	min-height: 100%;  /* 背景をページの下部まで伸ばす */
	background: url("images/<?php echo $m; ?>.png") 0 0 repeat;
	animation: test01 20s linear infinite;
	transform: translate3d(0,0,0);  /* アニメーションの動作対策 */
}
@keyframes test01 {
 0% {
	background-position: 0 0;
	}
 100% {
	background-position: 300px 300px;
	}
}

#form1 {
	width: 1040px;
	margin: 0 auto;
	padding-bottom: 40px;
}

.header {
	display: flex;
	justify-content: space-between;
	align-items: center;
	width: 800px;
	margin: 0 auto;
	padding: 25px 0 15px 0;
}
.header h1 {
	width: 300px;
	margin: 0;
	font-size: 44px;
	text-shadow: 3px 3px 0 #fff, -3px 3px 0 #fff,
			3px -3px 0 #fff, -3px -3px 0 #fff,
			0px 3px 0 #fff, -3px 0px 0 #fff,
			0px -3px 0 #fff, 3px 0px 0 #fff;
}

select {
	border: 1px solid #000000;
}
.select-y-m {
	width: 300px;
	font-size: 22px;
	text-shadow: 1px 1px 0 #fff, -1px 1px 0 #fff,
			1px -1px 0 #fff, -1px -1px 0 #fff,
			0px 1px 0 #fff, -1px 0px 0 #fff,
			0px -1px 0 #fff, 1px 0px 0 #fff;
}
.select-y {
	width: 100px;
	height: auto;
	font-size: 22px;
}
.select-m {
	width: 70px;
	height: auto;
	margin-left: 20px;
	font-size: 22px;
}

table {
	width: 1000px;
	margin: 0 20px;
	table-layout: fixed;
	border-collapse: collapse;
	background-color: rgba(255, 255 ,255, 0.9);
	font-size: 36px;
}

th{
	border: 1px solid #000;
	background-color: rgba(0, 0 ,0, 0.1);
	font-size: 24px;
}
td {
	height: 120px;
	border: 1px solid #000;
	text-align: right;
}
td a {
	display: block;
	width: 100%;
	height: 100%;
	padding: 0 5px;
	text-decoration: none;
}

.yotei-area-mobile {
	display: none;
}

.yotei-area p {
	overflow: hidden;
	height: 60px;
	margin: 0;
	text-align: left;
	font-size: 12px;
	color: #333;
	word-break: break-all;
}

.today {
	background-color: rgba(255, 255, 0, 0.3);
}
.pink {
	color: #ff007d;
}
.light-pink {
	color: #ffb2db;
}
.blue {
	color: #00b4ff;
}
.light-blue {
	color: #a3e4ff;
}
.black {
	color: #000000;
}
.gray {
	color: #aaaaaa;
}

/* モーダルウィンドウのスタイル */
.modal {
	display: none;
	position: absolute;
	top: 0;
	bottom: 0;
	left: 0;
	right: 0;
}

/* オーバーレイのスタイル */
.overLay {
	z-index: 10;
	position: fixed;
	top: 0;
	bottom: 0;
	left: 0;
	right: 0;
	background: rgba(200,200,200,0.8);
}

/* モーダルウィンドウの中身のスタイル */
.modal .inner {
	z-index: 11;
	position: absolute;
	top: 50%;
	left: 50%;
	transform: translate(-50%,-50%);
}

#modal-ymd {
	margin: 0 auto 10px auto;
	text-align: center;
	font-size: 40px;
	font-weight: bold;
	text-shadow: 2px 2px 0 #fff, -2px 2px 0 #fff,
			2px -2px 0 #fff, -2px -2px 0 #fff,
			0px 2px 0 #fff, -2px 0px 0 #fff,
			0px -2px 0 #fff, 2px 0px 0 #fff;
}

#modal-text {
	display: block;
	width: 220px;
	height: 300px;
	margin: 0 auto;
	font-size: 20px;
}

.button-area {
	display: flex;
	justify-content: space-around;
	width: 270px;
	margin-top: 30px;
}

#modal-hozon, #modal-modoru {
	padding: 5px 12px;
	border: 1px solid #000;
	border-radius: 2px;
	outline: none;
	background-color: rgba(255,255,255,0.8);
	cursor: pointer;
}
#modal-modoru a {
	display: block;
	width: 100%;
	height: 100%;
	text-decoration: none;
	color: #000;
}

/*=== モバイル用デザイン =========================================================*/
@media screen and (max-width: 767px){
	.haikei {
		min-width: 100%;
	}
	#form1 {
		width: 100%;
		min-width: 300px;
	}
	.header {
		flex-wrap: wrap;
		width: 100%;
		padding: 4vh 0 4vh 0;
	}
	.header h1 {
		margin: 0 auto 2vh auto;
		text-align: center;
		font-size: 2.2em;
	}

	.select-y-m {
		margin: 0 auto;
		text-align: center;
		font-size: 1.1em;
	}
	.select-y {
		height: auto;
		font-size: 1em;
	}
	.select-m {
		height: auto;
		font-size: 1em;
		margin-left: 15px;
	}

	table {
		width: 90%;
		margin: 0 auto;
	}
	th {
		font-size: 4vw;
	}
	td {
		height: auto;
	}
	td a {
		padding: 0 1vw;
		font-size: 5vw;
	}

	.yotei-area {
		display: none;
	}
	.yotei-area-mobile {
		display: block;
		position: relative;
		height: 6vw;
		text-align: center;
	}
	.yotei-area-mobile p {
		position: absolute;
		top: -1vw;
		left: 0.5vw;
		width: 90%;
		height: 6vw;
		margin: 0 auto;
		font-size: 5vw;
		color: #f00;
	}

	#modal-ymd {
		font-size: 2em;
		text-align: center;
	}
}

/* === モバイル用(横向き)デザイン ==================================== */
@media screen and (max-width: 767px) and (orientation: landscape) {
	.smartphone .header {
		justify-content: space-around;
		width: 90%;
		padding: 2vmin 0 1vmin 0;
	}
	.smartphone .header h1 {
		width: initial;
		margin: 0;
		font-size: 7vmin;
	}
	.smartphone .select-y-m {
		width: initial;
		margin: 0;
	}
	.smartphone .select-y {
		font-size: 4vmin;
		width: 14vw;
	}
	.smartphone .select-m {
		font-size: 4vmin;
		width: 10vw;
	}
	.smartphone th {
		font-size: 3vmin;
	}
	.smartphone td a {
		padding: 0 1vmin;
		font-size: 5vmin;
	}
	.smartphone .yotei-area-mobile {
		position: relative;
		height: 3vmin;
	}
	.smartphone .yotei-area-mobile p {
		position: absolute;
		top: -3.5vmin;
		left: 1vmin;
		height: 3vmin;
		font-size: 5vmin;
	}
	.smartphone #modal-text {
		width: 300px;
		height: 100px;
	}
	.smartphone .button-area {
		width: 300px;
		margin-top: 20px;
	}
}


</style>

</head>
<body>
	<div class="haikei">
		<form action="" method="post" id="form1">
			<div class="header">
				<h1><?php echo $y . '年 ' . $m . '月'; ?></h1>
				<p class="select-y-m">
					<select name="y" onchange="this.form.submit()" class="select-y">
					<?php for ($i = $y_latest; $i >= $y_oldest ; $i--) : ?>
						<option value="<?php echo "$i"; ?>" <?php if ($y == $i) echo "selected"; ?>><?php echo "$i"; ?></option>
					<?php endfor; ?>
					</select> 年
					<select name="m" onchange="this.form.submit()" class="select-m">
					<?php for ($j = 1 ; $j <= 12 ; $j++) : ?>
						<option value="<?php echo "$j"; ?>" <?php if ($m == $j) echo "selected"; ?>><?php echo "$j"; ?></option>
					<?php endfor; ?>
					</select> 月
				</p>
			</div>

			<?php
					//カレンダーを表示
					echo '<table>';
					echo '<tr><th class="pink">日</th><th>月</th><th>火</th><th>水</th><th>木</th><th>金</th><th class="blue">土</th></tr>';
					//週ごとのループ
					foreach ($data as $w) {
						echo '<tr>';
						//（週の中の）日ごとのループ
						foreach ($w as $d) {

								// 日曜は赤系の文字
								if ($d['dow'] == 0) {
									if ($d['month'] == $start['month']) {
										$date_color = 'pink';  // 表示月
									}
									else {
										$date_color = 'light-pink';  // 他の月
									}
								}
								// 土曜は青系の文字
								elseif ($d['dow'] == 6) {
									if ($d['month'] == $start['month']) {
										$date_color = 'blue';  // 表示月
									}
									else {
										$date_color = 'light-blue';  // 他の月
									}
								}
								// 平日は黒系の文字
								else {
									if ($d['month'] == $start['month']) {
										$date_color = 'black';  // 表示月
									}
									else {
										$date_color = 'gray';  // 他の月
									}
								}

								if ($d["date"] == date("n/j/Y")) {
									$class_today = ' class="today"';
								}
								else {
									$class_today = '';
								}
								$ymd = $d["year"] . '-' . sprintf('%02d', $d["month"]) . '-' . sprintf('%02d', $d["day"]);
								echo '<td' . $class_today . '><a href="#modal01" class="modalOpen ' . $date_color . '">' . $d['day'];

								$text_hyoji = "";
								$text_original = "";

								if (items) {
									foreach ($items as $item) {
										if ($item[date] == $ymd) {
											$text_original = $item[yotei];
											// 空の行を削除・長い文字列は分割して改行・３行目以降は「……」に置き換え
											$text_hyoji = yotei_wordwrap($item[yotei], 16);
										}
									}
								}
								echo '<div class="yotei-area"><p id="' . $ymd . '">' . nl2br(h($text_hyoji)) . '</p></div>';

								$text_mobile = "";
								if ($text_original) {
									$text_mobile = "★";
								}
								echo '<div class="yotei-area-mobile"><p id="' . $ymd . '">' . $text_mobile . '</p></div>';
								echo '<input type="hidden" id="yotei-text-hidden" name="yotei-text-hidden" value="' . h($text_original) . '">';
								echo '</a></td>';
						}
						echo '</tr>';
					}
					echo '</table>';

			?>
			<!-- モーダルウィンドウ -->
			<div class="modal" id="modal01">
				<!-- モーダルウィンドウが開いている時のオーバーレイ -->
				<div class="overLay"></div>
				<!-- モーダルウィンドウの中身 -->
				<div class="inner">
					<p id="modal-ymd"></p>
					<input type="hidden" id="modal-ymd-hidden" name="modal-ymd-hidden" value="">
					<textarea id="modal-text" name="modal-text"></textarea>
					<div class="button-area">
						<input type="submit" name="modal-hozon" id="modal-hozon" value="保存する">
						<button type="button" class="modalClose" id="modal-modoru"><a href="">ｶﾚﾝﾀﾞｰへ戻る</a></button>
					</div>
				</div>
			</div>
		</form>
		</div>
	</body>
</html>